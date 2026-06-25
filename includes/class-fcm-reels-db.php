<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * FCM_Reels_DB
 *
 * Handles database schema creation and maintenance for Orbit Analytics.
 */
class FCM_Reels_DB {

    /**
     * Create or update the analytics tables.
     *
     * @return void
     */
    public static function init_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // 1. Raw Event Logs
        $table_events = $wpdb->prefix . 'orbit_video_events';
        $sql_events = "CREATE TABLE $table_events (
            id BIGINT(20) NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) DEFAULT 0,
            video_id BIGINT(20) NOT NULL,
            event_type VARCHAR(50) NOT NULL,
            watch_seconds INT(11) DEFAULT 0,
            session_id VARCHAR(255) DEFAULT '',
            device VARCHAR(50) DEFAULT 'desktop',
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY video_id (video_id),
            KEY event_type (event_type),
            KEY user_id (user_id)
        ) $charset_collate;";

        // 2. Aggregated Metrics
        $table_metrics = $wpdb->prefix . 'orbit_video_metrics';
        $sql_metrics = "CREATE TABLE $table_metrics (
            video_id BIGINT(20) NOT NULL,
            total_views BIGINT(20) DEFAULT 0,
            unique_views BIGINT(20) DEFAULT 0,
            total_likes BIGINT(20) DEFAULT 0,
            total_comments BIGINT(20) DEFAULT 0,
            total_watch_time BIGINT(20) DEFAULT 0,
            avg_watch_time FLOAT DEFAULT 0,
            completion_rate FLOAT DEFAULT 0,
            engagement_score FLOAT DEFAULT 0,
            vtr FLOAT DEFAULT 0,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (video_id)
        ) $charset_collate;";

        // 3. Feed Sessions
        $table_sessions = $wpdb->prefix . 'orbit_feed_sessions';
        $sql_sessions = "CREATE TABLE $table_sessions (
            session_id VARCHAR(255) NOT NULL,
            user_id BIGINT(20) DEFAULT 0,
            viewed_count INT(11) DEFAULT 0,
            avg_swipe_velocity FLOAT DEFAULT 0,
            total_watch_time INT(11) DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (session_id)
        ) $charset_collate;";

        dbDelta( $sql_events );
        dbDelta( $sql_metrics );
        dbDelta( $sql_sessions );

        // 4. Alter external fcom_posts table if necessary
        $posts_table = $wpdb->prefix . 'fcom_posts';
        // Check if the table exists first (in case FluentCommunity isn't active or loaded yet)
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$posts_table'" ) === $posts_table ) {
            $column_exists = $wpdb->get_results( "SHOW COLUMNS FROM $posts_table LIKE 'views_count'" );
            if ( empty( $column_exists ) ) {
                $wpdb->query( "ALTER TABLE $posts_table ADD COLUMN views_count INT DEFAULT 0 AFTER reactions_count" );
            }
        }
    }

    /**
     * Get the events table name.
     */
    public static function get_events_table() {
        global $wpdb;
        return $wpdb->prefix . 'orbit_video_events';
    }

    /**
     * Get the metrics table name.
     */
    public static function get_metrics_table() {
        global $wpdb;
        return $wpdb->prefix . 'orbit_video_metrics';
    }

    /**
     * Get the sessions table name.
     */
    public static function get_sessions_table() {
        global $wpdb;
        return $wpdb->prefix . 'orbit_feed_sessions';
    }

    /**
     * Aggregate raw events into metrics.
     */
    public static function aggregate_metrics() {
        global $wpdb;
        $table_events = self::get_events_table();
        $table_metrics = self::get_metrics_table();
        $posts_tbl = $wpdb->prefix . 'fcom_posts';

        $archive_tbl = $wpdb->prefix . 'fcom_media_archive';
        $video_ids = $wpdb->get_col( "
            SELECT DISTINCT p.id 
            FROM $posts_tbl p 
            INNER JOIN $archive_tbl ma ON ma.feed_id = p.id 
            WHERE p.status = 'published' 
            AND ma.is_active = 1 
            AND (ma.media_type = 'fluent_player' OR ma.media_type LIKE 'video/%' OR ma.media_type = 'video')
        " );


        foreach ( $video_ids as $video_id ) {
            $video_id = absint( $video_id );

            // Views & Unique Views
            $total_views = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table_events WHERE video_id = %d AND event_type = 'video_view'", $video_id ) );
            $unique_views = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM $table_events WHERE video_id = %d AND event_type = 'video_view'", $video_id ) );

            // Watch Time
            $total_watch = $wpdb->get_var( $wpdb->prepare( "SELECT SUM(watch_seconds) FROM $table_events WHERE video_id = %d AND event_type = 'heartbeat'", $video_id ) );
            $avg_watch = $total_views > 0 ? $total_watch / $total_views : 0;

            // Completions
            $completions = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table_events WHERE video_id = %d AND event_type = 'video_complete'", $video_id ) );
            $completion_rate = $total_views > 0 ? ( $completions / $total_views ) * 100 : 0;
            $vtr = $total_views > 0 ? ( $completions / $total_views ) : 0; // Simplified VTR

            // Engagement (Likes + Comments from posts table cache)
            $post = $wpdb->get_row( $wpdb->prepare( "SELECT reactions_count, comments_count FROM $posts_tbl WHERE id = %d", $video_id ) );
            $likes = $post ? (int) $post->reactions_count : 0;
            $comments = $post ? (int) $post->comments_count : 0;
            $engagement_score = ( $likes * 2 ) + ( $comments * 5 );

            $wpdb->replace( $table_metrics, [
                'video_id'         => $video_id,
                'total_views'      => $total_views,
                'unique_views'     => $unique_views,
                'total_likes'      => $likes,
                'total_comments'   => $comments,
                'total_watch_time' => $total_watch,
                'avg_watch_time'   => $avg_watch,
                'completion_rate'  => $completion_rate,
                'engagement_score' => $engagement_score,
                'vtr'              => $vtr,
                'updated_at'       => current_time( 'mysql' ),
            ] );
        }
    }

    /**
     * Sync views based on Likes (150%).
     * 
     * @return int Number of videos processed.
     */
    public static function sync_views_from_likes() {
        global $wpdb;
        $posts_tbl = $wpdb->prefix . 'fcom_posts';
        $metrics_tbl = self::get_metrics_table();

        // Get all published videos
        $videos = $wpdb->get_results( "SELECT id, reactions_count FROM $posts_tbl WHERE status = 'published'" );
        if ( ! $videos ) {
            return 0;
        }

        $count = 0;
        foreach ( $videos as $v ) {
            $likes = (int) $v->reactions_count;
            $target_views = ceil( $likes * 1.5 );

            // Update main posts table
            $wpdb->update( $posts_tbl, [ 'views_count' => $target_views ], [ 'id' => $v->id ] );

            // Update metrics table if it exists
            $wpdb->update( $metrics_tbl, [ 'total_views' => $target_views ], [ 'video_id' => $v->id ] );

            $count++;
        }
        return $count;
    }
}
