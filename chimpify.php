<?php
/**
 * Plugin Name: Chimpify Migration API
 * Description: Migrate your WordPress data to Chimpify or your Chimpify data to WordPress. Posts, comments, users & media files.
 * Author:      Chimpify Team
 * Author URI:  http://chimpify.de/
 * License:     GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Version:     1.0.4
 */

define( 'CHIMP_PLUGIN_VERSION', '1.0.4' );

/**
 * Add our rewrites to wordpress
 *
 * @return void
 */
function chimpify_rewrites()
{
    add_rewrite_rule( '^chimpify-api/?$','index.php?chimpify_route=/', 'top' );
    add_rewrite_rule( '^chimpify-api/(.*)?','index.php?chimpify_route=/$matches[1]', 'top' );
}

/**
 * Called at activation of the plugin in admin gui
 *
 * @return void
 */
function chimpify_activation()
{
    chimpify_rewrites();
    chimpify_regenerate_apikey();
    flush_rewrite_rules();
}

/**
 * Called at deactivation of the plugin in admin gui
 *
 * @return void
 */
function chimpify_deactivation()
{
    delete_option( 'chimpify_apikey' );
    flush_rewrite_rules();
}

/**
 * Initialize
 *
 * @return void
 */
function chimpify_init()
{
    chimpify_rewrites();
        
    global $wp;
    $wp->add_query_var( 'chimpify_route' );
}

/**
 * (Re)generates a new API Key for Chimpify and write it to database
 *
 * @return void
 */
function chimpify_regenerate_apikey()
{
    $apikey = implode("-", str_split(substr(str_shuffle("abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890"), 0, 28), 7));
    update_option( 'chimpify_apikey', $apikey );
}

/**
 * Get Chimpify API Key
 *
 * @return String
 */
function chimpify_get_apikey()
{
    return get_option( 'chimpify_apikey' );
}

/**
 * Add actions and hooks
 */
add_action( 'init', 'chimpify_init', 11 ); // Prioritized over core rewrites
register_activation_hook( __FILE__, 'chimpify_activation' );
register_deactivation_hook( __FILE__, 'chimpify_deactivation' );


/**
 * Flush rewrite rules if needed.
 *
 * @return void
 */
function chimpify_flush_rewrite_rules()
{
    $version = get_option( 'chimpify_plugin_version', null );

    if($version !== CHIMP_PLUGIN_VERSION)
    {
        flush_rewrite_rules();
        update_option( 'chimpify_plugin_version', CHIMP_PLUGIN_VERSION );
    }

}
add_action( 'init', 'chimpify_flush_rewrite_rules', 900 );


/**
 * X-Headers for extra info
 *
 * @return void
 */
function chimpify_headers($count, $pages)
{
    header("X-Chimpify-Pages: {$pages}", true);
    header("X-Chimpify-Count: {$count}", true);
}

/**
 * Outputs our API results in JSON format
 *
 * @param array $results
 * @return void
 */
function chimpify_json($results)
{
    header("Content-type: application/json", true);
    echo json_encode($results);
    die();
}

/**
 * Outputs our API results in JSON format
 *
 * @param array $results
 * @return void
 */
