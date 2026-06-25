<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * FCM_Reels_Query
 *
 * Fetches videos from FluentCommunity's media archive table and joins them
 * with their parent feed posts and author profiles.
 *
 * Data flow:
 *   wp_fcom_media_archive (primary source for video URLs)
 *     → JOIN wp_fcom_posts (parent post data: likes, comments, space)
 *     → JOIN wp_fcom_xprofile (author data)
 */
class FCM_Reels_Query {

    private $pool_expiry = 43200; // 12 hours

    /**
     * Fetch a paginated list of reel videos.
     *
     * @param int    $page       Current page (1-indexed).
     * @param int    $per_page   Items per page (capped at 20).
     * @param string $space_slug Filter by FCM space slug (empty = all).
     * @param string $order_by   'latest' | 'popular'.
     * @return array { videos: array, has_more: bool, total: int, page: int }
     */
    public function get_videos( $page = 1, $per_page = 10, $space_slug = '', $order_by = 'discovery', $seed = 0, $exclude_ids = '' ) {
        global $wpdb;

        $page      = max( 1, absint( $page ) );
        $per_page  = min( 20, max( 1, absint( $per_page ) ) );
        $offset    = ( $page - 1 ) * $per_page;
        $seed      = absint( $seed ) ?: mt_rand( 1, 999999 );

        $posts_tbl   = $wpdb->prefix . 'fcom_posts';
        $spaces_tbl  = $wpdb->prefix . 'fcom_spaces';
        $xp_tbl      = $wpdb->prefix . 'fcom_xprofile';
        $archive_tbl = $wpdb->prefix . 'fcom_media_archive';

        // Exclusion logic: prevent seen videos from appearing
        $exclude_where = '';
        if ( ! empty( $exclude_ids ) ) {
            $ids = array_map( 'absint', explode( ',', $exclude_ids ) );
            if ( ! empty( $ids ) ) {
                $exclude_where = "AND p.id NOT IN (" . implode( ',', $ids ) . ")";
            }
        }

        /**
         * Hotness Discovery Algorithm:
         * ( (Likes * 2) + (Comments * 5) + 1 ) / (DaysOld + 2)^1.5
         */
        $hotness_sql = "( (p.reactions_count * 2) + (p.comments_count * 5) + 1 ) / POW( DATEDIFF( NOW(), ma.created_at ) + 2, 1.5 )";

        // ORDER BY clause.
        // [STOCHASTIC WATERFALL]
        // [A] Popularity (Dampened): Rewards viral content without making it unbeatable.
        // [B] Freshness Boost: Gives brand new videos (last 24h) a head start.
        // [C] Shuffle Force: Ensures a different experience on every reload/loop.
        $order_sql = "
            (CASE WHEN p.created_at > NOW() - INTERVAL 3 DAY THEN 100.0 ELSE 0 END)
            - (DATEDIFF(NOW(), ma.created_at) * 1.5)
            + (LOG10(p.reactions_count + p.comments_count + 1) * 2.0)
            + (RAND({$seed}) * 2.5)
            DESC
        ";

        // Optional space filter.
        $space_join  = '';
        $space_where = '';
        if ( ! empty( $space_slug ) ) {
            $space_slug  = sanitize_text_field( $space_slug );
            $space_join  = "LEFT JOIN {$spaces_tbl} sp_filter ON sp_filter.id = p.space_id";
            $space_where = $wpdb->prepare( 'AND sp_filter.slug = %s', $space_slug );
        }

        // Query the media archive for video types.
        $sql = "
            SELECT
                ma.id         AS archive_id,
                ma.media_url  AS video_url,
                ma.settings   AS media_settings,
                ma.created_at AS archive_created_at,
                p.id          AS post_id,
                p.slug        AS post_slug,
                p.title       AS post_title,
                p.message     AS post_message,
                p.meta        AS post_meta,
                p.reactions_count,
                p.comments_count,
                p.user_id,
                xp.display_name,
                xp.avatar,
                xp.username,
                sp2.title     AS space_title,
                sp2.slug      AS space_slug
            FROM {$archive_tbl} ma
            INNER JOIN {$posts_tbl} p
                ON p.id = ma.feed_id
            LEFT JOIN {$xp_tbl} xp
                ON xp.user_id = p.user_id
            LEFT JOIN {$spaces_tbl} sp2
                ON sp2.id = p.space_id
            {$space_join}
            WHERE ma.is_active = 1
              AND (ma.media_type = 'fluent_player' OR ma.media_type LIKE 'video/%')
              AND p.status = 'published'
              {$space_where}
              {$exclude_where}
            ORDER BY {$order_sql}
            LIMIT %d OFFSET %d
        ";

        return [ 'videos' => [], 'has_more' => false ];
    }

