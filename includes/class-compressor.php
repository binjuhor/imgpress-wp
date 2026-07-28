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
        return $this->compressAttachment($attachmentId, false);
    }

    /**
     * Explicit reconversion always synchronizes the resulting files to R2 when
     * R2 is configured. If the local attachment already uses the target format,
     * skip another lossy conversion and repair only the R2 object set.
     */
    public function reconvert(int $attachmentId): bool
    {
        $targetMime = $this->targetMime();
        $currentMime = strtolower((string) get_post_mime_type($attachmentId));

        if ($targetMime !== '' && $currentMime === $targetMime) {
            $filePath = get_attached_file($attachmentId);
            $r2Status = $this->r2Uploader->getStatus($attachmentId);

            if (!$filePath) {
                return false;
            }
            if (!file_exists($filePath) && (!$r2Status || !$this->r2Uploader->download($attachmentId))) {
                return false;
            }

            $this->repairEmbeddedUrlsFromBackup($attachmentId, $filePath);

            return $this->settings->isR2Configured()
                ? $this->r2Uploader->upload($attachmentId)
                : true;
        }

        return $this->compressAttachment($attachmentId, true);
    }

    private function compressAttachment(int $attachmentId, bool $forceR2Upload): bool
    {
        $filePath = get_attached_file($attachmentId);
        $mime     = get_post_mime_type($attachmentId);
		$r2Status = $this->r2Uploader->getStatus($attachmentId);

        if (!$filePath || !$mime) {
            return false;
        }

		// Offloaded libraries may intentionally have no local files. Pull the current
		// object back before recompressing it.
		if (!file_exists($filePath) && (!$r2Status || !$this->r2Uploader->download($attachmentId))) {
			return false;
		}

        if (!$this->settings->isTypeEnabled($mime)) {
            return false;
        }

        $result = $this->apiClient->compress($filePath, $mime);

        if (!$result) {
            return false;
        }

        // A format conversion is not an optimization when it increases the file.
        // Keep the current attachment untouched regardless of the output MIME type.
        if ($result['compressedSize'] >= $result['originalSize']) {
            return false;
        }

        if (!$this->backupOriginal($attachmentId, $filePath, $mime)) {
            return false;
        }

        $oldMetadata = wp_get_attachment_metadata($attachmentId);

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

            if ($targetPath !== $filePath) {
                $this->replaceEmbeddedAttachmentUrls(
                    $filePath,
                    is_array($oldMetadata) ? $oldMetadata : [],
                    $targetPath,
                    is_array($metadata) ? $metadata : []
                );
            }
        }

        if ($targetPath !== $filePath && file_exists($filePath)) {
            wp_delete_file($filePath);
        }

        // Push to R2 if enabled
        if (($forceR2Upload && $this->settings->isR2Configured())
            || $this->settings->isR2PushOnCompress()
            || ($r2Status['status'] ?? '') === 'uploaded') {
            if (!$this->r2Uploader->upload($attachmentId)) {
				return false;
			}
        }

        return true;
    }

    private function targetMime(): string
    {
        return match ($this->settings->getFormat()) {
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'jpeg' => 'image/jpeg',
            default => '',
        };
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
        $currentMetadata = wp_get_attachment_metadata($attachmentId);
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

            if ($currentPath && $currentPath !== $restorePath) {
                $this->replaceEmbeddedAttachmentUrls(
                    $currentPath,
                    is_array($currentMetadata) ? $currentMetadata : [],
                    $restorePath,
                    is_array($metadata) ? $metadata : []
                );
            }
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

    public function hasStaleEmbeddedUrls(int $attachmentId): bool
    {
        $backup = $this->getOriginalBackup($attachmentId);
        if (!$backup || empty($backup['source_file'])) {
            return false;
        }
        $sourcePath = $this->absoluteUploadPath($backup['source_file']);
        if (!$sourcePath) {
            return false;
        }
        $sourceUrl = $this->localUploadUrl($sourcePath);
        if (!$sourceUrl) {
            return false;
        }

        global $wpdb;
        $info = pathinfo($sourceUrl);
        $urlStem = trailingslashit($info['dirname'] ?? '') . ($info['filename'] ?? '');
        $stems = [$urlStem];
        $uploads = wp_upload_dir();
        $publicBase = $this->settings->getR2PublicBaseUrl();
        if (!empty($uploads['baseurl']) && $publicBase !== '') {
            $stems[] = str_replace(rtrim($uploads['baseurl'], '/'), rtrim($publicBase, '/'), $urlStem);
        }
        foreach (array_unique($stems) as $stem) {
            if ($wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type <> 'revision' AND post_content LIKE %s LIMIT 1",
                '%' . $wpdb->esc_like($stem) . '%'
            ))) {
                return true;
            }
        }

        return false;
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

    /** Update hardcoded full-size and generated-size URLs after an extension change. */
    private function replaceEmbeddedAttachmentUrls(
        string $oldPath,
        array $oldMetadata,
        string $newPath,
        array $newMetadata
    ): void {
        $replacements = [];
        $oldUrl = $this->localUploadUrl($oldPath);
        $newUrl = $this->localUploadUrl($newPath);
        if ($oldUrl && $newUrl && $oldUrl !== $newUrl) {
            $replacements[$oldUrl] = $newUrl;
        }

        $oldDirectory = dirname($oldPath);
        $newDirectory = dirname($newPath);
        foreach (($oldMetadata['sizes'] ?? []) as $sizeName => $oldSize) {
            $newSize = $newMetadata['sizes'][$sizeName] ?? null;
            if (empty($oldSize['file']) || empty($newSize['file'])) {
                continue;
            }
            $oldSizeUrl = $this->localUploadUrl($oldDirectory . '/' . $oldSize['file']);
            $newSizeUrl = $this->localUploadUrl($newDirectory . '/' . $newSize['file']);
            if ($oldSizeUrl && $newSizeUrl && $oldSizeUrl !== $newSizeUrl) {
                $replacements[$oldSizeUrl] = $newSizeUrl;
            }
        }

        $this->replaceUrlsInPostContent($replacements);
    }

    /** Repair content converted by older ImgPress versions that did not migrate URLs. */
    private function repairEmbeddedUrlsFromBackup(int $attachmentId, string $currentPath): void
    {
        $backup = $this->getOriginalBackup($attachmentId);
        if (!$backup || empty($backup['source_file'])) {
            return;
        }

        $sourcePath = $this->absoluteUploadPath($backup['source_file']);
        if (!$sourcePath || $sourcePath === $currentPath) {
            return;
        }

        $currentMetadata = wp_get_attachment_metadata($attachmentId);
        $sourceExtension = strtolower((string) pathinfo($sourcePath, PATHINFO_EXTENSION));
        $replacements = [];
        $sourceUrl = $this->localUploadUrl($sourcePath);
        $currentUrl = $this->localUploadUrl($currentPath);
        if ($sourceUrl && $currentUrl) {
            $replacements[$sourceUrl] = $currentUrl;
        }

        foreach ((is_array($currentMetadata) ? ($currentMetadata['sizes'] ?? []) : []) as $size) {
            if (empty($size['file']) || $sourceExtension === '') {
                continue;
            }
            $currentSizePath = dirname($currentPath) . '/' . $size['file'];
            $sourceSizePath = preg_replace('/\.[^.]+$/', '.' . $sourceExtension, $currentSizePath);
            if (!$sourceSizePath) {
                continue;
            }
            $sourceSizeUrl = $this->localUploadUrl($sourceSizePath);
            $currentSizeUrl = $this->localUploadUrl($currentSizePath);
            if ($sourceSizeUrl && $currentSizeUrl) {
                $replacements[$sourceSizeUrl] = $currentSizeUrl;
            }
        }

        $this->replaceUrlsInPostContent($replacements);
    }

    private function localUploadUrl(string $path): ?string
    {
        $relative = _wp_relative_upload_path($path);
        if (!$relative) {
            return null;
        }
        $uploads = wp_upload_dir();

        return trailingslashit($uploads['baseurl']) . ltrim(str_replace('\\', '/', $relative), '/');
    }

    /** @param array<string, string> $replacements */
    private function replaceUrlsInPostContent(array $replacements): void
    {
        if (!$replacements) {
            return;
        }

        $uploads = wp_upload_dir();
        $localBase = rtrim((string) ($uploads['baseurl'] ?? ''), '/');
        $publicBase = rtrim($this->settings->getR2PublicBaseUrl(), '/');
        if ($localBase !== '' && $publicBase !== '') {
            foreach ($replacements as $oldUrl => $newUrl) {
                if (str_starts_with($oldUrl, $localBase) && str_starts_with($newUrl, $localBase)) {
                    $replacements[$publicBase . substr($oldUrl, strlen($localBase))]
                        = $publicBase . substr($newUrl, strlen($localBase));
                }
            }
        }

        global $wpdb;
        uksort($replacements, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        $postIds = [];
        foreach (array_keys($replacements) as $oldUrl) {
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type <> 'revision' AND post_content LIKE %s",
                '%' . $wpdb->esc_like($oldUrl) . '%'
            ));
            foreach ($ids as $id) {
                $postIds[(int) $id] = true;
            }
        }

        foreach (array_keys($postIds) as $postId) {
            $post = get_post($postId);
            if (!$post || !is_string($post->post_content)) {
                continue;
            }
            $content = str_replace(array_keys($replacements), array_values($replacements), $post->post_content);
            if ($content !== $post->post_content) {
                wp_update_post(['ID' => $postId, 'post_content' => $content]);
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
