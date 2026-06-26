<?php
/**
 * Plugin Name: FCM Orbits
 * Plugin URI:  https://intasela.com
 * Description: Video Feed (Orbits)
 * Version:     2.1.7
 * Author:      Matthew John Alex
 * Text Domain: fcm-reels
 * Requires Plugins: fluent-community, fluent-player
 */

if (!defined('ABSPATH')) {
    exit;
}

define('FCM_REELS_VERSION', '2.1.7');
define('FCM_REELS_FILE', __FILE__);
define('FCM_REELS_DIR', plugin_dir_path(__FILE__));
define('FCM_REELS_URL', plugin_dir_url(__FILE__));

/**
 * Check that FluentCommunity is active before doing anything.
 */
add_action('plugins_loaded', 'fcm_reels_init');
add_action('wp_enqueue_scripts', 'fcm_reels_enqueue_global_assets');
add_action('fluent_community/portal_footer', 'fcm_reels_inject_script_headless');
add_action('fcm_reels_hourly_task', ['FCM_Reels_DB', 'aggregate_metrics']);

if (!wp_next_scheduled('fcm_reels_hourly_task')) {
    wp_schedule_event(time(), 'hourly', 'fcm_reels_hourly_task');
}

/**
 * Enqueue global assets like the upload monitor.
 */
function fcm_reels_enqueue_global_assets()
{
    wp_enqueue_script(
        'fcm-reels-uploader-monitor',
        FCM_REELS_URL . 'assets/js/uploader-monitor.js',
        [],
        FCM_REELS_VERSION,
        true
    );

    wp_localize_script('fcm-reels-uploader-monitor', 'FCMUploader', [
        'nonce'   => wp_create_nonce('wp_rest'),
        'apiBase' => esc_url_raw(rest_url('fcm-reels/v1'))
    ]);
}

/**
 * Inject the script directly in Fluent Community's Headless mode since wp_footer is bypassed.
 */
function fcm_reels_inject_script_headless()
{
    $nonce = wp_create_nonce('wp_rest');
    $apiBase = esc_url_raw(rest_url('fcm-reels/v1'));
    $scriptUrl = esc_url(FCM_REELS_URL . 'assets/js/uploader-monitor.js?ver=' . FCM_REELS_VERSION);
    
    echo "<script type='text/javascript'>
        window.FCMUploader = { nonce: '{$nonce}', apiBase: '{$apiBase}' };
    </script>";
    echo "<script type='text/javascript' src='{$scriptUrl}'></script>";
}

/**
 * Initialize the plugin after all plugins are loaded.
 */
function fcm_reels_init()
{
    if (!defined('FLUENT_COMMUNITY_PLUGIN_URL')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>FCM Reels</strong> requires <strong>FluentCommunity</strong> to be installed and active.</p></div>';
        });
        return;
    }

    if (!defined('FLUENT_PLAYER')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>FCM Reels</strong> requires <strong>FluentPlayer</strong> to be installed and active.</p></div>';
        });
        return;
    }

    require_once FCM_REELS_DIR . 'includes/class-fcm-reels-db.php';
    require_once FCM_REELS_DIR . 'includes/class-fcm-reels-query.php';
    require_once FCM_REELS_DIR . 'includes/class-fcm-reels-api.php';
    require_once FCM_REELS_DIR . 'includes/class-fcm-reels-page.php';
    require_once FCM_REELS_DIR . 'admin/class-fcm-reels-admin.php';

    // Initialize Database
    FCM_Reels_DB::init_tables();

    (new FCM_Reels_API())->register();
    (new FCM_Reels_Page())->register();
    (new FCM_Reels_Admin())->register();

    add_filter('wp_handle_upload_prefilter', 'fcm_reels_limit_video_upload_size');

    // Social Sharing: Inject thumbnails into the head for better previews
    add_action('wp_head', 'fcm_reels_inject_social_meta', 5);
    add_action('fluent_community/portal_head_meta', 'fcm_reels_inject_social_meta_headless', 10, 1);
}

/**
 * Inject OpenGraph and Twitter meta tags for video posts in standard WordPress.
 */
function fcm_reels_inject_social_meta()
{
    if (!is_singular()) {
        return;
    }

    global $post, $wpdb;
    if (!$post) {
        return;
    }
    
    // Support for custom Orbits URL structure: /{username}/orbits/{slug}/
    $url = $_SERVER['REQUEST_URI'];
    if (strpos($url, '/orbits/') !== false) {
        preg_match('/\/orbits\/([^\/?]+)/', $url, $matches);
        if (!empty($matches[1])) {
            $slug = sanitize_text_field($matches[1]);
            $posts_tbl = $wpdb->prefix . 'fcom_posts';
            $feed_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$posts_tbl} WHERE slug = %s LIMIT 1", $slug));
            
            if ($feed_id) {
                fcm_reels_output_social_meta($feed_id);
                return;
            }
        }
    }

    fcm_reels_output_social_meta($post->ID);
}

/**
 * Inject OpenGraph tags for Headless Fluent Community Portal.
 */
