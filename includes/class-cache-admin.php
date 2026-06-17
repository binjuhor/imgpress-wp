<?php

namespace ImgPress;

defined('ABSPATH') || exit;

class Cache_Admin
{
    private Cache_Manager $cache_manager;
    private Cache_Invalidation $invalidation;

    public function __construct(Cache_Manager $cache_manager, Cache_Invalidation $invalidation)
    {
        $this->cache_manager = $cache_manager;
        $this->invalidation = $invalidation;

        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_imgpress_cache_clear', [$this, 'handle_clear_cache']);
        add_action('wp_ajax_imgpress_cache_stats', [$this, 'handle_cache_stats']);
        add_action('admin_notices', [$this, 'show_cache_notice']);
    }

    public function enqueue_assets(string $hook): void
    {
        if (strpos($hook, 'imgpress') === false) {
            return;
        }

        wp_enqueue_script(
            'imgpress-cache',
            IMGPRESS_WP_URL . 'assets/js/cache-admin.js',
            ['jquery'],
            IMGPRESS_WP_VERSION,
            true
        );

        wp_localize_script('imgpress-cache', 'ImgPressCacheAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('imgpress_cache'),
        ]);
    }

    public function render_cache_settings(array $opts): void
    {
        ?>
        <div class="imgpress-card">
            <h2 class="imgpress-card-title">
                <span class="dashicons dashicons-performance"></span>
                <?php esc_html_e('Cache System', 'imgpress-wp'); ?>
            </h2>

            <table class="form-table" role="presentation">
                <tr>
                    <td style="padding: 12px 0">
                        <label class="imgpress-checkbox">
                            <input
                                type="checkbox"
                                name="imgpress_wp_options[cache_enabled]"
                                value="1"
                                <?php checked(!empty($opts['cache_enabled'])); ?>
                            />
                            <span class="checkbox-label">
                                <strong><?php esc_html_e('Enable Page Caching', 'imgpress-wp'); ?></strong>
                                <span class="description"><?php esc_html_e('Cache HTML pages for faster delivery', 'imgpress-wp'); ?></span>
                            </span>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="cache_ttl"><?php esc_html_e('Cache TTL', 'imgpress-wp'); ?></label>
                    </th>
                    <td>
                        <input
                            type="number"
                            id="cache_ttl"
                            name="imgpress_wp_options[cache_ttl]"
                            value="<?php echo esc_attr($opts['cache_ttl'] ?? 0); ?>"
                            min="0"
                            step="3600"
                        />
                        <span class="description">
                            <?php esc_html_e('Cache time-to-live in seconds (0 = until cleared)', 'imgpress-wp'); ?>
                        </span>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 12px 0">
                        <label class="imgpress-checkbox">
                            <input
                                type="checkbox"
                                name="imgpress_wp_options[cache_gzip]"
                                value="1"
                                <?php checked(!empty($opts['cache_gzip'])); ?>
                            />
                            <span class="checkbox-label">
                                <strong><?php esc_html_e('Gzip Compression', 'imgpress-wp'); ?></strong>
                                <span class="description"><?php esc_html_e('Store gzipped copies for faster delivery', 'imgpress-wp'); ?></span>
                            </span>
                        </label>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 12px 0">
                        <label class="imgpress-checkbox">
                            <input
                                type="checkbox"
                                name="imgpress_wp_options[cache_mobile]"
                                value="1"
                                <?php checked(!empty($opts['cache_mobile'])); ?>
                            />
                            <span class="checkbox-label">
                                <strong><?php esc_html_e('Mobile Cache', 'imgpress-wp'); ?></strong>
                                <span class="description"><?php esc_html_e('Separate cache for mobile devices', 'imgpress-wp'); ?></span>
                            </span>
                        </label>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 12px 0">
                        <label class="imgpress-checkbox">
                            <input
                                type="checkbox"
                                name="imgpress_wp_options[cache_logged_in]"
                                value="1"
                                <?php checked(!empty($opts['cache_logged_in'])); ?>
                            />
                            <span class="checkbox-label">
                                <strong><?php esc_html_e('Cache for Logged-in Users', 'imgpress-wp'); ?></strong>
                                <span class="description"><?php esc_html_e('⚠️ Only enable if you know what you\'re doing', 'imgpress-wp'); ?></span>
                            </span>
                        </label>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 12px 0">
                        <label class="imgpress-checkbox">
                            <input
                                type="checkbox"
                                name="imgpress_wp_options[cache_clear_on_new]"
                                value="1"
                                <?php checked(!empty($opts['cache_clear_on_new'])); ?>
                            />
                            <span class="checkbox-label">
                                <strong><?php esc_html_e('Clear Cache on New Post', 'imgpress-wp'); ?></strong>
                                <span class="description"><?php esc_html_e('Auto-clear when new posts are published', 'imgpress-wp'); ?></span>
                            </span>
                        </label>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 12px 0">
                        <label class="imgpress-checkbox">
                            <input
                                type="checkbox"
                                name="imgpress_wp_options[cache_clear_on_update]"
                                value="1"
                                <?php checked(!empty($opts['cache_clear_on_update'])); ?>
                            />
                            <span class="checkbox-label">
                                <strong><?php esc_html_e('Clear Cache on Post Update', 'imgpress-wp'); ?></strong>
                                <span class="description"><?php esc_html_e('Auto-clear when posts are updated', 'imgpress-wp'); ?></span>
                            </span>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="cache_size_limit"><?php esc_html_e('Cache Size Limit', 'imgpress-wp'); ?></label>
                    </th>
                    <td>
                        <input
                            type="number"
                            id="cache_size_limit"
                            name="imgpress_wp_options[cache_size_limit]"
                            value="<?php echo esc_attr($opts['cache_size_limit'] ?? 524288000); ?>"
                            min="10485760"
                            step="10485760"
                        />
                        <span class="description">
                            <?php esc_html_e('Maximum cache size in bytes (default: 500MB)', 'imgpress-wp'); ?>
                        </span>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 12px 0">
                        <label class="imgpress-checkbox">
                            <input
                                type="checkbox"
                                name="imgpress_wp_options[cache_log_enabled]"
                                value="1"
                                <?php checked(!empty($opts['cache_log_enabled'])); ?>
                            />
                            <span class="checkbox-label">
                                <strong><?php esc_html_e('Enable Invalidation Log', 'imgpress-wp'); ?></strong>
                                <span class="description"><?php esc_html_e('Log cache invalidation events for debugging', 'imgpress-wp'); ?></span>
                            </span>
                        </label>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 12px 0">
                        <label class="imgpress-checkbox">
                            <input
                                type="checkbox"
                                name="imgpress_wp_options[cache_etag_enabled]"
                                value="1"
                                <?php checked(!empty($opts['cache_etag_enabled'] ?? true)); ?>
                            />
                            <span class="checkbox-label">
                                <strong><?php esc_html_e('Enable ETag Headers', 'imgpress-wp'); ?></strong>
                                <span class="description"><?php esc_html_e('Send ETag headers for browser revalidation', 'imgpress-wp'); ?></span>
                            </span>
                        </label>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 12px 0">
                        <label class="imgpress-checkbox">
                            <input
                                type="checkbox"
                                name="imgpress_wp_options[cache_last_modified]"
                                value="1"
                                <?php checked(!empty($opts['cache_last_modified'] ?? true)); ?>
                            />
                            <span class="checkbox-label">
                                <strong><?php esc_html_e('Enable Last-Modified Headers', 'imgpress-wp'); ?></strong>
                                <span class="description"><?php esc_html_e('Send Last-Modified headers for browser revalidation', 'imgpress-wp'); ?></span>
                            </span>
                        </label>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 12px 0">
                        <label class="imgpress-checkbox">
                            <input
                                type="checkbox"
                                name="imgpress_wp_options[cache_minify_enabled]"
                                value="1"
                                <?php checked(!empty($opts['cache_minify_enabled'] ?? true)); ?>
                            />
                            <span class="checkbox-label">
                                <strong><?php esc_html_e('Enable HTML/CSS/JS Minification', 'imgpress-wp'); ?></strong>
                                <span class="description"><?php esc_html_e('Minify HTML and inline CSS/JS before caching', 'imgpress-wp'); ?></span>
                            </span>
                        </label>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 12px 0">
                        <label class="imgpress-checkbox">
                            <input
                                type="checkbox"
                                name="imgpress_wp_options[cache_js_defer_enabled]"
                                value="1"
                                <?php checked(!empty($opts['cache_js_defer_enabled'] ?? true)); ?>
                            />
                            <span class="checkbox-label">
                                <strong><?php esc_html_e('Defer JavaScript', 'imgpress-wp'); ?></strong>
                                <span class="description"><?php esc_html_e('Add defer attribute to all scripts for faster page load', 'imgpress-wp'); ?></span>
                            </span>
                        </label>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 12px 0">
                        <label class="imgpress-checkbox">
                            <input
                                type="checkbox"
                                name="imgpress_wp_options[cache_js_lazy_enabled]"
                                value="1"
                                <?php checked(!empty($opts['cache_js_lazy_enabled'])); ?>
                            />
                            <span class="checkbox-label">
                                <strong><?php esc_html_e('Lazy Load JavaScript', 'imgpress-wp'); ?></strong>
                                <span class="description"><?php esc_html_e('Lazy load non-critical scripts (experimental)', 'imgpress-wp'); ?></span>
                            </span>
                        </label>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 12px 0">
                        <label class="imgpress-checkbox">
                            <input
                                type="checkbox"
                                name="imgpress_wp_options[cache_image_lazy_enabled]"
                                value="1"
                                <?php checked(!empty($opts['cache_image_lazy_enabled'] ?? true)); ?>
                            />
                            <span class="checkbox-label">
                                <strong><?php esc_html_e('Lazy Load Images', 'imgpress-wp'); ?></strong>
                                <span class="description"><?php esc_html_e('Add lazy loading to images (skip above-fold)', 'imgpress-wp'); ?></span>
                            </span>
                        </label>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 12px 0">
                        <label class="imgpress-checkbox">
                            <input
                                type="checkbox"
                                name="imgpress_wp_options[cache_preload_enabled]"
                                value="1"
                                <?php checked(!empty($opts['cache_preload_enabled'])); ?>
                            />
                            <span class="checkbox-label">
                                <strong><?php esc_html_e('Preload Critical Resources', 'imgpress-wp'); ?></strong>
                                <span class="description"><?php esc_html_e('Add preload hints for critical assets (fonts, images)', 'imgpress-wp'); ?></span>
                            </span>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="cache_cdn_url"><?php esc_html_e('CDN URL', 'imgpress-wp'); ?></label>
                    </th>
                    <td>
                        <input
                            type="url"
                            id="cache_cdn_url"
                            name="imgpress_wp_options[cache_cdn_url]"
                            value="<?php echo esc_attr($opts['cache_cdn_url'] ?? ''); ?>"
                            placeholder="https://cdn.example.com"
                        />
                        <span class="description">
                            <?php esc_html_e('Optional: Rewrite URLs to point to CDN (leave empty to disable)', 'imgpress-wp'); ?>
                        </span>
                    </td>
                </tr>
            </table>

            <div style="margin: 20px 0; padding: 20px; background: #f5f5f5; border-radius: 4px;">
                <h3 style="margin-top: 0"><?php esc_html_e('Cache Management', 'imgpress-wp'); ?></h3>
                <button type="button" id="imgpress-clear-cache-btn" class="button button-secondary">
                    <?php esc_html_e('Clear All Cache', 'imgpress-wp'); ?>
                </button>
                <span id="imgpress-cache-stats" style="margin-left: 20px; font-size: 13px; color: #666;"></span>
            </div>
        </div>
        <?php
    }

    public function handle_clear_cache(): void
    {
        check_ajax_referer('imgpress_cache');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }

        $this->invalidation->purge_all();
        wp_send_json_success(['message' => 'Cache cleared']);
    }

    public function handle_cache_stats(): void
    {
        check_ajax_referer('imgpress_cache');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }

        $stats = $this->cache_manager->get_cache_stats();
        $size_limit = (int) $this->cache_manager->get_option('cache_size_limit', 524288000);

        $stats['size_limit'] = $size_limit;
        $stats['size_limit_human'] = size_format($size_limit);
        $stats['size_percent'] = $size_limit > 0 ? round(($stats['size'] / $size_limit) * 100, 1) : 0;

        wp_send_json_success($stats);
    }

    public function show_cache_notice(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (!$this->cache_manager->is_enabled()) {
            return;
        }

        if (get_transient('imgpress_cache_enabled_notice')) {
            return;
        }

        ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e('ImgPress Page Cache is active and working.', 'imgpress-wp'); ?></p>
        </div>
        <?php

        set_transient('imgpress_cache_enabled_notice', true, DAY_IN_SECONDS);
    }
}
