<?php

namespace ImgPress;

defined('ABSPATH') || exit;

class Compressor
{
    public function __construct(
        private Api_Client $apiClient,
        private Settings   $settings
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

        file_put_contents($filePath, $result['data']);

        update_post_meta($attachmentId, '_imgpress_original_size',   $result['originalSize']);
        update_post_meta($attachmentId, '_imgpress_compressed_size', $result['compressedSize']);
        update_post_meta($attachmentId, '_imgpress_ratio',           $result['ratio']);
        update_post_meta($attachmentId, '_imgpress_compressed_at',   current_time('mysql'));
        update_post_meta($attachmentId, '_imgpress_mime_out',        $result['mime']);

        if (str_starts_with($mime, 'image/')) {
            $metadata = wp_generate_attachment_metadata($attachmentId, $filePath);
            wp_update_attachment_metadata($attachmentId, $metadata);
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
}
