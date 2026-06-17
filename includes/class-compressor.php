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

        if ($result['compressedSize'] > $result['originalSize']) {
            return false;
        }

        $newFilePath = $filePath;
        $mimeChanged = false;

        if ($result['mime'] !== $mime) {
            $newFilePath = $this->get_converted_file_path($filePath, $result['mime']);
            $mimeChanged = true;
        }

        file_put_contents($newFilePath, $result['data']);

        if ($mimeChanged && $newFilePath !== $filePath && file_exists($filePath)) {
            wp_delete_file($filePath);
        }

        if ($mimeChanged) {
            update_attached_file($attachmentId, $newFilePath);
        }

        update_post_meta($attachmentId, '_imgpress_original_size',   $result['originalSize']);
        update_post_meta($attachmentId, '_imgpress_compressed_size', $result['compressedSize']);
        update_post_meta($attachmentId, '_imgpress_ratio',           $result['ratio']);
        update_post_meta($attachmentId, '_imgpress_compressed_at',   current_time('mysql'));
        update_post_meta($attachmentId, '_imgpress_mime_out',        $result['mime']);

        if (str_starts_with($mime, 'image/')) {
            $metadata = wp_generate_attachment_metadata($attachmentId, $newFilePath);
            wp_update_attachment_metadata($attachmentId, $metadata);
        }

        if ($mimeChanged) {
            wp_set_post_terms($attachmentId, $this->get_mime_type_term($result['mime']), 'attachment_type');
        }

        do_action('imgpress_compress_complete', $attachmentId);

        if ($this->settings->isR2PushOnCompress()) {
            $this->r2Uploader->upload($attachmentId);
        }

        return true;
    }

    private function get_converted_file_path(string $originalPath, string $newMime): string
    {
        $ext = $this->get_extension_for_mime($newMime);
        $dir = dirname($originalPath);
        $name = pathinfo($originalPath, PATHINFO_FILENAME);

        return "{$dir}/{$name}.{$ext}";
    }

    private function get_extension_for_mime(string $mime): string
    {
        return match ($mime) {
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            default => 'webp',
        };
    }

    private function get_mime_type_term(string $mime): string
    {
        return match ($mime) {
            'image/webp' => 'image',
            'image/avif' => 'image',
            'image/jpeg' => 'image',
            'image/png' => 'image',
            'image/gif' => 'image',
            'application/pdf' => 'document',
            default => 'unformatted',
        };
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
}