    /**
     * Discovery Engine 2.0: Get videos via Cursor-Based Delivery.
     *
     * @param string $cursor   Base64 encoded cursor.
     * @param int    $per_page Number of videos to fetch.
     * @param string $space    Optional space filter.
     * @param int    $seed     Random seed.
     * @return array
     */
    public function get_videos_v2( $cursor = '', $per_page = 8, $space = '', $seed = 0, $seen = '', $share_id = '' ) {
        $user_id = get_current_user_id();
        $per_page = absint( $per_page );

        // 1. Decode Cursor or Generate First Pool
        $index = 0;
        if ( ! empty( $cursor ) ) {
            $decoded = json_decode( base64_decode( $cursor ), true );
            if ( $decoded && isset( $decoded['i'] ) ) {
                $index = absint( $decoded['i'] );
                $seed  = isset( $decoded['s'] ) ? absint( $decoded['s'] ) : $seed;
            }
        }

        // 2. Get/Generate the Universe Pool (now with Seen Penalties instead of exclusions)
        $pool = $this->get_discovery_pool( $user_id, $space, $seed, $seen );
        
        if ( empty( $pool ) ) {
            return [ 'videos' => [], 'next_cursor' => null, 'has_more' => false ];
        }

        // If there's a share ID and this is the first page, force it to the top.
        if ( $index === 0 && ! empty( $share_id ) ) {
            global $wpdb;
            $posts_tbl = $wpdb->prefix . 'fcom_posts';
            
            $actual_id = 0;
            if ( is_numeric( $share_id ) ) {
                $actual_id = (int) $share_id;
            } else {
                $actual_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$posts_tbl} WHERE slug = %s LIMIT 1", $share_id ) );
            }

            if ( $actual_id > 0 ) {
                $pool = array_diff( $pool, [ $actual_id ] );
                array_unshift( $pool, $actual_id );
                $pool = array_values( $pool );
            }
        }

        // 3. Slice the pool for this chunk
        $ids = array_slice( $pool, $index, $per_page );
        $next_index = $index + count( $ids );
        $has_more = $next_index < count( $pool );

        if ( empty( $ids ) ) {
            // Pool exhausted: Generate a NEW pool with a NEW seed for infinite variety
            $new_seed = rand( 1, 999999 );
            return $this->get_videos_v2( '', $per_page, $space, $new_seed );
        }

        // 4. Fetch full data for these IDs (preserving order)
        $videos = $this->get_videos_by_ids( $ids );

        // 5. Generate Next Cursor
        $next_cursor = null;
        if ( $has_more ) {
            $next_cursor = base64_encode( json_encode( [ 'i' => $next_index, 's' => $seed ] ) );
        } else {
            // End of pool? Send a "Loop" cursor with a fresh seed
            $next_cursor = base64_encode( json_encode( [ 'i' => 0, 's' => rand( 1, 999999 ) ] ) );
        }

