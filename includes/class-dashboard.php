<?php

namespace ImgPress;

defined('ABSPATH') || exit;

class Dashboard
{
    public function __construct(
        private Settings $settings,
        private Logger $logger,
        private Page_Cache $pageCache,
        private Cache_Compatibility $compatibility,
        private ?Preload $preload = null,
        private ?Asset_Optimizer $assetOptimizer = null
    ) {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_post_imgpress_purge_cache', [$this, 'handlePurgeCache']);
    }

    public function addMenu(): void
    {
        add_menu_page(
            __('ImgPress Dashboard', 'imgpress-wp'),
            __('ImgPress', 'imgpress-wp'),
            'manage_options',
            'imgpress',
            [$this, 'render'],
            'dashicons-performance',
            80
        );

        add_submenu_page(
            'imgpress',
            __('ImgPress Dashboard', 'imgpress-wp'),
            __('Dashboard', 'imgpress-wp'),
            'manage_options',
            'imgpress',
            [$this, 'render']
        );

        remove_submenu_page('imgpress', 'imgpress');
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $cacheEnabled = $this->settings->isCacheEnabled();
        $conflicts = $this->compatibility->activeCachePlugins();
        ?>
        <div class="wrap imgpress-settings-wrap">
            <h1><?php esc_html_e('ImgPress Dashboard', 'imgpress-wp'); ?></h1>
            <div class="imgpress-dashboard-grid">
                <div class="imgpress-card">
                    <h2 class="imgpress-card-title"><span class="dashicons dashicons-performance"></span><?php esc_html_e('Page Cache', 'imgpress-wp'); ?></h2>
                    <p><strong><?php echo esc_html($cacheEnabled ? __('Enabled', 'imgpress-wp') : __('Disabled', 'imgpress-wp')); ?></strong></p>
                    <p><?php echo esc_html(sprintf(__('%d cached pages, %s stored.', 'imgpress-wp'), $this->pageCache->cacheCount(), size_format($this->pageCache->cacheSize()))); ?></p>
                    <p>
                        <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=imgpress-settings')); ?>"><?php esc_html_e('Settings', 'imgpress-wp'); ?></a>
                        <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=imgpress_purge_cache'), 'imgpress_purge_cache')); ?>"><?php esc_html_e('Purge Cache', 'imgpress-wp'); ?></a>
                        <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=imgpress_preload_cache'), 'imgpress_preload_cache')); ?>"><?php esc_html_e('Preload Cache', 'imgpress-wp'); ?></a>
                    </p>
                </div>
                <div class="imgpress-card">
                    <h2 class="imgpress-card-title"><span class="dashicons dashicons-shield"></span><?php esc_html_e('Compatibility', 'imgpress-wp'); ?></h2>
                    <?php if (empty($conflicts)): ?>
                        <p><?php esc_html_e('No other known page cache plugin is active.', 'imgpress-wp'); ?></p>
                    <?php else: ?>
                        <p><?php echo esc_html(sprintf(__('Active cache plugin detected: %s', 'imgpress-wp'), implode(', ', array_values($conflicts)))); ?></p>
                    <?php endif; ?>
                    <p><?php echo esc_html(sprintf(__('advanced-cache.php: %s', 'imgpress-wp'), Cache_Dropin::isInstalled() ? __('Installed by ImgPress', 'imgpress-wp') : __('Not installed', 'imgpress-wp'))); ?></p>
                </div>
                <div class="imgpress-card">
                    <h2 class="imgpress-card-title"><span class="dashicons dashicons-editor-code"></span><?php esc_html_e('Asset Cache', 'imgpress-wp'); ?></h2>
                    <p><?php echo esc_html(sprintf(__('%d optimized assets cached.', 'imgpress-wp'), $this->assetOptimizer ? $this->assetOptimizer->cacheCount() : 0)); ?></p>
                    <p>
                        <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=imgpress_purge_asset_cache'), 'imgpress_purge_asset_cache')); ?>"><?php esc_html_e('Purge Asset Cache', 'imgpress-wp'); ?></a>
                    </p>
                </div>
                <div class="imgpress-card">
                    <h2 class="imgpress-card-title"><span class="dashicons dashicons-list-view"></span><?php esc_html_e('Recent Activity', 'imgpress-wp'); ?></h2>
                    <?php $entries = $this->logger->recent(8); ?>
                    <?php if (empty($entries)): ?>
                        <p><?php esc_html_e('No ImgPress cache activity yet.', 'imgpress-wp'); ?></p>
                    <?php else: ?>
                        <ul class="imgpress-log-list">
                            <?php foreach ($entries as $entry): ?>
                                <li>
                                    <strong><?php echo esc_html($entry['level'] ?? 'info'); ?></strong>
                                    <?php echo esc_html($entry['message'] ?? ''); ?>
                                    <span><?php echo esc_html($entry['time'] ?? ''); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    public function handlePurgeCache(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized', 'imgpress-wp'), 403);
        }

        check_admin_referer('imgpress_purge_cache');
        $this->pageCache->purgeAll();
        wp_safe_redirect(admin_url('admin.php?page=imgpress'));
        exit;
    }
}
