<?php

namespace ImgPress;

defined('ABSPATH') || exit;

/**
 * Admin bar quick actions: purge current page, purge all pages, preload cache.
 * Visible on the front end and in the admin for users with manage_options.
 */
class Admin_Bar
{
    public function __construct(private Page_Cache $pageCache)
    {
    }

    public function init(): void
    {
        add_action('admin_bar_menu', [$this, 'addTools'], 100);
        add_action('admin_post_imgpress_purge_current_url', [$this, 'handlePurgeCurrentUrl']);
    }

    public function addTools(\WP_Admin_Bar $bar): void
    {
        if (!current_user_can('manage_options') || is_admin() && wp_doing_ajax()) {
            return;
        }

        $bar->add_node([
            'id' => 'imgpress',
            'title' => '<span class="ab-icon dashicons dashicons-performance"></span><span class="ab-label">ImgPress</span>',
            'href' => admin_url('admin.php?page=imgpress'),
        ]);

        $nodes = [];

        if (!is_admin()) {
            $currentUrl = $this->currentUrl();
            if ($currentUrl !== '') {
                $nodes['imgpress-purge-current'] = [
                    'title' => __('Purge this page', 'imgpress-wp'),
                    'href' => wp_nonce_url(
                        admin_url('admin-post.php?action=imgpress_purge_current_url&url=' . rawurlencode($currentUrl)),
                        'imgpress_purge_current_url'
                    ),
                ];
            }
        }

        $nodes['imgpress-purge-all'] = [
            'title' => __('Purge all cache', 'imgpress-wp'),
            'href' => wp_nonce_url(admin_url('admin-post.php?action=imgpress_purge_cache'), 'imgpress_purge_cache'),
        ];

        $nodes['imgpress-preload'] = [
            'title' => __('Preload cache', 'imgpress-wp'),
            'href' => wp_nonce_url(admin_url('admin-post.php?action=imgpress_preload_cache'), 'imgpress_preload_cache'),
        ];

        foreach ($nodes as $id => $node) {
            $bar->add_node(array_merge(['id' => $id, 'parent' => 'imgpress', 'meta' => ['class' => 'imgpress-admin-bar-item']], $node));
        }
    }

    public function handlePurgeCurrentUrl(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized', 'imgpress-wp'), 403);
        }

        check_admin_referer('imgpress_purge_current_url');

        $url = esc_url_raw((string) ($_GET['url'] ?? ''));
        if ($url !== '') {
            $this->pageCache->purgeUrl($url);
        }

        $redirect = wp_get_referer() ?: admin_url('admin.php?page=imgpress');
        wp_safe_redirect($redirect);
        exit;
    }

    private function currentUrl(): string
    {
        $scheme = is_ssl() ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');

        return $host !== '' ? $scheme . '://' . $host . $uri : home_url($uri);
    }
}
