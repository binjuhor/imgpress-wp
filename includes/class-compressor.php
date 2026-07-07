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

        if (!$this->backupOriginal($attachmentId, $filePath, $mime)) {
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

    public function restore(int $attachmentId): bool
    {
        $backup = $this->getOriginalBackup($attachmentId);
        if (!$backup) {
            return false;
        }

        $backupPath = $this->absoluteUploadPath($backup['backup_file']);
        if (!$backupPath || !file_exists($backupPath)) {
            return false;
        }

        $currentPath = get_attached_file($attachmentId);
        $restorePath = $this->absoluteUploadPath($backup['source_file']);
        if (!$restorePath) {
            return false;
        }

        $restoreDir = dirname($restorePath);
        if (!wp_mkdir_p($restoreDir)) {
            error_log("[ImgPress] Cannot create restore directory: {$restoreDir}");
            return false;
        }

        if ($this->r2Uploader->getStatus($attachmentId)) {
            $this->r2Uploader->remove($attachmentId);
        }

        if ($currentPath && str_starts_with((string) get_post_mime_type($attachmentId), 'image/')) {
            $this->deleteOldImageFiles($attachmentId, $currentPath);
        }

        if (!copy($backupPath, $restorePath)) {
            error_log("[ImgPress] Cannot restore original file: {$restorePath}");
            return false;
        }

        update_attached_file($attachmentId, $restorePath);
        wp_update_post([
            'ID' => $attachmentId,
            'post_mime_type' => $backup['mime'],
        ]);

        if (str_starts_with($backup['mime'], 'image/')) {
            $metadata = wp_generate_attachment_metadata($attachmentId, $restorePath);
            wp_update_attachment_metadata($attachmentId, $metadata);
        }

        if ($currentPath && $currentPath !== $restorePath && file_exists($currentPath)) {
            wp_delete_file($currentPath);
        }

        delete_post_meta($attachmentId, '_imgpress_original_size');
        delete_post_meta($attachmentId, '_imgpress_compressed_size');
        delete_post_meta($attachmentId, '_imgpress_ratio');
        delete_post_meta($attachmentId, '_imgpress_compressed_at');
        delete_post_meta($attachmentId, '_imgpress_mime_out');
        delete_post_meta($attachmentId, '_imgpress_r2');

        return true;
    }

    public function canRestore(int $attachmentId): bool
    {
        return $this->getOriginalBackup($attachmentId) !== null;
    }

    public function deleteOriginalBackup(int $attachmentId): void
    {
        $backup = $this->getOriginalBackup($attachmentId);
        if (!$backup) {
            return;
        }

        $backupPath = $this->absoluteUploadPath($backup['backup_file']);
        if ($backupPath && file_exists($backupPath)) {
            wp_delete_file($backupPath);
        }

        delete_post_meta($attachmentId, '_imgpress_original_backup');
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
            'canRestore'     => $this->canRestore($attachmentId),
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

    private function backupOriginal(int $attachmentId, string $filePath, string $mime): bool
    {
        $existing = $this->getOriginalBackup($attachmentId);
        if ($existing) {
            return true;
        }

        if (!file_exists($filePath)) {
            return false;
        }

        $uploads = wp_upload_dir();
        $relativeBackup = 'imgpress-originals/' . $attachmentId . '/' . basename($filePath);
        $backupPath = trailingslashit($uploads['basedir']) . $relativeBackup;
        $backupDir = dirname($backupPath);

        if (!wp_mkdir_p($backupDir)) {
            error_log("[ImgPress] Cannot create original backup directory: {$backupDir}");
            return false;
        }

        if (!copy($filePath, $backupPath)) {
            error_log("[ImgPress] Cannot back up original file: {$filePath}");
            return false;
        }

        update_post_meta($attachmentId, '_imgpress_original_backup', [
            'backup_file' => $relativeBackup,
            'source_file' => _wp_relative_upload_path($filePath) ?: basename($filePath),
            'mime' => $mime,
            'size' => filesize($filePath) ?: 0,
            'created_at' => current_time('mysql'),
        ]);

        return true;
    }

    private function getOriginalBackup(int $attachmentId): ?array
    {
        $backup = get_post_meta($attachmentId, '_imgpress_original_backup', true);
        if (!is_array($backup) || empty($backup['backup_file']) || empty($backup['source_file']) || empty($backup['mime'])) {
            return null;
        }

        return $backup;
    }

    private function absoluteUploadPath(string $relativePath): ?string
    {
        $uploads = wp_upload_dir();
        if (empty($uploads['basedir'])) {
            return null;
        }

        return trailingslashit($uploads['basedir']) . ltrim($relativePath, '/');
    }
}
