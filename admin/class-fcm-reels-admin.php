<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * FCM_Reels_Admin
 *
 * Adds a settings page under Settings → FCM Reels to configure
 * the reels page, login requirement, and other preferences.
 */
class FCM_Reels_Admin {

    /**
     * Register admin hooks.
     *
     * @return void
     */
    public function register() {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        
        // Media Library Columns
        add_filter( 'manage_media_columns', [ $this, 'add_media_columns' ] );
        add_action( 'manage_media_custom_column', [ $this, 'display_media_column_content' ], 10, 2 );
    }

    /**
     * Add the settings page to the WordPress admin menu.
     *
     * @return void
     */
    /**
     * Add the settings page to the WordPress admin menu.
     *
     * @return void
     */
    public function add_menu() {
        add_menu_page(
            'Orbit Settings',
            'Orbit',
            'manage_options',
            'fcm-reels',
            [ $this, 'render_settings_page' ],
            FCM_REELS_URL . 'assets/img/orbit-icon.png',
            30
        );
    }

    /**
     * Enqueue admin-specific assets.
     *
     * @param string $hook The current admin page hook.
     * @return void
     */
    public function enqueue_assets( $hook ) {
        wp_enqueue_style(
            'fcm-reels-admin-css',
            FCM_REELS_URL . 'assets/css/admin.css',
            [],
            FCM_REELS_VERSION
        );
    }

    /**
     * Register plugin settings with the WordPress Settings API.
     *
     * @return void
     */
    public function register_settings() {
        register_setting( 'fcm_reels_options', 'fcm_reels_page_id' );
        register_setting( 'fcm_reels_options', 'fcm_reels_require_login' );
    }

    /**
     * Render the settings page.
     *
     * @return void
     */

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        // Save settings if posted
        if ( isset( $_POST['fcm_reels_save_settings'] ) ) {
            check_admin_referer( 'fcm_reels_settings' );
            
            update_option( 'fcm_reels_page_id', (int) $_POST['reels_page_id'] );
            update_option( 'fcm_reels_portal_url_manual', sanitize_text_field( $_POST['portal_url_manual'] ) );
            update_option( 'fcm_reels_require_login', sanitize_text_field( $_POST['require_login'] ) );
            
            // Cloudflare Stream Settings
            update_option( 'fcm_reels_cf_account_id', sanitize_text_field( $_POST['cf_account_id'] ) );
            update_option( 'fcm_reels_cf_subdomain', sanitize_text_field( $_POST['cf_subdomain'] ) );
            update_option( 'fcm_reels_cf_api_token', sanitize_text_field( $_POST['cf_api_token'] ) );
            update_option( 'fcm_reels_cf_sync_enabled', isset( $_POST['cf_sync_enabled'] ) ? 'yes' : 'no' );

            echo '<div class="fcm-orbit-notice"><span class="dashicons dashicons-saved" style="color: #2ecc71; font-size: 24px; width: 24px; height: 24px;"></span> <p>Settings saved successfully! Your Orbits are ready.</p></div>';
        }

        // Handle Purge Cache
        if ( isset( $_POST['fcm_reels_purge_cache'] ) ) {
            check_admin_referer( 'fcm_reels_settings' );
            $this->purge_discovery_cache();
            echo '<div class="fcm-orbit-notice" style="border-left-color: #e67e22;"><span class="dashicons dashicons-trash" style="color: #e67e22; font-size: 24px; width: 24px; height: 24px;"></span> <p>Discovery cache purged! Next feed load will be fresh.</p></div>';
        }

        // Handle Manual Aggregation
        if ( isset( $_POST['fcm_reels_trigger_aggregation'] ) ) {
            check_admin_referer( 'fcm_reels_settings' );
            FCM_Reels_DB::aggregate_metrics();
            echo '<div class="fcm-orbit-notice"><span class="dashicons dashicons-update" style="color: #2ecc71; font-size: 24px; width: 24px; height: 24px;"></span> <p>Analytics metrics updated successfully!</p></div>';
        }

        // Handle Manual Migration
        if ( isset( $_POST['fcm_reels_trigger_migration'] ) ) {
            check_admin_referer( 'fcm_reels_settings' );
            FCM_Reels_DB::init_tables();
            echo '<div class="fcm-orbit-notice"><span class="dashicons dashicons-database-add" style="color: #2ecc71; font-size: 24px; width: 24px; height: 24px;"></span> <p>Database tables synchronized successfully!</p></div>';
        }

