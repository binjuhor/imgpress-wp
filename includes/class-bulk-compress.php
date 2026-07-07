<?php

namespace ImgPress;

defined('ABSPATH') || exit;

class Bulk_Compress
{
    public function __construct(
        private Compressor $compressor,
        private Settings   $settings
    ) {
        add_action('admin_menu',                        [$this, 'addMenuPage']);
        add_action('wp_ajax_imgpress_bulk_get_ids',     [$this, 'handleGetIds']);
        add_action('wp_ajax_imgpress_bulk_compress',    [$this, 'handleCompress']);
        add_action('admin_enqueue_scripts',             [$this, 'enqueueAssets']);
    }

    public function addMenuPage(): void
    {
        add_submenu_page(
            Dashboard::menuSlug(),
            __('ImgPress Bulk Compress', 'imgpress-wp'),
            __('Bulk Compress', 'imgpress-wp'),
            'manage_options',
            'imgpress-bulk',
            fn() => require IMGPRESS_WP_DIR . 'admin/page-bulk.php'
        );
    }

    public function handleGetIds(): void
    {
        check_ajax_referer('imgpress_bulk');

        if (!current_user_can('upload_files')) {
            wp_send_json_error('Unauthorized', 403);
        }

        $ids = $this->getUncompressedIds();
        wp_send_json_success(['ids' => $ids, 'total' => count($ids)]);
    }

    public function handleCompress(): void
    {
        check_ajax_referer('imgpress_bulk');

        if (!current_user_can('upload_files')) {
            wp_send_json_error('Unauthorized', 403);
        }

        $attachmentId = (int) ($_POST['id'] ?? 0);
        if (!$attachmentId) {
            wp_send_json_error('Invalid ID');
        }

        $ok    = $this->compressor->compress($attachmentId);
        $stats = $ok ? $this->compressor->getStats($attachmentId) : null;
        $name  = get_the_title($attachmentId) ?: basename(get_attached_file($attachmentId) ?: '');

        if ($ok && $stats) {
            wp_send_json_success([
                'id'    => $attachmentId,
                'name'  => $name,
                'stats' => $stats,
            ]);
        } else {
            wp_send_json_error(['id' => $attachmentId, 'name' => $name]);
        }
    }

    private function getUncompressedIds(): array
    {
        $query = new \WP_Query([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [[
                'key'     => '_imgpress_compressed_at',
                'compare' => 'NOT EXISTS',
            ]],
        ]);

        return $query->posts;
    }

    public function enqueueAssets(string $hook): void
    {
        if ($hook !== 'imgpress_page_imgpress-bulk') {
            return;
        }

        wp_enqueue_style(
            'imgpress-badges',
            IMGPRESS_WP_URL . 'assets/css/badges.css',
            [],
            IMGPRESS_WP_VERSION
        );
        wp_enqueue_style(
            'imgpress-bulk-results',
            IMGPRESS_WP_URL . 'assets/css/bulk-results.css',
            [],
            IMGPRESS_WP_VERSION
        );

        wp_enqueue_script(
            'imgpress-bulk-compress',
            IMGPRESS_WP_URL . 'assets/js/bulk-compress.js',
            ['jquery'],
            IMGPRESS_WP_VERSION,
            true
        );

        wp_localize_script('imgpress-bulk-compress', 'ImgPressAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('imgpress_bulk'),
        ]);

        wp_enqueue_script(
            'imgpress-admin',
            IMGPRESS_WP_URL . 'assets/admin.js',
            ['jquery'],
            IMGPRESS_WP_VERSION,
            true
        );

        wp_localize_script('imgpress-admin', 'ImgPressMediaAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('imgpress_bulk'),
        ]);
    }
}
