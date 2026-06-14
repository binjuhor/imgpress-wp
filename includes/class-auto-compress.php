<?php

namespace ImgPress;

defined('ABSPATH') || exit;

class Auto_Compress
{
    public function __construct(
        private Api_Client  $apiClient,
        private Compressor  $compressor,
        private Settings    $settings
    ) {
        add_filter('wp_handle_upload', [$this, 'handleUpload']);
        add_action('add_attachment', [$this, 'handleAddAttachment']);
    }

    /**
     * Fires immediately after a file is uploaded to disk, before the attachment
     * post exists. We compress the file in place and store stats in a transient
     * keyed on the filename so handleAddAttachment() can write them to post meta.
     */
    public function handleUpload(array $upload): array
    {
        if (!$this->settings->isAutoCompress()) {
            return $upload;
        }

        $mime = $upload['type'] ?? '';

        if (!$this->settings->isTypeEnabled($mime)) {
            return $upload;
        }

        $filePath = $upload['file'];
        $result   = $this->apiClient->compress($filePath, $mime);

        if (!$result) {
            return $upload;
        }

        file_put_contents($filePath, $result['data']);

        // Stash stats until we have an attachment ID
        $key = 'imgpress_upload_' . md5($filePath);
        set_transient($key, $result, 60);

        if ($result['mime'] !== $mime) {
            $upload['type'] = $result['mime'];
        }

        return $upload;
    }

    /**
     * Fires right after the attachment post is created. Pulls the transient
     * written by handleUpload() and saves stats to post meta.
     */
    public function handleAddAttachment(int $attachmentId): void
    {
        $filePath = get_attached_file($attachmentId);

        if (!$filePath) {
            return;
        }

        $key    = 'imgpress_upload_' . md5($filePath);
        $result = get_transient($key);

        if (!$result) {
            return;
        }

        delete_transient($key);

        update_post_meta($attachmentId, '_imgpress_original_size',   $result['originalSize']);
        update_post_meta($attachmentId, '_imgpress_compressed_size', $result['compressedSize']);
        update_post_meta($attachmentId, '_imgpress_ratio',           $result['ratio']);
        update_post_meta($attachmentId, '_imgpress_compressed_at',   current_time('mysql'));
        update_post_meta($attachmentId, '_imgpress_mime_out',        $result['mime']);
    }
}