        // Handle Sync Views
        if ( isset( $_POST['fcm_reels_sync_views'] ) ) {
            check_admin_referer( 'fcm_reels_settings' );
            $processed = FCM_Reels_DB::sync_views_from_likes();
            echo '<div class="fcm-orbit-notice"><span class="dashicons dashicons-visibility" style="color: #3498db; font-size: 24px; width: 24px; height: 24px;"></span> <p>Successfully synced views for ' . (int) $processed . ' videos based on likes!</p></div>';
        }

        $page_id         = (int) get_option( 'fcm_reels_page_id' );
        $require_login   = get_option( 'fcm_reels_require_login', 'no' );
        $cf_account_id   = get_option( 'fcm_reels_cf_account_id' );
        $cf_api_token    = get_option( 'fcm_reels_cf_api_token' );
        $cf_sync_enabled = get_option( 'fcm_reels_cf_sync_enabled', 'no' );

        $pages = get_pages();
        $active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'settings';
        ?>
        <style>
            .fcm-orbit-tab {
                text-decoration: none;
                color: var(--orbit-text-muted);
                padding: 8px 16px;
                border-radius: 20px;
                font-size: 14px;
                font-weight: 500;
                transition: var(--orbit-transition);
            }
            .fcm-orbit-tab.active {
                background: var(--orbit-green);
                color: white;
            }
            .fcm-orbit-tag {
                background: var(--orbit-green-light);
                color: var(--orbit-green-dark);
                padding: 4px 10px;
                border-radius: 12px;
                font-weight: 600;
                font-size: 12px;
            }
        </style>
        <div class="fcm-orbit-admin-wrap">
            <header class="fcm-orbit-header">
                <h1><span>Orbit</span> Settings</h1>
                <div style="display: flex; align-items: center; gap: 20px;">
                    <nav class="fcm-orbit-tabs" style="display: flex; gap: 10px; margin-right: 20px;">
                        <a href="<?php echo admin_url('admin.php?page=fcm-reels&tab=settings'); ?>" class="fcm-orbit-tab <?php echo $active_tab === 'settings' ? 'active' : ''; ?>">Settings</a>
                        <a href="<?php echo admin_url('admin.php?page=fcm-reels&tab=analytics'); ?>" class="fcm-orbit-tab <?php echo $active_tab === 'analytics' ? 'active' : ''; ?>">Analytics</a>
                    </nav>
                    <form method="post" action="" style="margin: 0; display: flex; gap: 10px;">
                        <?php wp_nonce_field( 'fcm_reels_settings' ); ?>
                        <button type="submit" name="fcm_reels_sync_views" class="fcm-orbit-button" style="background: transparent; color: var(--orbit-text-muted); box-shadow: none; border: 1px solid var(--orbit-border); padding: 8px 15px; font-size: 13px;" title="Bootstrap Views (Likes x 1.5)">
                            <span class="dashicons dashicons-visibility" style="font-size: 16px; width: 16px; height: 16px;"></span>
                            Sync Views
                        </button>
                        <button type="submit" name="fcm_reels_purge_cache" class="fcm-orbit-button" style="background: transparent; color: var(--orbit-text-muted); box-shadow: none; border: 1px solid var(--orbit-border); padding: 8px 15px; font-size: 13px;">
                            <span class="dashicons dashicons-trash" style="font-size: 16px; width: 16px; height: 16px;"></span>
                            Purge Cache
                        </button>
                    </form>
                    <div class="fcm-orbit-version">v<?php echo FCM_REELS_VERSION; ?></div>
                </div>
            </header>

            <?php if ( $active_tab === 'analytics' ) : ?>
                <?php $this->render_analytics_tab(); ?>
            <?php else : ?>
            
            <form method="post" action="">
                <?php wp_nonce_field( 'fcm_reels_settings' ); ?>
                
                <section class="fcm-orbit-card">
                    <h2><span class="dashicons dashicons-admin-generic"></span> General Configuration</h2>
                    