function chimpify_import($action)
{
    $errors = array();

    if($action == 'post')
    {
        $raw_post_data = file_get_contents("php://input");
        $inbound_data = json_decode($raw_post_data);
    
        if(json_last_error() != JSON_ERROR_NONE)
        {
            $protocol = (isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0');
            header($protocol . ' 400 Bad Request', true);
            die();
        }

        if(isset($inbound_data->post) && is_object($inbound_data->post))
        {
            $user_map = array();

            $post = $inbound_data->post;

            $wp_post = array(
                "post_date"     => $post->post_date,
                'post_content'  => $post->post_content,
                'post_title'    => $post->post_title,
                'post_status'   => $post->post_status,
                'post_type'     => $post->post_type,
                'post_name'     => $post->post_name,
                'post_modified' => $post->post_modified,
                'meta_input'    => $post->meta_input,
            );

            if($post->post_author_id && !empty($post->post_author_id))
            {
                $wp_user_id = $post->post_author_id;
                $wp_post['post_author'] = $wp_user_id;
            }
            elseif($post->post_author && !empty($post->post_author) && !empty($post->post_author->fullname) && !empty($post->post_author->email))
            {
                $user_id = md5($post->post_author->email.$post->post_author->fullname);
            
                if(isset($user_map[$user_id]))
                {
                    $wp_user_id = $user_map[$user_id];
                }
                else
                {
                    $wp_user = array(
                        'user_login' => $post->post_author->fullname,
                        'user_pass' => md5(uniqid(mt_rand(), true)),
                        'user_email' => $post->post_author->email,
                        'display_name' => $post->post_author->fullname,
                        'first_name' => $post->post_author->firstname,
                        'last_name' => $post->post_author->name,
                        'description' => $post->post_author->description,
                        'role' => $post->post_author->role,
                        'facebook' => $post->post_author->facebook,
                        'twitter' => $post->post_author->twitter,
                        'googleplus' => $post->post_author->googleplus,
                    );
                
                    $wp_user_id = wp_insert_user( $wp_user ) ;
                    
                    if(is_wp_error($wp_user_id))
                    {
                        $errors[] = $wp_user_id->get_error_message();
                    }
                    
                    if($wp_user_id)
                    {
                        $user_map[$user_id] = $wp_user_id;
                    }
                }
            
                if($wp_user_id)
                {
                    $wp_post['post_author'] = $wp_user_id;
                }
            }

            // Tags
            if($post->tags && is_array($post->tags) && !empty($post->tags))
            {
                $wp_post['tags_input'] = implode(",", $post->tags);
            }

            $post_id = wp_insert_post( $wp_post );
            
            if(is_wp_error($post_id))
            {
                $errors[] = $post_id->get_error_message();
            }
        
            chimpify_json(array("status" => "ok", "id" => $post_id, "user_id" => $wp_user_id, "errors" => $errors));
            die();
        }
    }
    elseif($action == 'comment')
    {
        $raw_post_data = file_get_contents("php://input");
        $inbound_data = json_decode($raw_post_data);
    
        if(json_last_error() != JSON_ERROR_NONE)
        {
            $protocol = (isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0');
            header($protocol . ' 400 Bad Request', true);
            die();
        }

        if(isset($inbound_data->comment) && is_object($inbound_data->comment))
        {
            $comment = $inbound_data->comment;

            $comment_data = array(
                'comment_post_ID' => $comment->post_id,
                'comment_author' => $comment->author_name,
                'comment_author_email' => $comment->author_email,
                'comment_author_url' => $comment->author_url,
                'comment_content' => $comment->comment,
                'comment_type' => $comment->type,
                'comment_parent' => $comment->parent,
                'comment_date' => $comment->datetime,
                'comment_approved' => $comment->approved,
            );

            $comment_id = wp_insert_comment($comment_data);
            
            if(is_wp_error($comment_id))
            {
                $errors[] = $comment_id->get_error_message();
            }

            chimpify_json(array("status" => "ok", "id" => $comment_id, "errors" => $errors));
            die();
        }
    }
    elseif($action == 'attachment')
    {

        $inbound_data = json_decode(json_encode($_POST), FALSE);
        $file = (isset($_FILES) && isset($_FILES[0])) ? $_FILES[0] : null;

        if($file !== null && isset($inbound_data) && is_object($inbound_data) && !empty($inbound_data->path))
        {
            $attachment = $inbound_data;

            $wp_upload_dir = wp_upload_dir();
            $wp_basedir = $wp_upload_dir['basedir'];
            $filename = rtrim($wp_basedir, '/') . '/' . ltrim($attachment->path, '/');

            if(!is_dir( dirname($filename) ))
            {
                mkdir( dirname($filename), 0755, true );
            }

            $tmp_name = $file['tmp_name'];

            if(move_uploaded_file($tmp_name, $filename))
            {

                @unlink($tmp_name);

                $parent_post_id = null;
                $filetype = wp_check_filetype( basename( $filename ), null );
                $wp_upload_dir = wp_upload_dir();

                $wp_attachment = array(
                    'guid'           => $wp_upload_dir['url'] . '/' . basename( $filename ), 
                    'post_mime_type' => $filetype['type'],
                    'post_title'     => preg_replace( '/\.[^.]+$/', '', basename( $filename ) ),
                    'post_content'   => '',
                    'post_status'    => 'inherit'
                );

                // Insert the attachment.
                $attach_id = wp_insert_attachment( $wp_attachment, $filename, $parent_post_id );
            
                if(is_wp_error($attach_id))
                {
                    $errors[] = $attach_id->get_error_message();
                }
                else
                {
                    require_once( ABSPATH . 'wp-admin/includes/image.php' );
                    require_once( ABSPATH . 'wp-admin/includes/media.php' );

                    $attach_data = wp_generate_attachment_metadata( $attach_id, $filename );
                    wp_update_attachment_metadata( $attach_id, $attach_data );

                    // Set Post Images
                    $wp_query = new WP_Query( array(
                        'meta_query' => array(
                            array(
                                'key'     => 'chimpify_post_image',
                                'value'   => $attachment->path,
                            ),
                        ),
                        'fields' => 'ID'
                    ) );

                    $posts_with_image = $wp_query->get_posts();
                    foreach ($posts_with_image as $post)
                    {
                        set_post_thumbnail( $post->ID, $attach_id );
                    }

                    // Replace paths
                    $wp_query = new WP_Query('s='.$attachment->url . '&fields=ID');

                    $posts = $wp_query->get_posts();
                    foreach ($posts as $post)
                    {
                        $search = '/' . preg_quote($attachment->url, '/') . '/mi';
                        $replace = wp_get_attachment_url($attach_id);
                        $post->post_content = preg_replace($search, $replace, $post->post_content);
                
                        wp_update_post( $post );
                
                        wp_update_post( array(
                            'ID'            => $attach_id,
                            'post_parent'   => $post->ID,
                        ) );
                    }

                    $wp_query = new WP_Query('s='.$attachment->path_absolute . '&fields=ID');

                    $posts = $wp_query->get_posts();
                    foreach ($posts as $post)
                    {
                        $search = '/' . preg_quote($attachment->path_absolute, '/') . '/mi';
                        $replace = wp_get_attachment_url($attach_id);
                        $post->post_content = preg_replace($search, $replace, $post->post_content);
                
                        wp_update_post( $post );
                
                        wp_update_post( array(
                            'ID'            => $attach_id,
                            'post_parent'   => $post->ID,
                        ) );
                    }

                }

            }

        }
    }
    
    chimpify_json(array("status" => "ok", "errors" => $errors));
    die();
}

/**
 * Core function with API methods
 *
 * @return void
 */
function chimpify_ready()
{
    global $wp;

    if(!isset($GLOBALS['wp']->query_vars['chimpify_route']))
    {
        return;
    }
    
    $apikey = chimpify_get_apikey();
    
    if(!$_GET['api_key'] || !$apikey || $_GET['api_key'] != $apikey)
    {
        chimpify_json(array("error" => "Access denied"));
        die();
    }
    
    $action = trim($GLOBALS['wp']->query_vars['chimpify_route'], '/');
    /******************************************************************
     * Import
     ******************************************************************/
    if(preg_match('/^import\/(.*)$/', $action, $matches))
    {
        return chimpify_import($matches[1]);
    }

    /******************************************************************
     * Posts
     ******************************************************************/
    elseif($action == 'posts')
    {
        $page = (int)$GLOBALS['wp']->query_vars['page'];
        ($page == 0 && $page=1);
        
        $query = array(
            'post_status' => 'publish',
            'post_type' => array('post', 'page'),
            'posts_per_page' => 10,
            'paged' => $page
        );

        $wp_query = new WP_Query();
        $posts = $wp_query->query($query);

        chimpify_headers($wp_query->found_posts, $wp_query->max_num_pages);
        
        $results = array();
        
        foreach ($posts as $post)
        {
            if($post->post_status != 'publish')
            {
                continue;
            }

            $wp_user = get_userdata( (int) $post->post_author );
            
            $author = null;
            
            if($wp_user)
            {
                $author = array(
                    'ID' => $wp_user->ID,
                    'login' => $wp_user->user_login,
                    'last_name' => $wp_user->last_name,
                    'first_name' => $wp_user->first_name,
                    'display_name' => $wp_user->display_name,
                    'email' => $wp_user->user_email,
                    'description' => $wp_user->description,
                    'roles' => implode(', ', $wp_user->roles),
                );
            }
            
            $post_image = null;
            
            $post_image_id = get_post_thumbnail_id( $post->ID );
            
            if($post_image_id)
            {
                $attachment_metadata = wp_get_attachment_metadata( $post_image_id, true );
                $attachment_url = wp_get_attachment_url( $post_image_id );
                
                $post_image = array(
                    'ID' => $post_image_id,
                    'source' => $attachment_url,
                    'meta' => $attachment_metadata
                );
            }
            
            $post_categories = wp_get_post_categories( $post->ID );
            
            $categories = array();
            foreach($post_categories as $post_category_id)
            {
                $post_category = get_category( $post_category_id );
                
                $categories[] = array(
                    'name' => $post_category->name,
                    'slug' => $post_category->slug,
                );
            }
            
            $post_tags = wp_get_post_tags($post->ID);
            
            $tags = array();
            foreach($post_tags as $post_tag)
            {
                $tags[] = array(
                    'name' => $post_tag->name,
                    'slug' => $post_tag->slug,
                );
            }
            
            $data = array(
                'ID'              => $post->ID,
                'title'           => get_the_title( $post->ID ), // $post->post_title'],
                'status'          => $post->post_status,
                'type'            => $post->post_type,
                'date'            => $post->post_date,
                'modified'        => $post->post_modified,
                'author'          => $author,
                'content'         => apply_filters( 'the_content', $post->post_content ),
                'parent'          => (int) $post->post_parent,
                'link'            => get_permalink( $post->ID ),
                'slug'            => $post->post_name,
                'guid'            => apply_filters( 'get_the_guid', $post->guid ),
                'excerpt'         => $post->post_excerpt,
                'comment_status'  => $post->comment_status,
                'ping_status'     => $post->ping_status,
                'post_image'      => $post_image,
                'categories'      => $categories,
                'tags'            => $tags,
             );
             
            // Import keyword from bananacontent
            $postmeta = get_post_meta($post->ID, 'banana_content', true);
            if($postmeta && is_array($postmeta) && isset($postmeta['keyword']) && !empty($postmeta['keyword']))
            {
                $data['keyword'] = $postmeta['keyword'];
            }
            
            // Import keyword from Yoast
            if(!isset($data['keyword']) && !$data['keyword'])
            {
                $yoast_focuskw = get_post_meta( $post->ID, '_yoast_wpseo_focuskw', true );
                if($yoast_focuskw)
                {
                    $data['keyword'] = $yoast_focuskw;
                }
            }

            // Import meta from Yoast
            $yoast_seo_title = get_post_meta( $post->ID, '_yoast_wpseo_title', true );
            if($yoast_seo_title)
            {
                $data['meta_title'] = $yoast_seo_title;
            }
            $yoast_seo_description = get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true );
            if($yoast_seo_description)
            {
                $data['meta_description'] = $yoast_seo_description;
            }


            // Import meta from All In One SEO
            $aios_seo_title = get_post_meta( $post->ID, '_aioseop_title', true );
            if(!$data['meta_title'] && $aios_seo_title)
            {
                $data['meta_title'] = $aios_seo_title;
            }
            $aios_seo_description = get_post_meta( $post->ID, '_aioseop_description', true );
            if(!$data['meta_description'] && $aios_seo_description)
            {
                $data['meta_description'] = $aios_seo_description;
            }

            // Import meta from wpSEO
            $wpseo_seo_title = get_post_meta( $post->ID, '_wpseo_edit_title', true );
            if(!$data['meta_title'] && $wpseo_seo_title)
            {
                $data['meta_title'] = $wpseo_seo_title;
            }
            
            $wpseo_seo_description = get_post_meta( $post->ID, '_wpseo_edit_description', true );
            if(!$data['meta_description'] && $wpseo_seo_description)
            {
                $data['meta_description'] = $wpseo_seo_description;
            }

            $results[] = $data;
        }
        
        chimpify_json($results);
        
    }
    /******************************************************************
     * Users
     ******************************************************************/
    elseif($action == 'users')
    {
        //WP_User_Query
        $page = (int)$GLOBALS['wp']->query_vars['page'];
        ($page == 0 && $page=1);
        
        $query = array(
            'number' => 50,
            'paged' => $page
        );

        $user_query = new WP_User_Query($query);

        chimpify_headers($user_query->total_users, ceil($user_query->total_users/$query['number']));

        $results = array();
        
        foreach ($user_query->results as $wp_user)
        {
            $results[] = array(
                'ID' => $wp_user->ID,
                'login' => $wp_user->user_login,
                'last_name' => $wp_user->last_name,
                'first_name' => $wp_user->first_name,
                'display_name' => $wp_user->display_name,
                'email' => $wp_user->user_email,
                'description' => $wp_user->description,
                'roles' => implode(', ', $wp_user->roles),
            );
        }
        
        chimpify_json($results);
    }
    /******************************************************************
     * Comments
     ******************************************************************/
    elseif($action == 'comments')
    {
        $number = 50;
        
        $page = (int)$GLOBALS['wp']->query_vars['page'];
        ($page == 0 && $page=1);
        
        $offset = ( $page - 1 ) * $number;
        
        $query = array(
            'number' => $number,
            'offset' => $offset,
            'paged' => $page,
            'orderby' => 'comment_date_gmt',
            'order' => 'ASC'
        );

        // First, check the total amount of comments
        $comments_query = new WP_Comment_Query;
        $comments = $comments_query->query( array() );

        chimpify_headers(count($comments), ceil(count($comments)/$query['number']));

        $comments_query = new WP_Comment_Query;
        $comments = $comments_query->query( $query );


        $results = array();
        
        foreach ($comments as $comment)
        {
            $results[] = array(
                'ID' => $comment->comment_ID,
                'post_id' => $comment->comment_post_ID,
                'author_name' => $comment->comment_author,
                'author_email' => $comment->comment_author_email,
                'author_url' => $comment->comment_author_url,
                'date' => $comment->comment_date,
                'content' => $comment->comment_content,
                'agent' => $comment->comment_agent,
                'type' => $comment->comment_type,
                'parent' => $comment->comment_parent,
                'user_id' => $comment->user_id,
                'approved' => $comment->comment_approved,
            );
        }
        
        chimpify_json($results);
    }
    /******************************************************************
     * Media
     ******************************************************************/
    elseif($action == 'media')
    {
        $page = (int)$GLOBALS['wp']->query_vars['page'];
        ($page == 0 && $page=1);
        
        $query = array(
            'post_status' => 'inherit',
            'post_type' => 'attachment',
            'posts_per_page' => 50,
            'paged' => $page
        );

        $wp_query = new WP_Query();
        $posts = $wp_query->query($query);

        chimpify_headers($wp_query->found_posts, $wp_query->max_num_pages);
        
        $results = array();
        
        foreach ($posts as $post)
        {
            $post_image = null;
            
            $post_image_id = get_post_thumbnail_id( $post->ID );
            
            $metadata = wp_get_attachment_metadata( $post->ID, true );
            $url = wp_get_attachment_url( $post->ID );
            
            if(isset($metadata['sizes']) && is_array($metadata['sizes']) && !empty($metadata['sizes']))
            {
                foreach($metadata['sizes'] as $name => $size)
                {
                    $metadata['sizes'][$name]['url'] = dirname($url) . "/" . $size['file'];
                }
            }
            
            $wp_user = get_userdata( (int) $post->post_author );
            
            $author = null;
            
            if($wp_user)
            {
                $author = array(
                    'ID' => $wp_user->ID,
                    'login' => $wp_user->user_login,
                    'last_name' => $wp_user->last_name,
                    'first_name' => $wp_user->first_name,
                    'display_name' => $wp_user->display_name,
                    'email' => $wp_user->user_email,
                    'description' => $wp_user->description,
                    'roles' => implode(', ', $wp_user->roles),
                );
            }

            $data = array(
                'title'           => get_the_title( $post->ID ), // $post->post_title'],
                'date'            => $post->post_date,
                'modified'        => $post->post_modified,
                'author'          => $author,
                'source'          => $url,
                'slug'            => $post->post_name,
                'guid'            => apply_filters( 'get_the_guid', $post->guid ),
                'mime_type'       => $post->post_mime_type,
                'meta'            => $metadata,
             );

            $results[] = $data;
        }
        
        chimpify_json($results);
    }
    // Index
    elseif(empty($action))
    {
        $results = array(
            'url' => get_bloginfo('url'),
            'self' => rtrim( get_bloginfo('url'), '/') . '/chimpify-api/',
            'version' => get_bloginfo('version'),
            'charset' => get_bloginfo('charset'),
            'pingback_url' => get_bloginfo('pingback_url'),
            'rss_url' => get_bloginfo('rss_url'),
            'rss2_url' => get_bloginfo('rss2_url'),
            'chimpify_plugin_version' => CHIMP_PLUGIN_VERSION,
        );
        
        chimpify_json($results);
    }
    else
    {
        echo "Not provided.";
        die();
    }
    
}

add_action( 'template_redirect', 'chimpify_ready', -100 );

if( is_admin() )
{
    function chimpify_admin_page()
    {
        if( !current_user_can('administrator') )
        {
            //return;
        }

        $chimpify_apikey  = chimpify_get_apikey();
        $chimpify_api_url = rtrim( get_bloginfo('url'), '/') . '/chimpify-api/';
    
    ?>
            <div class="wrap">
                <h2>Chimpify</h2>
                API-Plugin <?= CHIMP_PLUGIN_VERSION; ?>
                <p>
                    Folgende Daten kannst du für einen neuen Import in Chimpify nutzen:
                </p>
                <table class="form-table">
                    <tr>
                        <td colspan="2">
                            <label for="chimpify_api_url"><strong>API URL:</strong></label><br/>
                            <a href="<?php echo add_query_arg( 'api_key', $chimpify_apikey, $chimpify_api_url ); ?>" target="_blank"><?php echo $chimpify_api_url; ?></a>
                        </td>
                    </tr>
                    <tr>
                        <td colspan="2">
                            <label for="stetic_api_key"><strong>API-Key:</strong></label><br/>
                            <?php echo $chimpify_apikey; ?><br>
                            <a href="<?php echo add_query_arg(
                                array(
                                    'page' => 'chimpify/chimpify.php',
                                    'chimpify_action' => 'reload_apikey',
                                ),
                                admin_url('options-general.php')
                            ); ?>">Neu generieren</a>
                        </td>
                    </tr>
                </table>
                <p>
                    Bitte beachte: Jedes Mal, wenn du einen neuen API-Key erstellst, werden alle vorhergehenden API-Keys ungültig.
                </p>
            </div>
    <?php
    }

    function chimpify_add_admin_menu()
    {
        if ( function_exists('add_options_page') && current_user_can('administrator') )
        {
            add_options_page('chimpify', 'Chimpify', 'manage_options', 'chimpify/chimpify.php', 'chimpify_admin_page');
        }

        if ( function_exists('add_menu_page') && current_user_can('administrator') )
        {
            add_menu_page('chimpify', 'Chimpify', 'read', __FILE__, 'chimpify_admin_page', 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAAGXRFWHRTb2Z0d2FyZQBBZG9iZSBJbWFnZVJlYWR5ccllPAAAAyhpVFh0WE1MOmNvbS5hZG9iZS54bXAAAAAAADw/eHBhY2tldCBiZWdpbj0i77u/IiBpZD0iVzVNME1wQ2VoaUh6cmVTek5UY3prYzlkIj8+IDx4OnhtcG1ldGEgeG1sbnM6eD0iYWRvYmU6bnM6bWV0YS8iIHg6eG1wdGs9IkFkb2JlIFhNUCBDb3JlIDUuNi1jMTExIDc5LjE1ODMyNSwgMjAxNS8wOS8xMC0wMToxMDoyMCAgICAgICAgIj4gPHJkZjpSREYgeG1sbnM6cmRmPSJodHRwOi8vd3d3LnczLm9yZy8xOTk5LzAyLzIyLXJkZi1zeW50YXgtbnMjIj4gPHJkZjpEZXNjcmlwdGlvbiByZGY6YWJvdXQ9IiIgeG1sbnM6eG1wPSJodHRwOi8vbnMuYWRvYmUuY29tL3hhcC8xLjAvIiB4bWxuczp4bXBNTT0iaHR0cDovL25zLmFkb2JlLmNvbS94YXAvMS4wL21tLyIgeG1sbnM6c3RSZWY9Imh0dHA6Ly9ucy5hZG9iZS5jb20veGFwLzEuMC9zVHlwZS9SZXNvdXJjZVJlZiMiIHhtcDpDcmVhdG9yVG9vbD0iQWRvYmUgUGhvdG9zaG9wIENDIDIwMTUgKE1hY2ludG9zaCkiIHhtcE1NOkluc3RhbmNlSUQ9InhtcC5paWQ6RkJBQzVEODcyRjA4MTFFNjlFNzdCQzgyMkVDOTdBRjAiIHhtcE1NOkRvY3VtZW50SUQ9InhtcC5kaWQ6RkJBQzVEODgyRjA4MTFFNjlFNzdCQzgyMkVDOTdBRjAiPiA8eG1wTU06RGVyaXZlZEZyb20gc3RSZWY6aW5zdGFuY2VJRD0ieG1wLmlpZDpGQkFDNUQ4NTJGMDgxMUU2OUU3N0JDODIyRUM5N0FGMCIgc3RSZWY6ZG9jdW1lbnRJRD0ieG1wLmRpZDpGQkFDNUQ4NjJGMDgxMUU2OUU3N0JDODIyRUM5N0FGMCIvPiA8L3JkZjpEZXNjcmlwdGlvbj4gPC9yZGY6UkRGPiA8L3g6eG1wbWV0YT4gPD94cGFja2V0IGVuZD0iciI/PmYHvW0AAAE3SURBVHjapNM7SwNBFIbhzCaaTsUomEIsBDsFqzSCiIWVpLRQ0MYfYeUPEExjIZb2FjaCEFCQtIKFiJWNRDBR0nhH13fgC5wss1HIwAOzmTlnTubi4jjO9NJyKb+tYhCnaGEFfqUj1Dtm+wqMIm7jzvZq+i+YszHJBMfx362BXDsmUrkXmEVRhR2gYgo9wbb6I1hADesZk3kYe+pPoN+MrWlF3+6Qbw/4DdvHPfIoa5UdvJkKNlFSfwzzWMSyM8fYUHn/bQU8R/rYCAR/4hLneAgk2LX3YDowIYtxJRoIjE/ZBFcpCUa7/IVre5H8uW6himaXO/COGioo+FiXeAtLmEFTV7cPDl/41sY94TD0FrIaHNKeRAr60Tyf6BE3OvIPH+RSXmNJt21S3z7wDNXkRNfrc/4VYADqaxw9ohgPfgAAAABJRU5ErkJggg==');
        }
        
    }
    
    function chimpify_add_plugin_action_link($links, $file)
    {
        if($file ==  plugin_basename(__FILE__))
        {
            $settings_link = '<a href="options-general.php?page=chimpify/chimpify.php">' . __('Settings') . '</a>';
            array_push( $links, $settings_link );
        }
        return $links;
    }

    add_filter( 'plugin_action_links', 'chimpify_add_plugin_action_link', 11, 2 );
    
    add_action( 'admin_menu', 'chimpify_add_admin_menu' );
    
    function chimpify_admin_request_changes()
    {
        if(basename(__FILE__) == 'chimpify.php' && $_GET['chimpify_action'] && $_GET['chimpify_action'] == 'reload_apikey')
        {
            chimpify_regenerate_apikey();
            wp_redirect(admin_url('/options-general.php?page=chimpify/chimpify.php'), 301);
            exit;
        }
    }
    
    add_action( 'admin_init', 'chimpify_admin_request_changes' );
    
}
