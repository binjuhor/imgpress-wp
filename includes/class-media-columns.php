<?php

namespace ImgPress;

defined('ABSPATH') || exit;

class Media_Columns
{
    private const BULK_COMPRESS = 'imgpress_compress';
    private const BULK_OFFLOAD = 'imgpress_r2_offload';
    private const BULK_RESTORE = 'imgpress_restore_original';

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
        add_action('wp_ajax_imgpress_media_bulk_item', [$this, 'handleBulkItem']);
        add_action('admin_enqueue_scripts',       [$this, 'enqueueAssets']);
        add_action('delete_attachment',           [$this, 'handleDeleteAttachment']);
        add_filter('bulk_actions-upload',          [$this, 'addBulkActions']);
        add_filter('handle_bulk_actions-upload',   [$this, 'handleBulkActionFallback'], 10, 3);
        add_action('admin_notices',                [$this, 'renderBulkActionNotice']);
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
                echo $this->compactButton('ip-restore-btn', __('Restore original', 'imgpress-wp'), $postId);
                echo '<span class="ip-restore-result" role="status" aria-live="polite"></span>';
            }
        } else {
            $mime = get_post_mime_type($postId);
            if ($mime && $this->settings->isTypeEnabled($mime)) {
                echo $this->compactButton('ip-compress-btn', __('Compress', 'imgpress-wp'), $postId);
                echo '<span class="ip-compress-result" role="status" aria-live="polite"></span>';
            } else {
                echo '<span class="ip-na">—</span>';
            }
        }

        // Render R2 sub-block if R2 is configured
        if ($this->uploader && $this->settings->isR2Configured()) {
            $this->renderR2SubBlock($postId);
        }
    }

    public function addBulkActions(array $actions): array
    {
        $actions[self::BULK_COMPRESS] = __('Convert/Compress', 'imgpress-wp');
        $actions[self::BULK_RESTORE] = __('Restore Originals', 'imgpress-wp');

        if ($this->uploader && $this->settings->isR2Configured()) {
            $actions[self::BULK_OFFLOAD] = __('Offload to R2', 'imgpress-wp');
        }

        return $actions;
    }

    public function handleBulkItem(): void
    {
        check_ajax_referer('imgpress_media_bulk');

        $attachmentId = (int) ($_POST['id'] ?? 0);
        $operation = isset($_POST['operation']) && is_string($_POST['operation'])
            ? sanitize_key(wp_unslash($_POST['operation']))
            : '';
        if (!$this->canManageAttachment($attachmentId)) {
            wp_send_json_error(['result' => 'failed'], 403);
        }

        if (!in_array($operation, [self::BULK_COMPRESS, self::BULK_OFFLOAD, self::BULK_RESTORE], true)) {
            wp_send_json_error(['result' => 'failed'], 400);
        }

        wp_send_json_success(['result' => $this->runBulkAction($operation, $attachmentId)]);
    }

    /** Safe fallback when the sequential JavaScript controller is unavailable. */
    public function handleBulkActionFallback(string $redirectTo, string $action, array $postIds): string
    {
        if (!in_array($action, [self::BULK_COMPRESS, self::BULK_OFFLOAD, self::BULK_RESTORE], true)) {
            return $redirectTo;
        }

        return add_query_arg([
            'imgpress_bulk_action' => $action,
            'imgpress_succeeded' => 0,
            'imgpress_skipped' => 0,
            'imgpress_failed' => count(array_unique(array_map('intval', $postIds))),
            'imgpress_js_required' => 1,
        ], $redirectTo);
    }

    public function renderBulkActionNotice(): void
    {
        $screen = get_current_screen();
        if (!isset($_GET['imgpress_bulk_action']) || !$screen || $screen->id !== 'upload') {
            return;
        }

        $action = is_string($_GET['imgpress_bulk_action'])
            ? sanitize_key(wp_unslash($_GET['imgpress_bulk_action']))
            : '';
        $labels = [
            self::BULK_COMPRESS => __('Convert/Compress', 'imgpress-wp'),
            self::BULK_OFFLOAD => __('Offload to R2', 'imgpress-wp'),
            self::BULK_RESTORE => __('Restore Originals', 'imgpress-wp'),
        ];

        if (!isset($labels[$action])) {
            return;
        }

        $succeeded = isset($_GET['imgpress_succeeded']) ? absint($_GET['imgpress_succeeded']) : 0;
        $skipped = isset($_GET['imgpress_skipped']) ? absint($_GET['imgpress_skipped']) : 0;
        $failed = isset($_GET['imgpress_failed']) ? absint($_GET['imgpress_failed']) : 0;
        $noticeClass = $failed > 0 ? 'notice-warning' : 'notice-success';

        if (!empty($_GET['imgpress_js_required'])) {
            printf(
                '<div class="notice notice-error is-dismissible"><p><strong>ImgPress: %1$s</strong> — %2$s</p></div>',
                esc_html($labels[$action]),
                esc_html__('The bulk controller did not start. Enable JavaScript, reload the Media Library, and try again.', 'imgpress-wp')
            );
            return;
        }

        printf(
            '<div class="notice %1$s is-dismissible"><p><strong>ImgPress: %2$s</strong> — %3$d succeeded, %4$d skipped, %5$d failed.</p></div>',
            esc_attr($noticeClass),
            esc_html($labels[$action]),
            $succeeded,
            $skipped,
            $failed
        );
    }

    private function runBulkAction(string $action, int $attachmentId): string
    {
        if ($attachmentId <= 0 || get_post_type($attachmentId) !== 'attachment') {
            return 'skipped';
        }

        if ($action === self::BULK_COMPRESS) {
            $mime = (string) get_post_mime_type($attachmentId);
            if ($mime === '' || !$this->settings->isTypeEnabled($mime)) {
                return 'skipped';
            }

            return $this->compressor->compress($attachmentId) ? 'succeeded' : 'failed';
        }

        if ($action === self::BULK_RESTORE) {
            if (!$this->compressor->canRestore($attachmentId)) {
                return 'skipped';
            }

            return $this->compressor->restore($attachmentId) ? 'succeeded' : 'failed';
        }

        if (!$this->uploader || !$this->settings->isR2Configured()) {
            return 'failed';
        }

        $status = $this->uploader->getStatus($attachmentId);
        if (is_array($status) && ($status['status'] ?? '') === 'uploaded') {
            return 'skipped';
        }

        return $this->uploader->upload($attachmentId) ? 'succeeded' : 'failed';
    }

    private function canManageAttachment(int $attachmentId): bool
    {
        return $attachmentId > 0
            && get_post_type($attachmentId) === 'attachment'
            && current_user_can('upload_files')
            && current_user_can('edit_post', $attachmentId);
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
        if (!$this->canManageAttachment($attachmentId)) {
            wp_send_json_error('Unauthorized', 403);
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
        if (!$this->canManageAttachment($attachmentId)) {
            wp_send_json_error('Unauthorized', 403);
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
        if (!$this->canManageAttachment($attachmentId)) {
            wp_send_json_error('Unauthorized', 403);
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
        if (!$this->canManageAttachment($attachmentId)) {
            wp_send_json_error('Unauthorized', 403);
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

            echo $this->compactButton('ip-r2-btn ip-r2-remove-btn', __('Remove R2', 'imgpress-wp'), $postId);
            echo '<span class="ip-r2-result" role="status" aria-live="polite"></span>';
        } elseif ($status && $status['status'] === 'failed') {
            echo '<span class="ip-err">R2 failed</span>';
            echo $this->compactButton('ip-r2-btn ip-r2-push-btn', __('Retry R2', 'imgpress-wp'), $postId);
            echo '<span class="ip-r2-result" role="status" aria-live="polite"></span>';
        } else {
            echo $this->compactButton('ip-r2-btn ip-r2-push-btn', __('Offload R2', 'imgpress-wp'), $postId);
            echo '<span class="ip-r2-result" role="status" aria-live="polite"></span>';
        }

        echo '</div>';
    }

    private function compactButton(string $classes, string $label, int $postId): string
    {
        return sprintf(
            '<button type="button" class="button ip-compact-btn %1$s" data-id="%2$d" aria-label="%3$s" title="%3$s">%3$s</button>',
            esc_attr($classes),
            $postId,
            esc_html($label)
        );
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
            'bulkNonce' => wp_create_nonce('imgpress_media_bulk'),
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
            'bulkNonce' => wp_create_nonce('imgpress_media_bulk'),
        ]);
    }
}
