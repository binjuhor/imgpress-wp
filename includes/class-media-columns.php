<?php

namespace ImgPress;

defined('ABSPATH') || exit;

class Media_Columns
{
    public function __construct(
        private Compressor $compressor,
        private Settings   $settings
    ) {
        add_filter('manage_media_columns',        [$this, 'addColumn']);
        add_action('manage_media_custom_column',  [$this, 'renderColumn'], 10, 2);
        add_action('wp_ajax_imgpress_compress_single', [$this, 'handleAjaxSingle']);
        add_action('admin_enqueue_scripts',       [$this, 'enqueueAssets']);
    }

    public function addColumn(array $columns): array
    {
        $columns['imgpress'] = '<span title="ImgPress">⚡ ImgPress</span>';
        return $columns;
    }

    public function renderColumn(string $column, int $postId): void
    {
        if ($column !== 'imgpress') {
            return;
        }

        $stats = $this->compressor->getStats($postId);

        if ($stats) {
            $ratio    = number_format($stats['ratio'], 1);
            $origKb   = number_format($stats['originalSize'] / 1024, 1);
            $compKb   = number_format($stats['compressedSize'] / 1024, 1);
            $date     = date_i18n('M j, Y', strtotime($stats['compressedAt']));
            $tier     = $stats['ratio'] >= 60 ? 'high' : ($stats['ratio'] >= 30 ? 'mid' : 'low');

            echo "<span class=\"ip-badge ip-badge--{$tier}\">−{$ratio}%</span>";
            echo "<span class=\"ip-sizes\">{$origKb} → {$compKb} KB</span>";
            echo "<span class=\"ip-date\">{$date}</span>";
        } else {
            $mime = get_post_mime_type($postId);
            if ($mime && $this->settings->isTypeEnabled($mime)) {
                echo "<button class=\"button ip-compress-btn\" data-id=\"{$postId}\">Compress</button>";
                echo "<span class=\"ip-compress-result\"></span>";
            } else {
                echo '<span class="ip-na">—</span>';
            }
        }
    }

    public function handleAjaxSingle(): void
    {
        check_ajax_referer('imgpress_compress_single');

        if (!current_user_can('upload_files')) {
            wp_send_json_error('Unauthorized', 403);
        }

        $attachmentId = (int) ($_POST['id'] ?? 0);
        if (!$attachmentId) {
            wp_send_json_error('Invalid ID');
        }

        $ok = $this->compressor->compress($attachmentId);

        if (!$ok) {
            wp_send_json_error('Compression failed — check error log.');
        }

        $stats = $this->compressor->getStats($attachmentId);
        wp_send_json_success($stats);
    }

    public function enqueueAssets(string $hook): void
    {
        if ($hook !== 'upload.php') {
            return;
        }

        wp_enqueue_style(
            'imgpress-admin',
            IMGPRESS_WP_URL . 'assets/admin.css',
            [],
            IMGPRESS_WP_VERSION
        );

        wp_enqueue_script(
            'imgpress-admin',
            IMGPRESS_WP_URL . 'assets/admin.js',
            ['jquery'],
            IMGPRESS_WP_VERSION,
            true
        );

        wp_localize_script('imgpress-admin', 'ImgPressAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('imgpress_compress_single'),
        ]);
    }
}
