<?php

namespace ImgPress;

defined('ABSPATH') || exit;

class Compressor
{
    public function __construct(
        private Api_Client $apiClient,
        private Settings   $settings,
        private R2_Uploader $r2Uploader
    ) {}

    public function compress(int $attachmentId): bool
    {
        $filePath = get_attached_file($attachmentId);
        $mime     = get_post_mime_type($attachmentId);

        if (!$filePath || !$mime) {
            return false;
        }

        if (!$this->settings->isTypeEnabled($mime)) {
            return false;
        }

        $result = $this->apiClient->compress($filePath, $mime);

        if (!$result) {
            return false;
        }

        if ($result['mime'] === $mime && $result['compressedSize'] > $result['originalSize']) {
            return false;
        }

        $targetPath = $this->getTargetPath($filePath, $mime, $result['mime']);

        if (file_put_contents($targetPath, $result['data']) === false) {
            error_log("[ImgPress] Cannot write compressed file: {$targetPath}");
            return false;
        }

        if ($targetPath !== $filePath) {
            update_attached_file($attachmentId, $targetPath);
            wp_update_post([
                'ID'             => $attachmentId,
                'post_mime_type' => $result['mime'],
            ]);
        }

        update_post_meta($attachmentId, '_imgpress_original_size',   $result['originalSize']);
        update_post_meta($attachmentId, '_imgpress_compressed_size', $result['compressedSize']);
        update_post_meta($attachmentId, '_imgpress_ratio',           $result['ratio']);
        update_post_meta($attachmentId, '_imgpress_compressed_at',   current_time('mysql'));
        update_post_meta($attachmentId, '_imgpress_mime_out',        $result['mime']);

        if (str_starts_with($mime, 'image/')) {
            if ($targetPath !== $filePath) {
                $this->deleteOldImageFiles($attachmentId, $filePath);
            }

            $metadata = wp_generate_attachment_metadata($attachmentId, $targetPath);
            wp_update_attachment_metadata($attachmentId, $metadata);
        }

        if ($targetPath !== $filePath && file_exists($filePath)) {
            wp_delete_file($filePath);
        }

        // Push to R2 if enabled
        if ($this->settings->isR2PushOnCompress()) {
            $this->r2Uploader->upload($attachmentId);
        }

        return true;
    }

    public function getStats(int $attachmentId): ?array
    {
        $compressedAt = get_post_meta($attachmentId, '_imgpress_compressed_at', true);

        if (!$compressedAt) {
            return null;
        }

        return [
            'originalSize'   => (int) get_post_meta($attachmentId, '_imgpress_original_size', true),
            'compressedSize' => (int) get_post_meta($attachmentId, '_imgpress_compressed_size', true),
            'ratio'          => (float) get_post_meta($attachmentId, '_imgpress_ratio', true),
            'compressedAt'   => $compressedAt,
            'mimeOut'        => get_post_meta($attachmentId, '_imgpress_mime_out', true),
        ];
    }

    private function getTargetPath(string $filePath, string $sourceMime, string $targetMime): string
    {
        if (!str_starts_with($sourceMime, 'image/') || !str_starts_with($targetMime, 'image/')) {
            return $filePath;
        }

        $extension = $this->extensionForMime($targetMime);
        if (!$extension) {
            return $filePath;
        }

        $info = pathinfo($filePath);
        if (strtolower($info['extension'] ?? '') === $extension) {
            return $filePath;
        }

        $directory = $info['dirname'] ?? dirname($filePath);
        $filename = ($info['filename'] ?? basename($filePath)) . '.' . $extension;

        if (file_exists($directory . '/' . $filename)) {
            $filename = wp_unique_filename($directory, $filename);
        }

        return $directory . '/' . $filename;
    }

    private function extensionForMime(string $mime): ?string
    {
        return match ($mime) {
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            default => null,
        };
    }

    private function deleteOldImageFiles(int $attachmentId, string $filePath): void
    {
        $metadata = wp_get_attachment_metadata($attachmentId);
        if (!is_array($metadata) || empty($metadata['sizes']) || !is_array($metadata['sizes'])) {
            return;
        }

        $directory = dirname($filePath);
        foreach ($metadata['sizes'] as $size) {
            if (empty($size['file'])) {
                continue;
            }

            $sizePath = $directory . '/' . $size['file'];
            if (file_exists($sizePath)) {
                wp_delete_file($sizePath);
            }
        }
    }
}