        return [
            'videos'      => $videos,
            'next_cursor' => $next_cursor,
            'has_more'    => true // Always true for Discovery Engine 2.0
        ];
    }

    /**
     * Generate or retrieve the ranked ID pool for a session.
     */
    private function get_discovery_pool( $user_id, $space, $seed, $seen = '' ) {
        // We do not include $seen in the cache key.
        // We want the pool to be generated and cached specifically with the penalty applied for this exact session seed.
        $cache_key = "fcm_reels_pool_v3_{$user_id}_" . md5( $space . $seed );
        $pool = get_transient( $cache_key );

        if ( $pool !== false ) {
            return $pool;
        }

        global $wpdb;
        $archive_tbl = $wpdb->prefix . 'fcom_media_archive';
        $posts_tbl   = $wpdb->prefix . 'fcom_posts';
        $spaces_tbl  = $wpdb->prefix . 'fcom_spaces';

        $space_where = '';
        if ( ! empty( $space ) ) {
            $space_where = $wpdb->prepare( "AND sp.slug = %s", $space );
        }

        // The Codex UX Fix: Seen Penalties instead of Exclusions.
        // Drops seen videos heavily in score so they act as fallback inventory.
        $seen_penalty = "";
        if ( ! empty( $seen ) ) {
            $seen_arr = array_map( 'absint', explode( ',', $seen ) );
            if ( ! empty( $seen_arr ) ) {
                $seen_list = implode( ',', $seen_arr );
                $seen_penalty = "- (CASE WHEN p.id IN ({$seen_list}) THEN 50.0 ELSE 0 END)";
            }
        }

        $metrics_tbl = FCM_Reels_DB::get_metrics_table();
        $react_tbl   = $wpdb->prefix . 'fcom_post_reactions';

        $user_likes_join = "";
        $engaged_penalty = "";
        if ( $user_id ) {
            $user_likes_join = $wpdb->prepare( "LEFT JOIN {$react_tbl} ur ON ur.object_id = p.id AND ur.object_type = 'feed' AND ur.user_id = %d AND ur.type = 'like'", $user_id );
            $engaged_penalty = "- (CASE WHEN ur.id IS NOT NULL THEN 500.0 ELSE 0 END)";
        }

        // Generate the ranked universe using the Intelligent Analytics Waterfall
        $sql = "
            SELECT DISTINCT p.id
            FROM {$posts_tbl} p
            INNER JOIN {$archive_tbl} ma ON ma.feed_id = p.id
            LEFT JOIN {$spaces_tbl} sp ON sp.id = p.space_id
            LEFT JOIN {$metrics_tbl} m ON m.video_id = p.id
            {$user_likes_join}
            WHERE ma.is_active = 1
              AND (ma.media_type = 'fluent_player' OR ma.media_type LIKE 'video/%' OR ma.media_type = 'video')
              AND p.status = 'published'
              {$space_where}
            ORDER BY (
                (CASE WHEN p.created_at > NOW() - INTERVAL 3 DAY THEN 100.0 ELSE 0 END)
                + (LOG10(IFNULL(m.avg_watch_time, 0) + 1) * 2.0)
                + (IFNULL(m.completion_rate, 0) * 0.003) 
                + (LOG10(IFNULL(m.engagement_score, 0) + 1) * 2.0)
                - (DATEDIFF(NOW(), p.created_at) * 1.5) -- Strong time penalty for older videos
                + (RAND({$seed}) * 5.0) -- Higher random factor for fresh discovery
                {$seen_penalty}
                {$engaged_penalty}
            ) DESC
            LIMIT 500
        ";

        $ids = $wpdb->get_col( $sql );
        
        if ( empty( $ids ) ) {
            error_log( "FCM Orbits: Discovery Pool EMPTY on LIVE. SQL: " . $sql );
            if ( $wpdb->last_error ) {
                error_log( "FCM Orbits SQL ERROR: " . $wpdb->last_error );
            }
            return [];
        }

        // Remove Small Library Multiplier: We do not want to artificially duplicate videos.
        // If the library only has 3 videos, it will show 3 and then pull fresh ones.
        $pool = $ids;

        if ( ! empty( $pool ) ) {
            set_transient( $cache_key, $pool, $this->pool_expiry );
        }

        return $pool;
    }

    /**
     * Fetch full video objects for a specific list of IDs, preserving order.
     */
    private function get_videos_by_ids( $ids ) {
        if ( empty( $ids ) ) return [];

        global $wpdb;
        $archive_tbl = $wpdb->prefix . 'fcom_media_archive';
        $posts_tbl   = $wpdb->prefix . 'fcom_posts';
        $xp_tbl      = $wpdb->prefix . 'fcom_xprofile';
        $spaces_tbl  = $wpdb->prefix . 'fcom_spaces';

        $id_list = implode( ',', array_map( 'absint', $ids ) );

        $sql = "
            SELECT
                ma.id         AS archive_id,
                ma.media_url  AS video_url,
                ma.settings   AS media_settings,
                ma.created_at AS archive_created_at,
                p.id          AS post_id,
                p.slug        AS post_slug,
                p.title       AS post_title,
                p.message     AS post_message,
                p.meta        AS post_meta,
                p.reactions_count,
                p.comments_count,
                p.views_count,
                p.user_id,
                xp.display_name,
                xp.avatar,
                xp.username,
                sp.title      AS space_title,
                sp.slug       AS space_slug
            FROM {$archive_tbl} ma
            INNER JOIN {$posts_tbl} p ON p.id = ma.feed_id
            LEFT JOIN {$xp_tbl} xp ON xp.user_id = p.user_id
            LEFT JOIN {$spaces_tbl} sp ON sp.id = p.space_id
            WHERE p.id IN ({$id_list})
            ORDER BY FIELD(p.id, {$id_list})
        ";

        $rows = $wpdb->get_results( $sql );
        $videos = [];
        $current_user_id = get_current_user_id();

        foreach ( $rows as $row ) {
            $videos[] = $this->format_video( $row, $current_user_id );
        }

        return $videos;
    }

    /**
     * Format a raw DB row into a clean video object.
     *
     * @param object $row             Raw row from SQL query.
     * @param int    $current_user_id Logged-in user ID (0 if guest).
     * @return array
     */
    private function format_video( $row, $current_user_id ) {
        global $wpdb;
        // Author avatar with WP fallback.
        $avatar = ! empty( $row->avatar )
            ? $row->avatar
            : get_avatar_url( $row->user_id, [ 'size' => 128 ] );

        // Extract thumbnail from media settings        // 1. Check FluentPlayer/Archive settings
        $thumbnail_url = '';
        $media_settings = maybe_unserialize( $row->media_settings );
        if ( isset( $media_settings['thumbnail'] ) ) {
            $thumbnail_url = $media_settings['thumbnail'];
        }

        // 2. Automate: Check if WordPress generated a thumbnail for the attachment itself
        if ( empty( $thumbnail_url ) ) {
            $attachment_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->posts} WHERE guid = %s AND post_type = 'attachment' LIMIT 1", $row->video_url ) );
            if ( $attachment_id ) {
                $wp_thumb = wp_get_attachment_image_src( $attachment_id, 'large' );
                if ( $wp_thumb ) {
                    $thumbnail_url = $wp_thumb[0];
                }
            }
        }

        // 3. Prioritize manual Featured Image (if set, it wins)
        if ( has_post_thumbnail( $row->post_id ) ) {
            $thumbnail_url = get_the_post_thumbnail_url( $row->post_id, 'large' );
        }

        if ( empty( $thumbnail_url ) && ! empty( $row->post_meta ) ) {
            $post_meta = maybe_unserialize( $row->post_meta );
            if ( is_array( $post_meta ) && isset( $post_meta['media_preview']['thumbnail'] ) ) {
                $thumbnail_url = $post_meta['media_preview']['thumbnail'];
            } elseif ( is_array( $post_meta ) && isset( $post_meta['media_preview']['posterSrc'] ) ) {
                $thumbnail_url = $post_meta['media_preview']['posterSrc'];
            }
        }

        // Check if current user has liked this feed post.
        $user_liked = $this->has_user_liked( $row->post_id, $current_user_id );

        // Extract description (strip block markup if any).
        $description = $row->post_message ? $this->strip_blocks( $row->post_message ) : '';

        return [
            'id'             => (int) $row->post_id,
            'archive_id'     => (int) $row->archive_id,
            'slug'           => $row->post_slug,
            'title'          => wp_strip_all_tags( $row->post_title ?: 'Video' ),
            'description'    => wp_strip_all_tags( $description ),
            'video_url'      => esc_url_raw( $row->video_url ),
            'thumbnail_url'  => $thumbnail_url ? esc_url_raw( $thumbnail_url ) : '',
            'duration'       => isset( $media_settings['duration'] ) ? (float) $media_settings['duration'] : 0,
            'author'         => [
                'id'          => (int) $row->user_id,
                'name'        => $row->display_name ?: 'Community Member',
                'username'    => $row->username ?: '',
                'avatar'      => esc_url_raw( $avatar ),
                'profile_url' => $this->get_profile_url( $row->username ),
            ],
            'space'          => [
                'title' => $row->space_title ?: '',
                'slug'  => $row->space_slug  ?: '',
            ],
            'likes_count'    => (int) $row->reactions_count,
            'comments_count' => (int) $row->comments_count,
            'views_count'    => (int) $row->views_count,
            'user_liked'     => $user_liked,
            'created_at'     => $row->archive_created_at,
        ];
    }

    /**
     * Check whether the given user has liked a feed post.
     *
     * @param int $feed_id         FCM post ID.
     * @param int $current_user_id WP user ID (0 = guest).
     * @return bool
     */
    private function has_user_liked( $feed_id, $current_user_id ) {
        if ( ! $current_user_id ) {
            return false;
        }

        global $wpdb;
        $reactions_tbl = $wpdb->prefix . 'fcom_post_reactions';

        return (bool) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$reactions_tbl}
                 WHERE object_id = %d AND object_type = 'feed'
                   AND user_id = %d AND type = 'like' LIMIT 1",
                $feed_id,
                $current_user_id
            )
        );
    }

    /**
     * Strip Gutenberg block comments from a message string to get plain text.
     *
     * @param string $message Raw post message.
     * @return string
     */
    private function strip_blocks( $message ) {
        // Remove block comment tags: <!-- wp:... --> and <!-- /wp:... -->
        $stripped = preg_replace( '/<!--\s*\/?wp:[^>]+-->/s', '', $message );
        // Remove remaining HTML tags.
        return trim( wp_strip_all_tags( $stripped ) );
    }

    /**
     * Build an FCM profile URL for a given username.
     *
     * @param string $username FCM username.
     * @return string
     */
    private function get_profile_url( $username ) {
        if ( ! $username ) {
            return '';
        }
        
        // Use our smart discovery logic
        $manual = get_option( 'fcm_reels_portal_url_manual' );
        if ( $manual ) {
            $base = trim( $manual, '/' );
        } else {
            $base = defined( 'FLUENT_COMMUNITY_PORTAL_SLUG' ) ? FLUENT_COMMUNITY_PORTAL_SLUG : 'community';
        }

        return home_url( trailingslashit( $base ) . 'u/' . rawurlencode( $username ) );
    }

    /**
     * Get FCM spaces that have at least one video in the archive.
     *
     * @return array
     */
    public function get_spaces_with_videos() {
        global $wpdb;

        $posts_tbl   = $wpdb->prefix . 'fcom_posts';
        $spaces_tbl  = $wpdb->prefix . 'fcom_spaces';
        $archive_tbl = $wpdb->prefix . 'fcom_media_archive';

        $sql = "
            SELECT DISTINCT sp.id, sp.title, sp.slug
            FROM {$spaces_tbl} sp
            INNER JOIN {$posts_tbl} p
                ON p.space_id = sp.id
            INNER JOIN {$archive_tbl} ma
                ON ma.feed_id = p.id
            WHERE p.status = 'published'
              AND ma.is_active = 1
              AND (ma.media_type = 'fluent_player' OR ma.media_type LIKE 'video/%')
            ORDER BY sp.title ASC
        ";

        return $wpdb->get_results( $sql );
    }
}
