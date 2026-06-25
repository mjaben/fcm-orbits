<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * FCM_Reels_API
 *
 * Registers and handles the REST API endpoints that power the reel feed.
 */
class FCM_Reels_API {

    /**
     * Register REST API routes.
     *
     * @return void
     */
    public function register() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
        add_action( 'add_attachment', [ $this, 'sync_to_cf_stream' ] );
        add_action( 'add_attachment', [ $this, 'link_thumbnail_to_video' ] );
    }

    /**
     * Hook into WordPress uploads to sync videos to Cloudflare Stream.
     *
     * @param int $attachment_id
     * @return void
     */
    public function sync_to_cf_stream( $attachment_id ) {
        if ( get_option( 'fcm_reels_cf_sync_enabled' ) !== 'yes' ) return;

        $post = get_post( $attachment_id );
        if ( ! $post || strpos( $post->post_mime_type, 'video/' ) === false ) return;

        $account_id = get_option( 'fcm_reels_cf_account_id' );
        $api_token  = get_option( 'fcm_reels_cf_api_token' );
        if ( ! $account_id || ! $api_token ) return;

        $video_url = wp_get_attachment_url( $attachment_id );
        if ( ! $video_url ) return;

        // Use Cloudflare "Upload from URL" API
        $endpoint = "https://api.cloudflare.com/client/v4/accounts/{$account_id}/stream/copy";
        
        $response = wp_remote_post( $endpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_token,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( [
                'url'  => $video_url,
                'meta' => [
                    'name'          => $post->post_title,
                    'attachment_id' => $attachment_id,
                    'source'        => 'fcm-reels-sync'
                ]
            ] )
        ] );

        if ( is_wp_error( $response ) ) return;

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! empty( $data['result']['uid'] ) ) {
            // Save the Cloudflare Video UID to the attachment meta
            update_post_meta( $attachment_id, '_fcm_reels_cf_uid', $data['result']['uid'] );
            update_post_meta( $attachment_id, '_fcm_reels_cf_hls_url', $data['result']['playback']['hls'] );
        }
    }

    /**
     * Define the REST API routes.
     *
     * @return void
     */
    public function register_routes() {
        $namespace = 'fcm-reels/v1';

        // Main video feed endpoint.
        register_rest_route( $namespace, '/feed', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_feed' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'cursor'     => [
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'per_page'   => [
                    'default'           => 10,
                    'sanitize_callback' => 'absint',
                ],
                'space'      => [
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'seed'       => [
                    'default'           => 0,
                    'sanitize_callback' => 'absint',
                ],
                'seen'       => [
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'share_id'   => [
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ] );

        // Space/Category list endpoint.
        register_rest_route( $namespace, '/spaces', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_spaces' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );

        // POST /fcm-reels/v1/pulse (Log granular events)
        register_rest_route( $namespace, '/pulse', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'track_event' ],
            'permission_callback' => function() { return true; },
            'args'                => [
                'video_id'      => [ 'required' => true, 'sanitize_callback' => 'absint' ],
                'event_type'    => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
                'watch_seconds' => [ 'default'  => 0, 'sanitize_callback' => 'absint' ],
                'session_id'    => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
                'device'        => [ 'default'  => 'desktop', 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        // POST /fcm-reels/v1/stream-session (Track feed sessions)
        register_rest_route( $namespace, '/stream-session', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'track_session' ],
            'permission_callback' => function() { return true; },
            'args'                => [
                'session_id'    => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
                'viewed_count'  => [ 'default'  => 0, 'sanitize_callback' => 'absint' ],
                'total_watch'   => [ 'default'  => 0, 'sanitize_callback' => 'absint' ],
            ],
        ] );

        // Like/unlike a video post.
        register_rest_route( $namespace, '/like/(?P<id>\d+)', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'toggle_like' ],
            'permission_callback' => [ $this, 'require_login' ],
            'args'                => [
                'id' => [
                    'required'          => true,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ] );

        // POST /fcm-reels/v1/upload-thumbnail (Client-side thumbnail generation)
        register_rest_route( $namespace, '/upload-thumbnail', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'upload_thumbnail' ],
            'permission_callback' => [ $this, 'require_login' ],
            'args'                => [
                'filename' => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
                'image'    => [ 'required' => true ],
            ],
        ] );
    }

    /**
     * Permission: allow access if the reel page is public, or user is logged in.
     * Respects the plugin's "require login" setting.
     *
     * @return bool|WP_Error
     */
    public function check_permission() {
        $require_login = get_option( 'fcm_reels_require_login', 'no' );
        if ( $require_login === 'yes' && ! is_user_logged_in() ) {
            return new WP_Error( 'rest_forbidden', __( 'You must be logged in to view reels.', 'fcm-reels' ), [ 'status' => 401 ] );
        }
        return true;
    }

    /**
     * Permission: requires the user to be logged in.
     *
     * @return bool|WP_Error
     */
    public function require_login() {
        if ( ! is_user_logged_in() ) {
            return new WP_Error( 'rest_forbidden', __( 'You must be logged in.', 'fcm-reels' ), [ 'status' => 401 ] );
        }
        return true;
    }

    /**
     * GET /fcm-reels/v1/feed
     * Returns a paginated list of video reels.
     *
     * @param WP_REST_Request $request Incoming request.
     * @return WP_REST_Response
     */
    public function get_feed( WP_REST_Request $request ) {
        $query    = new FCM_Reels_Query();
        $per_page = min( absint( $request->get_param( 'per_page' ) ), 20 );
        $cursor   = $request->get_param( 'cursor' );
        $space    = $request->get_param( 'space' );
        $seed     = absint( $request->get_param( 'seed' ) );
        $seen     = $request->get_param( 'seen' );
        $share_id = sanitize_text_field( $request->get_param( 'share_id' ) );

        $data = $query->get_videos_v2( $cursor, $per_page, $space, $seed, $seen, $share_id );

        $response = rest_ensure_response( $data );

        // LiteSpeed & Cache Optimization: 
        // Ensure discovery feeds are never cached by the server.
        $response->header( 'Cache-Control', 'no-cache, must-revalidate, max-age=0' );
        $response->header( 'Expires', 'Wed, 11 Jan 1984 05:00:00 GMT' );

        return $response;
    }

    /**
     * GET /fcm-reels/v1/spaces
     * Returns spaces that contain video content (for the filter bar).
     *
     * @param WP_REST_Request $request Incoming request.
     * @return WP_REST_Response
     */
    public function get_spaces( WP_REST_Request $request ) {
        $query  = new FCM_Reels_Query();
        $spaces = $query->get_spaces_with_videos();
        return rest_ensure_response( [ 'spaces' => $spaces ] );
    }

    /**
     * Log a granular analytics event.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function track_event( $request ) {
        error_log( "FCM Orbits API: Pulse received for video " . $request->get_param( 'video_id' ) );
        global $wpdb;
        $table = FCM_Reels_DB::get_events_table();

        $inserted = $wpdb->insert( $table, [
            'user_id'       => get_current_user_id(),
            'video_id'      => $request->get_param( 'video_id' ),
            'event_type'    => $request->get_param( 'event_type' ),
            'watch_seconds' => $request->get_param( 'watch_seconds' ),
            'session_id'    => $request->get_param( 'session_id' ),
            'device'        => $request->get_param( 'device' ),
            'created_at'    => current_time( 'mysql' ),
        ] );

        if ( ! $inserted ) {
            error_log( "FCM Orbits Analytics Error: Failed to insert event. SQL Error: " . $wpdb->last_error );
        }

        if ( $inserted && $request->get_param( 'event_type' ) === 'video_view' ) {
            // Still increment the legacy counter for backward compatibility with existing shortcodes
            $posts_table = $wpdb->prefix . 'fcom_posts';
            $wpdb->query( $wpdb->prepare( "UPDATE {$posts_table} SET views_count = views_count + 1 WHERE id = %d", $request->get_param( 'video_id' ) ) );
        }

        return new WP_REST_Response( [ 'success' => (bool) $inserted ], 200 );
    }

    /**
     * Track or update a feed session.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function track_session( $request ) {
        global $wpdb;
        $table = FCM_Reels_DB::get_sessions_table();
        $session_id = $request->get_param( 'session_id' );

        $exists = $wpdb->get_var( $wpdb->prepare( "SELECT session_id FROM $table WHERE session_id = %s", $session_id ) );

        if ( $exists ) {
            $wpdb->update( $table, [
                'viewed_count'       => $request->get_param( 'viewed_count' ),
                'total_watch_time'   => $request->get_param( 'total_watch' ),
                'updated_at'         => current_time( 'mysql' ),
            ], [ 'session_id' => $session_id ] );
        } else {
            $wpdb->insert( $table, [
                'session_id'         => $session_id,
                'user_id'            => get_current_user_id(),
                'viewed_count'       => $request->get_param( 'viewed_count' ),
                'total_watch_time'   => $request->get_param( 'total_watch' ),
                'created_at'         => current_time( 'mysql' ),
                'updated_at'         => current_time( 'mysql' ),
            ] );
        }

        return new WP_REST_Response( [ 'success' => true ], 200 );
    }

    /**
     * POST /fcm-reels/v1/like/{id}
     * Toggles a like reaction on a feed post using FCM's own reaction system.
     *
     * @param WP_REST_Request $request Incoming request.
     * @return WP_REST_Response|WP_Error
     */
    public function toggle_like( WP_REST_Request $request ) {
        global $wpdb;

        $feed_id    = $request->get_param( 'id' );
        $user_id    = get_current_user_id();
        $react_tbl  = $wpdb->prefix . 'fcom_reactions';
        $posts_tbl  = $wpdb->prefix . 'fcom_posts';

        // Confirm the post is a published video.
        $post = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, reactions_count FROM {$posts_tbl}
                 WHERE id = %d AND status = 'published' LIMIT 1",
                $feed_id
            )
        );

        if ( ! $post ) {
            return new WP_Error( 'not_found', __( 'Video not found.', 'fcm-reels' ), [ 'status' => 404 ] );
        }

        // Check existing like.
        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$react_tbl}
                 WHERE object_id = %d AND object_type = 'feed' AND user_id = %d AND type = 'like' LIMIT 1",
                $feed_id,
                $user_id
            )
        );


        if ( $existing ) {
            // Unlike.
            $wpdb->delete( $react_tbl, [ 'id' => $existing ] );
            $new_count = max( 0, (int) $post->reactions_count - 1 );
            $liked     = false;
        } else {
            // Like.
            $wpdb->insert( $react_tbl, [
                'object_id'   => $feed_id,
                'object_type' => 'feed',
                'user_id'     => $user_id,
                'type'        => 'like',
                'created_at'  => current_time( 'mysql' ),
                'updated_at'  => current_time( 'mysql' ),
            ] );
            $new_count = (int) $post->reactions_count + 1;
            $liked     = true;
        }

        // Update the cached count on the post.
        $wpdb->update( $posts_tbl, [ 'reactions_count' => $new_count ], [ 'id' => $feed_id ] );

        return rest_ensure_response( [
            'liked'       => $liked,
            'likes_count' => $new_count,
        ] );
    }

    /**
     * POST /fcm-reels/v1/upload-thumbnail
     * Receives a base64 generated thumbnail from the client and saves it as an attachment.
     * Maps it to the current user and filename so it can be linked when the video finishes uploading.
     */
    public function upload_thumbnail( WP_REST_Request $request ) {
        $filename   = sanitize_file_name( $request->get_param( 'filename' ) );
        $base64_img = $request->get_param( 'image' );
        $user_id    = get_current_user_id();

        if ( empty( $base64_img ) || strpos( $base64_img, 'data:image/' ) !== 0 ) {
            return new WP_Error( 'invalid_image', 'Invalid base64 image data.', [ 'status' => 400 ] );
        }

        list( $type, $data ) = explode( ';', $base64_img );
        list( , $data )      = explode( ',', $data );
        $data = base64_decode( $data );

        if ( ! $data ) {
            return new WP_Error( 'decode_failed', 'Failed to decode base64 image.', [ 'status' => 400 ] );
        }

        $ext = 'jpg';
        if ( strpos( $type, 'png' ) !== false ) $ext = 'png';
        
        $thumb_filename = 'fcm-thumb-' . wp_generate_password( 8, false ) . '-' . time() . '.' . $ext;
        
        $upload_dir = wp_upload_dir();
        $file_path  = $upload_dir['path'] . '/' . $thumb_filename;
        file_put_contents( $file_path, $data );

        $file_type = wp_check_filetype( $thumb_filename, null );
        
        $attachment = [
            'guid'           => $upload_dir['url'] . '/' . $thumb_filename,
            'post_mime_type' => $file_type['type'],
            'post_title'     => preg_replace( '/\.[^.]+$/', '', $thumb_filename ),
            'post_content'   => '',
            'post_status'    => 'inherit'
        ];

        $attach_id = wp_insert_attachment( $attachment, $file_path );
        require_once( ABSPATH . 'wp-admin/includes/image.php' );
        $attach_data = wp_generate_attachment_metadata( $attach_id, $file_path );
        wp_update_attachment_metadata( $attach_id, $attach_data );

        // Map the generated thumbnail to the user and original video filename
        $cache_key = 'fcm_thumb_' . $user_id . '_' . md5( $filename );
        set_transient( $cache_key, $attach_id, 3600 ); // Keep for 1 hour

        return rest_ensure_response( [ 'success' => true, 'attachment_id' => $attach_id ] );
    }

    /**
     * Link the pre-generated thumbnail to the uploaded video.
     * Runs on add_attachment hook.
     */
    public function link_thumbnail_to_video( $attachment_id ) {
        $post = get_post( $attachment_id );
        if ( ! $post || strpos( $post->post_mime_type, 'video/' ) === false ) return;

        $user_id  = $post->post_author;
        $filename = '';

        if ( ! empty( $_FILES ) ) {
            foreach ( $_FILES as $file ) {
                if ( ! empty( $file['name'] ) ) {
                    $filename = sanitize_file_name( wp_unslash( $file['name'] ) );
                    break;
                }
            }
        }

        if ( empty( $filename ) ) {
            $file_path = get_post_meta( $attachment_id, '_wp_attached_file', true );
            $filename  = sanitize_file_name( basename( $file_path ) );
        }

        $cache_key = 'fcm_thumb_' . $user_id . '_' . md5( $filename );
        $thumb_id  = get_transient( $cache_key );

        if ( $thumb_id ) {
            update_post_meta( $attachment_id, '_thumbnail_id', $thumb_id );
            delete_transient( $cache_key );
        }
    }

}
