<?php

namespace ImgPress;

defined('ABSPATH') || exit;

class Auto_Compress
{
    private bool $compressing = false;

    public function __construct(
        private Compressor  $compressor,
        private R2_Uploader $r2Uploader,
        private Settings    $settings
    ) {
        add_filter('wp_handle_upload', [$this, 'handleUpload']);
        add_action('add_attachment', [$this, 'handleAddAttachment']);
        add_filter('wp_generate_attachment_metadata', [$this, 'handleGeneratedMetadata'], 10, 3);
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
     * Fires right after the attachment post is created. Non-image files can be
     * compressed here because WordPress does not need to inspect image sizes
     * from the original path later in the request.
     */
    public function handleAddAttachment(int $attachmentId): void
    {
        $filePath = get_attached_file($attachmentId);
        $mime = get_post_mime_type($attachmentId);

        if (!$filePath || !$mime || str_starts_with($mime, 'image/')) {
            return;
        }

        $key = 'imgpress_upload_' . md5($filePath);
        $flag = get_transient($key);

        if (!$flag || empty($flag['should_compress'])) {
            return;
        }

        delete_transient($key);
        $this->compressAndMaybeUpload($attachmentId);
    }

    /**
     * For images, wait until WordPress has finished generating metadata from the
     * original upload. Then replace it with the converted file and return fresh
     * metadata so the caller persists the converted file's sub-sizes.
     *
     * We intentionally hook `wp_generate_attachment_metadata` (which fires once the
     * full metadata tree has been produced for this upload) instead of
     * `wp_update_attachment_metadata`. WordPress calls `wp_update_attachment_metadata`
     * *while* it is still creating sub-sizes (wp_create_image_subsizes()/
     * _wp_make_subsizes() persist progress after every size). Compressing there would
     * delete the original upload file while WordPress still holds it open and is about
     * to generate more sizes from it, which leaves the attachment with no thumbnail
     * metadata. By the time `wp_generate_attachment_metadata` runs, all sub-sizes from
     * the original file already exist, so it is safe to convert and regenerate.
     */
    public function handleGeneratedMetadata(array $metadata, int $attachmentId, string $context = 'create'): array
    {
        if ($this->compressing) {
            return $metadata;
        }

        $filePath = get_attached_file($attachmentId);
        $mime = get_post_mime_type($attachmentId);

        if (!$filePath || !$mime || !str_starts_with($mime, 'image/')) {
            return $metadata;
        }

        $key = 'imgpress_upload_' . md5($filePath);
        $flag = get_transient($key);

        if (!$flag || empty($flag['should_compress'])) {
            return $metadata;
        }

        delete_transient($key);

        if (!$this->compressAndMaybeUpload($attachmentId)) {
            return $metadata;
        }

        // Compressor::compress() has already regenerated and persisted metadata
        // for the converted file before it uploads anything to R2. Do not read the
        // image from disk again here: a successful R2 upload may intentionally have
        // deleted the local original and generated sizes.
        $updated = wp_get_attachment_metadata($attachmentId);

        return is_array($updated) ? $updated : $metadata;
    }

    private function compressAndMaybeUpload(int $attachmentId): bool
    {
        $this->compressing = true;

        try {
            $ok = $this->compressor->compress($attachmentId);
        } finally {
            $this->compressing = false;
        }

        if (!$ok) {
            return false;
        }

        if ($this->settings->isR2PushOnUpload()) {
            $this->r2Uploader->upload($attachmentId);
        }

        return true;
    }
}
