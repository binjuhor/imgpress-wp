<?php

namespace ImgPress;

defined('ABSPATH') || exit;

class Auto_Compress
{
    public function __construct(
        private Compressor  $compressor,
        private R2_Uploader $r2Uploader,
        private Settings    $settings
    ) {
        add_filter('wp_handle_upload', [$this, 'handleUpload']);
        add_action('add_attachment', [$this, 'handleAddAttachment']);
    }

    /**
     * Fires immediately after a file is uploaded to disk, before the attachment
     * post exists. Stores a flag so handleAddAttachment() knows to trigger compression
     * after the attachment post is created (at which point we have an attachment ID).
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

        // Stash flag so handleAddAttachment() knows to trigger compress
        $key = 'imgpress_upload_' . md5($filePath);
        set_transient($key, ['should_compress' => true], 60);

        return $upload;
    }

    /**
     * Fires right after the attachment post is created. Checks if compression
     * should be triggered (via transient flag from handleUpload), compresses
     * the file, and optionally pushes to R2 if enabled.
     */
    public function handleAddAttachment(int $attachmentId): void
    {
        $filePath = get_attached_file($attachmentId);

        if (!$filePath) {
            return;
        }

        $key = 'imgpress_upload_' . md5($filePath);
        $flag = get_transient($key);

        if (!$flag || empty($flag['should_compress'])) {
            return;
        }

        delete_transient($key);

        // Trigger compression (Compressor writes its own meta)
        $this->compressor->compress($attachmentId);

        // Push to R2 if enabled (happens after compress() which calls wp_generate_attachment_metadata)
        if ($this->settings->isR2PushOnUpload()) {
            $this->r2Uploader->upload($attachmentId);
        }
    }
}