                    <div class="fcm-orbit-form-row">
                        <label class="fcm-orbit-label">Orbits Page</label>
                        <div class="fcm-orbit-input-wrapper">
                            <select name="reels_page_id" class="fcm-orbit-input">
                                <option value="0">-- Select Page --</option>
                                <?php foreach ( $pages as $page ) : ?>
                                    <option value="<?php echo $page->ID; ?>" <?php selected( $page_id, $page->ID ); ?>>
                                        <?php echo esc_html( $page->post_title ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <p class="fcm-orbit-description">Select the page that contains the <code>[fcm_reels]</code> shortcode.</p>
                    </div>

                    <div class="fcm-orbit-form-row">
                        <label class="fcm-orbit-label">Community Portal URL</label>
                        <div class="fcm-orbit-input-wrapper">
                            <input type="text" name="portal_url_manual" value="<?php echo esc_attr( get_option( 'fcm_reels_portal_url_manual' ) ); ?>" class="fcm-orbit-input" placeholder="e.g. /social or /home">
                        </div>
                        <p class="fcm-orbit-description">The URL of your FluentCommunity portal (e.g. <code>/social</code> on live, <code>/home</code> on local).</p>
                    </div>

                    <div class="fcm-orbit-form-row">
                        <label class="fcm-orbit-label">Privacy Control</label>
                        <div class="fcm-orbit-input-wrapper">
                            <select name="require_login" class="fcm-orbit-input">
                                <option value="no" <?php selected( $require_login, 'no' ); ?>>Public (Anyone can view)</option>
                                <option value="yes" <?php selected( $require_login, 'yes' ); ?>>Private (Logged-in members only)</option>
                            </select>
                        </div>
                        <p class="fcm-orbit-description">Control who can access the video feed page.</p>
                    </div>
                </section>

                <section class="fcm-orbit-card">
                    <h2><span class="dashicons dashicons-cloud"></span> Cloudflare Stream Integration</h2>
                    <p class="fcm-orbit-description" style="margin-bottom: 25px;">Enable adaptive bitrate streaming and global CDN delivery for premium mobile performance.</p>
                    
                    <div class="fcm-orbit-form-row">
                        <label class="fcm-orbit-label">Account ID</label>
                        <div class="fcm-orbit-input-wrapper">
                            <input type="text" name="cf_account_id" value="<?php echo esc_attr( $cf_account_id ); ?>" class="fcm-orbit-input" placeholder="e.g. f33zs165nr7gyyc4...">
                        </div>
                    </div>

                    <div class="fcm-orbit-form-row">
                        <label class="fcm-orbit-label">Customer Subdomain</label>
                        <div class="fcm-orbit-input-wrapper">
                            <input type="text" name="cf_subdomain" value="<?php echo esc_attr( get_option( 'fcm_reels_cf_subdomain' ) ); ?>" class="fcm-orbit-input" placeholder="e.g. customer-f33zs165nr7gyyc4">
                        </div>
                        <p class="fcm-orbit-description">Your unique Cloudflare Stream subdomain.</p>
                    </div>

                    <div class="fcm-orbit-form-row">
                        <label class="fcm-orbit-label">API Token</label>
                        <div class="fcm-orbit-input-wrapper">
                            <input type="password" name="cf_api_token" value="<?php echo esc_attr( $cf_api_token ); ?>" class="fcm-orbit-input">
                        </div>
                        <p class="fcm-orbit-description">Requires <strong>Account: Stream: Edit</strong> permissions.</p>
                    </div>

                    <div class="fcm-orbit-form-row">
                        <label class="fcm-orbit-label" style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                            <input type="checkbox" name="cf_sync_enabled" value="1" <?php checked( $cf_sync_enabled, 'yes' ); ?> style="width: 18px; height: 18px; margin: 0; cursor: pointer;">
                            Auto-Sync Videos
                        </label>
                        <p class="fcm-orbit-description">Automatically process new video uploads via Cloudflare Stream.</p>
                    </div>
                </section>

                <div class="fcm-orbit-submit-wrap">
                    <button type="submit" name="fcm_reels_save_settings" class="fcm-orbit-button">
                        <span class="dashicons dashicons-cloud-upload"></span>
                        Save All Changes
                    </button>
                </div>
            </form>

            <section class="fcm-orbit-card" style="background: linear-gradient(135deg, #ffffff 0%, #f8fdfa 100%); border-left: 4px solid var(--orbit-green);">
                <h2><span class="dashicons dashicons-info"></span> About Orbit</h2>
                <p><strong>Orbit</strong> is a premium video discovery engine designed specifically for FluentCommunity. It transforms your community into a dynamic video-first social space with high-performance streaming and smart ranking.</p>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px;">
                    <div>
                        <h4 style="margin: 0 0 10px 0; font-weight: 600; color: var(--orbit-green-dark);">Smart Discovery</h4>
                        <p class="fcm-orbit-description">Powered by the <em>Stochastic Waterfall</em> algorithm, Orbit balances fresh content, community engagement, and random variety to keep users hooked.</p>
                    </div>
                    <div>
                        <h4 style="margin: 0 0 10px 0; font-weight: 600; color: var(--orbit-green-dark);">Global Delivery</h4>
                        <p class="fcm-orbit-description">Deep integration with Cloudflare Stream ensures your videos load instantly worldwide with adaptive bitrate streaming (HLS/DASH).</p>
                    </div>
                </div>
            </section>

            <section class="fcm-orbit-table-wrap">
                <header style="margin-bottom: 20px;">
                    <h3 style="margin: 0; font-size: 18px; font-weight: 600;">Developer Reference</h3>
                    <p class="fcm-orbit-description">API endpoints available for custom integrations.</p>
                </header>
                <table class="fcm-orbit-table">
                    <thead>
                        <tr>
                            <th>Endpoint</th>
                            <th>Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>/fcm-reels/v1/feed</code></td>
                            <td>Main video feed (supports pagination).</td>
                        </tr>
                        <tr>
                            <td><code>/fcm-reels/v1/spaces</code></td>
                            <td>List of communities with video content.</td>
                        </tr>
                    </tbody>
                </table>
            </section>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render the Analytics dashboard tab.
     */
    private function render_analytics_tab() {
        global $wpdb;
        $table_metrics = FCM_Reels_DB::get_metrics_table();
        $posts_tbl = $wpdb->prefix . 'fcom_posts';

        // Summary Stats
        $total_views = $wpdb->get_var( "SELECT SUM(total_views) FROM $table_metrics" ) ?: 0;
        $total_likes = $wpdb->get_var( "SELECT SUM(total_likes) FROM $table_metrics" ) ?: 0;
        $total_comments = $wpdb->get_var( "SELECT SUM(total_comments) FROM $table_metrics" ) ?: 0;
        $avg_vtr = $wpdb->get_var( "SELECT AVG(vtr) FROM $table_metrics WHERE total_views > 0" ) ?: 0;
        $avg_completion = $wpdb->get_var( "SELECT AVG(completion_rate) FROM $table_metrics WHERE total_views > 0" ) ?: 0;
        
        $archive_tbl = $wpdb->prefix . 'fcom_media_archive';
        $total_videos = $wpdb->get_var( "
            SELECT COUNT(DISTINCT p.id) 
            FROM $posts_tbl p 
            INNER JOIN $archive_tbl ma ON ma.feed_id = p.id 
            WHERE p.status = 'published' 
            AND ma.is_active = 1 
            AND (ma.media_type = 'fluent_player' OR ma.media_type LIKE 'video/%' OR ma.media_type = 'video')
        " ) ?: 0;

        // Top Videos
        $top_videos = $wpdb->get_results( "
            SELECT m.*, p.title 
            FROM $table_metrics m 
            JOIN $posts_tbl p ON p.id = m.video_id 
            ORDER BY m.engagement_score DESC LIMIT 5
        " );

        ?>
        <div class="fcm-orbit-subnav" style="background: #fff; padding: 10px 20px; border-radius: 12px; margin-bottom: 25px; border: 1px solid var(--orbit-border); display: flex; gap: 20px; align-items: center; position: sticky; top: 32px; z-index: 99;">
            <span style="font-size: 12px; font-weight: 600; color: var(--orbit-text-muted); text-transform: uppercase;">Jump to:</span>
            <a href="#overview" class="fcm-orbit-nav-link">Overview</a>
            <a href="#top-orbits" class="fcm-orbit-nav-link">Top Orbits</a>
            <a href="#space-intel" class="fcm-orbit-nav-link">Space Intelligence</a>
            <a href="#dev-tools" class="fcm-orbit-nav-link">Developer Tools</a>
        </div>

        <style>
            .fcm-orbit-nav-link {
                text-decoration: none;
                color: var(--orbit-text);
                font-size: 13px;
                font-weight: 500;
                transition: var(--orbit-transition);
            }
            .fcm-orbit-nav-link:hover {
                color: var(--orbit-green);
            }
        </style>

        <div id="overview" class="fcm-analytics-grid" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 40px; scroll-margin-top: 100px;">
            <div class="fcm-orbit-card" style="margin-bottom: 0; text-align: center; background: var(--orbit-green-light); border: none;">
                <div style="font-size: 11px; color: var(--orbit-green-dark); text-transform: uppercase; letter-spacing: 1px;">Total Videos</div>
                <div style="font-size: 32px; font-weight: 700; margin-top: 10px; color: var(--orbit-green-dark);"><?php echo number_format($total_videos); ?></div>
            </div>
            <div class="fcm-orbit-card" style="margin-bottom: 0; text-align: center;">
                <div style="font-size: 11px; color: var(--orbit-text-muted); text-transform: uppercase; letter-spacing: 1px;">Total Views</div>
                <div style="font-size: 32px; font-weight: 700; margin-top: 10px; color: var(--orbit-green);"><?php echo number_format($total_views); ?></div>
            </div>
            <div class="fcm-orbit-card" style="margin-bottom: 0; text-align: center;">
                <div style="font-size: 11px; color: var(--orbit-text-muted); text-transform: uppercase; letter-spacing: 1px;">Total Likes</div>
                <div style="font-size: 32px; font-weight: 700; margin-top: 10px; color: #e74c3c;"><?php echo number_format($total_likes); ?></div>
            </div>
            <div class="fcm-orbit-card" style="margin-bottom: 0; text-align: center;">
                <div style="font-size: 11px; color: var(--orbit-text-muted); text-transform: uppercase; letter-spacing: 1px;">Total Comments</div>
                <div style="font-size: 32px; font-weight: 700; margin-top: 10px; color: #9b59b6;"><?php echo number_format($total_comments); ?></div>
            </div>
            <div class="fcm-orbit-card" style="margin-bottom: 0; text-align: center;">
                <div style="font-size: 11px; color: var(--orbit-text-muted); text-transform: uppercase; letter-spacing: 1px;">Avg. VTR</div>
                <div style="font-size: 32px; font-weight: 700; margin-top: 10px; color: #3498db;"><?php echo round($avg_vtr * 100, 1); ?>%</div>
            </div>
            <div class="fcm-orbit-card" style="margin-bottom: 0; text-align: center;">
                <div style="font-size: 11px; color: var(--orbit-text-muted); text-transform: uppercase; letter-spacing: 1px;">Avg. Completion</div>
                <div style="font-size: 32px; font-weight: 700; margin-top: 10px; color: #e67e22;"><?php echo round($avg_completion, 1); ?>%</div>
            </div>
        </div>

        <section id="top-orbits" class="fcm-orbit-card" style="scroll-margin-top: 100px;">
            <h2><span class="dashicons dashicons-chart-line"></span> Top Performing Orbits</h2>
            <table class="fcm-orbit-table">
                <thead>
                    <tr>
                        <th>Video Title</th>
                        <th>Views</th>
                        <th>VTR</th>
                        <th>Completion</th>
                        <th>Algorithm Score <span style="font-size: 10px; font-weight: normal; color: #888; display: block;">(Likes x2 + Comments x5)</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty($top_videos) ) : ?>
                        <tr><td colspan="5" style="text-align: center; padding: 40px;">No data yet. Keep watching reels!</td></tr>
                    <?php else : ?>
                        <?php foreach ( $top_videos as $v ) : ?>
                            <tr>
                                <td><strong><?php echo esc_html($v->title ?: 'Video #' . $v->video_id); ?></strong></td>
                                <td><?php echo number_format($v->total_views); ?></td>
                                <td><?php echo round($v->vtr * 100, 1); ?>%</td>
                                <td><?php echo round($v->completion_rate, 1); ?>%</td>
                                <td><span class="fcm-orbit-tag"><?php echo round($v->engagement_score); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>

        <?php
        // Space Intelligence Data
        $spaces_tbl = $wpdb->prefix . 'fcom_spaces';
        $space_metrics = $wpdb->get_results( "
            SELECT s.title, COUNT(m.video_id) as video_count, SUM(m.total_views) as total_views, AVG(m.vtr) as avg_vtr, SUM(m.engagement_score) as total_engagement
            FROM $table_metrics m
            JOIN $posts_tbl p ON p.id = m.video_id
            JOIN $spaces_tbl s ON s.id = p.space_id
            GROUP BY s.id
            ORDER BY total_views DESC
        " );
        ?>

        <section id="space-intel" class="fcm-orbit-card" style="scroll-margin-top: 100px;">
            <h2><span class="dashicons dashicons-groups"></span> Space Intelligence</h2>
            <p class="fcm-orbit-description">Performance breakdown by Community Spaces.</p>
            <table class="fcm-orbit-table" style="margin-top: 20px;">
                <thead>
                    <tr>
                        <th>Space Name</th>
                        <th>Orbits</th>
                        <th>Total Views</th>
                        <th>Avg. VTR</th>
                        <th>Algorithm Score <span style="font-size: 10px; font-weight: normal; color: #888; display: block;">(Likes x2 + Comments x5)</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty($space_metrics) ) : ?>
                        <tr><td colspan="5" style="text-align: center; padding: 40px;">No space data tracked yet.</td></tr>
                    <?php else : ?>
                        <?php foreach ( $space_metrics as $s ) : ?>
                            <tr>
                                <td><strong># <?php echo esc_html($s->title); ?></strong></td>
                                <td><?php echo number_format($s->video_count); ?></td>
                                <td><?php echo number_format($s->total_views); ?></td>
                                <td><?php echo round($s->avg_vtr * 100, 1); ?>%</td>
                                <td><span class="fcm-orbit-tag" style="background: #efecf9; color: #6e56cf;"><?php echo number_format($s->total_engagement); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>

        <section id="dev-tools" class="fcm-orbit-card" style="background: #fff; border: 1px dashed var(--orbit-border); scroll-margin-top: 100px;">
            <div style="display: flex; align-items: center; justify-content: space-between;">
                <div>
                    <h4 style="margin: 0; font-size: 15px; font-weight: 600;">Developer Tools</h4>
                    <p class="fcm-orbit-description" style="margin: 5px 0 0 0;">Manually run the analytics engine to update metrics immediately.</p>
                </div>
                <form method="post" action="">
                    <?php wp_nonce_field( 'fcm_reels_settings' ); ?>
                    <button type="submit" name="fcm_reels_trigger_aggregation" class="fcm-orbit-button" style="background: var(--orbit-text); padding: 10px 20px; font-size: 14px;">
                        <span class="dashicons dashicons-update" style="font-size: 16px; width: 16px; height: 16px;"></span>
                        Update Metrics Now
                    </button>
                </form>
            </div>
            <div style="display: flex; align-items: center; justify-content: space-between; margin-top: 20px; padding-top: 20px; border-top: 1px solid var(--orbit-border);">
                <div>
                    <h4 style="margin: 0; font-size: 15px; font-weight: 600;">Database Migration</h4>
                    <p class="fcm-orbit-description" style="margin: 5px 0 0 0;">Synchronize table columns and ensure the latest schema is applied.</p>
                </div>
                <form method="post" action="">
                    <?php wp_nonce_field( 'fcm_reels_settings' ); ?>
                    <button type="submit" name="fcm_reels_trigger_migration" class="fcm-orbit-button" style="background: transparent; color: var(--orbit-text-muted); border: 1px solid var(--orbit-border); box-shadow: none; padding: 10px 20px; font-size: 14px;">
                        <span class="dashicons dashicons-database-add" style="font-size: 16px; width: 16px; height: 16px;"></span>
                        Update Schema
                    </button>
                </form>
            </div>
        </section>

        <section class="fcm-orbit-card" style="background: var(--orbit-green-light); border: none;">
            <p style="margin: 0; font-size: 13px; color: var(--orbit-green-dark); display: flex; align-items: center; gap: 10px;">
                <span class="dashicons dashicons-info" style="font-size: 18px; width: 18px; height: 18px;"></span>
                Metrics are aggregated hourly. To see real-time changes, you can manually trigger aggregation from the developer tools or wait for the next cron cycle.
            </p>
        </section>
        <?php
    }

    /**
     * Add Cloudflare ID column to Media Library.
     */
    public function add_media_columns( $columns ) {
        $columns['fcm_reels_cf'] = 'Cloudflare Stream';
        return $columns;
    }

    /**
     * Display Cloudflare ID in Media Library.
     */
    public function display_media_column_content( $column_name, $post_id ) {
        if ( 'fcm_reels_cf' === $column_name ) {
            $cf_id = get_post_meta( $post_id, '_fcm_reels_cf_uid', true );
            if ( $cf_id ) {
                echo '<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span> <code>' . esc_html( $cf_id ) . '</code>';
            } else {
                echo '<span class="description">Pending / Local Only</span>';
            }
        }
    }

    /**
     * Purge all video discovery pool transients from the database.
     *
     * @return void
     */
    private function purge_discovery_cache() {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_fcm_reels_pool_%' OR option_name LIKE '_transient_timeout_fcm_reels_pool_%'" );
    }
}
