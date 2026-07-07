<?php

namespace ImgPress;

defined('ABSPATH') || exit;

class Media_Columns
{
    public function __construct(
        private Compressor   $compressor,
        private Settings     $settings,
        private ?R2_Uploader $uploader = null
    ) {
        add_filter('manage_media_columns',        [$this, 'addColumn']);
        add_action('manage_media_custom_column',  [$this, 'renderColumn'], 10, 2);
        add_action('wp_ajax_imgpress_compress_single', [$this, 'handleAjaxSingle']);
        add_action('wp_ajax_imgpress_restore_original', [$this, 'handleRestoreOriginal']);
        add_action('wp_ajax_imgpress_r2_push',   [$this, 'handleR2Push']);
        add_action('wp_ajax_imgpress_r2_remove', [$this, 'handleR2Remove']);
        add_action('admin_enqueue_scripts',       [$this, 'enqueueAssets']);
        add_action('delete_attachment',           [$this, 'handleDeleteAttachment']);
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
            if ($this->compressor->canRestore($postId)) {
                echo "<button class=\"button ip-restore-btn\" data-id=\"{$postId}\">Restore original</button>";
                echo "<span class=\"ip-restore-result\"></span>";
            }
        } else {
            $mime = get_post_mime_type($postId);
            if ($mime && $this->settings->isTypeEnabled($mime)) {
                echo "<button class=\"button ip-compress-btn\" data-id=\"{$postId}\">Compress</button>";
                echo "<span class=\"ip-compress-result\"></span>";
            } else {
                echo '<span class="ip-na">—</span>';
            }
        }

        // Render R2 sub-block if R2 is configured
        if ($this->uploader && $this->settings->isR2Configured()) {
            $this->renderR2SubBlock($postId);
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

    public function handleRestoreOriginal(): void
    {
        check_ajax_referer('imgpress_compress_single');

        if (!current_user_can('upload_files')) {
            wp_send_json_error('Unauthorized', 403);
        }

        $attachmentId = (int) ($_POST['id'] ?? 0);
        if (!$attachmentId) {
            wp_send_json_error('Invalid ID');
        }

        $ok = $this->compressor->restore($attachmentId);

        if (!$ok) {
            wp_send_json_error('Restore failed — original backup is missing or unreadable.');
        }

        wp_send_json_success([
            'id' => $attachmentId,
            'mime' => get_post_mime_type($attachmentId),
            'file' => basename(get_attached_file($attachmentId) ?: ''),
        ]);
    }

    public function handleDeleteAttachment(int $attachmentId): void
    {
        $this->compressor->deleteOriginalBackup($attachmentId);
    }

    public function handleR2Push(): void
    {
        check_ajax_referer('imgpress_r2');

        if (!current_user_can('upload_files')) {
            wp_send_json_error('Unauthorized', 403);
        }

        $attachmentId = (int) ($_POST['id'] ?? 0);
        if (!$attachmentId) {
            wp_send_json_error('Invalid ID');
        }

        if (!$this->uploader) {
            wp_send_json_error('R2 uploader not available');
        }

        $ok = $this->uploader->upload($attachmentId);

        if (!$ok) {
            wp_send_json_error('Upload to R2 failed — check error log.');
        }

        $status = $this->uploader->getStatus($attachmentId);
        wp_send_json_success($status);
    }

    public function handleR2Remove(): void
    {
        check_ajax_referer('imgpress_r2');

        if (!current_user_can('upload_files')) {
            wp_send_json_error('Unauthorized', 403);
        }

        $attachmentId = (int) ($_POST['id'] ?? 0);
        if (!$attachmentId) {
            wp_send_json_error('Invalid ID');
        }

        if (!$this->uploader) {
            wp_send_json_error('R2 uploader not available');
        }

        $ok = $this->uploader->remove($attachmentId);

        if (!$ok) {
            wp_send_json_error('Remove from R2 failed — check error log.');
        }

        wp_send_json_success(['ok' => true]);
    }

    private function renderR2SubBlock(int $postId): void
    {
        $status = $this->uploader->getStatus($postId);

        echo '<div class="ip-r2-block">';

        if ($status && $status['status'] === 'uploaded') {
            // R2 ✓ badge + link + Remove button
            echo '<span class="ip-badge ip-r2-badge">R2 ✓</span>';

            if (!empty($status['url'])) {
                echo '<a href="' . esc_attr($status['url']) . '" target="_blank" class="ip-r2-link">' .
                     esc_html(parse_url($status['url'], PHP_URL_HOST)) .
                     '</a>';
            } else {
                echo '<span class="ip-r2-link">No public URL</span>';
            }

            echo '<button class="button ip-r2-btn ip-r2-remove-btn" data-id="' . esc_attr($postId) . '">Remove</button>';
            echo '<span class="ip-r2-result"></span>';
        } elseif ($status && $status['status'] === 'failed') {
            // R2 failed badge + Retry button
            echo '<span class="ip-err">R2 failed</span>';
            echo '<button class="button ip-r2-btn ip-r2-push-btn" data-id="' . esc_attr($postId) . '">Retry</button>';
            echo '<span class="ip-r2-result"></span>';
        } else {
            // Push to R2 button
            echo '<button class="button ip-r2-btn ip-r2-push-btn" data-id="' . esc_attr($postId) . '">Push to R2</button>';
            echo '<span class="ip-r2-result"></span>';
        }

        echo '</div>';
    }

    public function enqueueAssets(string $hook): void
    {
        if ($hook !== 'upload.php') {
            return;
        }

        wp_enqueue_style(
            'imgpress-media-library',
            IMGPRESS_WP_URL . 'assets/css/media-library.css',
            [],
            IMGPRESS_WP_VERSION
        );
        wp_enqueue_style(
            'imgpress-badges',
            IMGPRESS_WP_URL . 'assets/css/badges.css',
            [],
            IMGPRESS_WP_VERSION
        );
        wp_enqueue_style(
            'imgpress-r2-offloading',
            IMGPRESS_WP_URL . 'assets/css/r2-offloading.css',
            [],
            IMGPRESS_WP_VERSION
        );

        wp_enqueue_script(
            'imgpress-media-library',
            IMGPRESS_WP_URL . 'assets/js/media-library.js',
            ['jquery'],
            IMGPRESS_WP_VERSION,
            true
        );

        wp_localize_script('imgpress-media-library', 'ImgPressAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('imgpress_compress_single'),
            'r2Nonce' => wp_create_nonce('imgpress_r2'),
        ]);

        wp_enqueue_script(
            'imgpress-admin',
            IMGPRESS_WP_URL . 'assets/admin.js',
            ['jquery'],
            IMGPRESS_WP_VERSION,
            true
        );

        wp_localize_script('imgpress-admin', 'ImgPressAdmin', [
            'ajaxUrl'  => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('imgpress_compress_single'),
            'r2Nonce'  => wp_create_nonce('imgpress_r2'),
        ]);
    }
}