function fcm_reels_inject_social_meta_headless($route = '')
{
    global $wpdb;
    
    // Check if the current URL is a single post
    $url = $_SERVER['REQUEST_URI'];
    if (strpos($url, '/post/') === false) {
        return;
    }
    
    // Extract the post slug from the URL: /post/my-post-slug
    preg_match('/\/post\/([^\/?]+)/', $url, $matches);
    if (empty($matches[1])) {
        return;
    }
    
    $slug = sanitize_text_field($matches[1]);
    
    // Get the feed post ID from the slug
    $posts_tbl = $wpdb->prefix . 'fcom_posts';
    $feed_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$posts_tbl} WHERE slug = %s LIMIT 1", $slug));
    
    if ($feed_id) {
        fcm_reels_output_social_meta($feed_id);
    }
}

/**
 * Output the actual meta tags for a given feed ID.
 */
function fcm_reels_output_social_meta($feed_id)
{
    global $wpdb;
    
    // Check if this post has a video in the media archive
    $archive_tbl = $wpdb->prefix . 'fcom_media_archive';
    $video = $wpdb->get_row($wpdb->prepare(
        "SELECT media_url, media_type, settings FROM {$archive_tbl} 
         WHERE feed_id = %d AND is_active = 1 
         AND (media_type = 'fluent_player' OR media_type LIKE 'video/%') 
         LIMIT 1",
        $feed_id
    ));

    if (!$video) {
        return;
    }

    // Fetch the post content for the description and title
    $posts_tbl = $wpdb->prefix . 'fcom_posts';
    $feed_post = $wpdb->get_row($wpdb->prepare(
        "SELECT content, author_id FROM {$posts_tbl} WHERE id = %d LIMIT 1",
        $feed_id
    ));

    $description = '';
    $title = 'Video Post';
    
    if ($feed_post) {
        $description = wp_strip_all_tags($feed_post->content);
        // Truncate description for meta tag
        if (mb_strlen($description) > 200) {
            $description = mb_substr($description, 0, 197) . '...';
        }
        
        $user_info = get_userdata($feed_post->author_id);
        if ($user_info) {
            $title = $user_info->display_name . ' posted a video';
        }
    }

    // 1. Try to get thumbnail from Fluent Player media settings
    if (!empty($video->settings)) {
        $settings = json_decode($video->settings, true);
        if (!$settings && is_string($video->settings)) {
            $settings = maybe_unserialize($video->settings);
        }
        if (is_array($settings) && !empty($settings['posterSrc'])) {
            $thumb_url = $settings['posterSrc'];
        } elseif (is_array($settings) && !empty($settings['thumbnail'])) {
            $thumb_url = $settings['thumbnail'];
        }
    }

    // 2. Fallback to Featured Image
    if (!$thumb_url && has_post_thumbnail($feed_id)) {
        $thumb_url = get_the_post_thumbnail_url($feed_id, 'large');
    }

    // 3. Fallback to common video icon
    if (!$thumb_url) {
        $thumb_url = FCM_REELS_URL . 'assets/img/video-icon.png';
    }

    if ($thumb_url || $description) {
        echo "\n<!-- FCM Reels Social Meta -->\n";
        
        if ($title) {
            echo '<meta property="og:title" content="' . esc_attr($title) . '" />' . "\n";
            echo '<meta name="twitter:title" content="' . esc_attr($title) . '" />' . "\n";
        }
        
        if ($description) {
            echo '<meta property="og:description" content="' . esc_attr($description) . '" />' . "\n";
            echo '<meta name="twitter:description" content="' . esc_attr($description) . '" />' . "\n";
        }
        
        if ($thumb_url) {
            echo '<meta property="og:image" content="' . esc_url($thumb_url) . '" />' . "\n";
            echo '<meta property="og:image:secure_url" content="' . esc_url($thumb_url) . '" />' . "\n";
            echo '<meta property="og:image:width" content="1200" />' . "\n";
            echo '<meta property="og:image:height" content="630" />' . "\n";
            echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
            echo '<meta name="twitter:image" content="' . esc_url($thumb_url) . '" />' . "\n";
        }
        
        echo "<!-- End FCM Reels Social Meta -->\n\n";
    }
}

/**
 * Reject video uploads larger than 10MB.
 */
function fcm_reels_limit_video_upload_size($file)
{
    if (!empty($file['error'])) {
        return $file;
    }

    $size = isset($file['size']) ? (int) $file['size'] : 0;
    $limit = 10 * 1024 * 1024; // 10MB

    $type = isset($file['type']) ? $file['type'] : '';
    $name = isset($file['name']) ? $file['name'] : '';
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $video_exts = ['mp4', 'mov', 'webm', 'avi', 'm4v', 'm3u8', 'mpd'];

    if (strpos($type, 'video/') !== false || in_array($ext, $video_exts)) {
        if ($size > $limit) {
            // Log the discrepancy for debugging
            error_log("FCM Reels: Video rejected. Detected size: $size bytes, Limit: $limit bytes.");
            $file['error'] = "Video too large! Please keep files under 10MB.";
        }
    }

    return $file;
}
