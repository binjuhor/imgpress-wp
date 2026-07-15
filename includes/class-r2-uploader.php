<?php

namespace ImgPress;

defined('ABSPATH') || exit;

/**
 * R2_Uploader — Orchestrates offloading media (compressed or uncompressed) to Cloudflare R2.
 * Works independently from compression. Implements all-or-nothing semantics: on first upload
 * failure, aborts without modifying local files. Meta written only after all files succeed.
 */
class R2_Uploader
{
	private $fileReader;

	public function __construct(
		private $client,
		private $settings,
		$fileReader = null
	) {
		$this->fileReader = $fileReader;
	}

	/**
	 * Upload attachment (original + all sub-sizes) to R2.
	 * Returns true only if ALL files uploaded successfully and meta was written.
	 * On any failure, keeps all local files and doesn't mark status=uploaded.
	 *
	 * @param int $attachmentId WordPress attachment post ID
	 *
	 * @return bool True if all files uploaded and meta written; false on any failure
	 */
	public function upload(int $attachmentId): bool
	{
		$previousMeta = $this->getStatus($attachmentId);
		// Guard: R2 must be configured
		if (!$this->settings->isR2Configured()) {
			error_log("[ImgPress R2] Skipping upload for attachment {$attachmentId}: R2 not configured");
			return false;
		}

		// Collect all files (original + sub-sizes)
		$files = $this->collectFiles($attachmentId);
		if (empty($files)) {
			error_log("[ImgPress R2] No files to upload for attachment {$attachmentId}");
			return false;
		}

		// Upload each file; abort on first failure (all-or-nothing)
		foreach ($files as $file) {
			$path = $file['path'];
			$key = $file['key'];
			$sizeName = $file['size'];

			// Read file contents
			if ($this->fileReader !== null) {
				$data = call_user_func($this->fileReader, $path);
			} else {
				$data = @file_get_contents($path);
			}

			if ($data === false || $data === null) {
				error_log("[ImgPress R2] Could not read file: {$path} (attachment {$attachmentId}, size: {$sizeName})");
				$this->markUploadFailed($attachmentId);
				return false;
			}

			// Get MIME type: original from post meta, sub-sizes inherit
			if ($sizeName === 'full') {
				$mime = get_post_mime_type($attachmentId);
			} else {
				$mime = get_post_mime_type($attachmentId);
			}

			if (!$mime) {
				$mime = 'application/octet-stream';
			}

			// Upload to R2
			$result = $this->client->putObject($key, $data, $mime);

			if (!$result['ok']) {
				error_log("[ImgPress R2] Upload failed for {$key} (attachment {$attachmentId}): " . ($result['error'] ?? 'Unknown error'));
				$this->markUploadFailed($attachmentId);
				return false;
			}
		}

		// All uploads succeeded — write meta
		$originalKey = $files[0]['key']; // First file is always original
		$publicUrl = $this->publicUrl($originalKey);

		// Build sizes map (key => R2 key for each sub-size)
		$sizesMap = [];
		foreach ($files as $file) {
			if ($file['size'] !== 'full') {
				$sizesMap[$file['size']] = $file['key'];
			}
		}

		$meta = [
			'status'      => 'uploaded',
			'key'         => $originalKey,
			'url'         => $publicUrl,
			'sizes'       => $sizesMap,
			'uploaded_at' => current_time('mysql'),
		];

		update_post_meta($attachmentId, '_imgpress_r2', $meta);

		// A format conversion changes object names (for example .jpg to .webp).
		// Only remove obsolete objects after every replacement has uploaded and the
		// attachment points at the new set, so a failed conversion never loses R2 data.
		if ($previousMeta) {
			$oldKeys = $this->keysFromMeta($previousMeta);
			$newKeys = $this->keysFromMeta($meta);
			foreach (array_diff($oldKeys, $newKeys) as $staleKey) {
				$result = $this->client->deleteObject($staleKey);
				if (!$result['ok']) {
					error_log("[ImgPress R2] Could not delete obsolete object {$staleKey} (attachment {$attachmentId})");
				}
			}
		}

		// Delete local files if enabled (ONLY after verified upload)
		if ($this->settings->isR2DeleteLocal()) {
			foreach ($files as $file) {
				$deleted = wp_delete_file($file['path']);
				if (!$deleted) {
					error_log("[ImgPress R2] Could not delete local file: {$file['path']} (attachment {$attachmentId})");
					// Log but don't fail — R2 copy is safe
				}
			}
		}

		return true;
	}

	/** Restore the full-size file and generated sizes from R2 to uploads. */
	public function download(int $attachmentId): bool
	{
		$meta = $this->getStatus($attachmentId);
		$originalPath = get_attached_file($attachmentId);
		if (!$meta || empty($meta['key']) || !$originalPath || !method_exists($this->client, 'getObject')) {
			return false;
		}

		$targets = [['key' => $meta['key'], 'path' => $originalPath]];
		$attachmentMeta = wp_get_attachment_metadata($attachmentId);
		foreach (($meta['sizes'] ?? []) as $sizeName => $key) {
			$file = $attachmentMeta['sizes'][$sizeName]['file'] ?? '';
			if ($file !== '') {
				$targets[] = ['key' => $key, 'path' => dirname($originalPath) . '/' . $file];
			}
		}

		$temps = [];
		foreach ($targets as $target) {
			$result = $this->client->getObject($target['key']);
			if (!$result['ok'] || !array_key_exists('data', $result)) {
				$this->cleanupDownloadedFiles(array_column($temps, 'temp'));
				return false;
			}
			if (!wp_mkdir_p(dirname($target['path']))) {
				$this->cleanupDownloadedFiles(array_column($temps, 'temp'));
				return false;
			}
			$temp = $target['path'] . '.imgpress-download-' . wp_generate_password(8, false);
			if (file_put_contents($temp, $result['data']) === false) {
				@unlink($temp);
				$this->cleanupDownloadedFiles(array_column($temps, 'temp'));
				return false;
			}
			$temps[] = ['temp' => $temp, 'path' => $target['path']];
		}

		foreach ($temps as $item) {
			if (!rename($item['temp'], $item['path'])) {
				$this->cleanupDownloadedFiles(array_column($temps, 'temp'));
				return false;
			}
		}

		return true;
	}

	/** Delete local media only when a complete uploaded R2 set is recorded. */
	public function deleteLocal(int $attachmentId): bool
	{
		$meta = $this->getStatus($attachmentId);
		if (!$meta || ($meta['status'] ?? '') !== 'uploaded') {
			return false;
		}
		$files = $this->collectFiles($attachmentId);
		if (empty($files)) {
			return false;
		}
		foreach ($files as $file) {
			if (file_exists($file['path']) && !wp_delete_file($file['path'])) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Remove attachment from R2 and clear meta.
	 * Note: Does NOT restore local files if they were deleted earlier.
	 * (v1 requirement: keep-local to allow clean remove)
	 *
	 * @param int $attachmentId WordPress attachment post ID
	 *
	 * @return bool True if all R2 objects deleted and meta cleared
	 */
	public function remove(int $attachmentId): bool
	{
		$meta = get_post_meta($attachmentId, '_imgpress_r2', true);

		if (!is_array($meta) || empty($meta['key'])) {
			return false;
		}

		// Delete original
		$result = $this->client->deleteObject($meta['key']);
		if (!$result['ok']) {
			error_log("[ImgPress R2] Failed to delete {$meta['key']} (attachment {$attachmentId}): " . ($result['error'] ?? 'Unknown error'));
			return false;
		}

		// Delete sub-sizes
		if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
			foreach ($meta['sizes'] as $sizeName => $key) {
				$result = $this->client->deleteObject($key);
				if (!$result['ok']) {
					error_log("[ImgPress R2] Failed to delete sub-size {$key} (size: {$sizeName}, attachment {$attachmentId}): " . ($result['error'] ?? 'Unknown error'));
					return false;
				}
			}
		}

		// Clear meta
		delete_post_meta($attachmentId, '_imgpress_r2');

		return true;
	}

	/**
	 * Get R2 upload status for attachment.
	 *
	 * @param int $attachmentId WordPress attachment post ID
	 *
	 * @return array{status, key, url, sizes, uploaded_at}|null Null if not uploaded
	 */
	public function getStatus(int $attachmentId): ?array
	{
		$meta = get_post_meta($attachmentId, '_imgpress_r2', true);

		if (!is_array($meta) || empty($meta['status'])) {
			return null;
		}

		return $meta;
	}

	/**
	 * Collect all files for an attachment: original + all sub-sizes.
	 * Returns array of {path, key, size} for each file.
	 *
	 * @param int $attachmentId WordPress attachment post ID
	 *
	 * @return array<int, array{path: string, key: string, size: string}> Files to upload
	 */
	private function collectFiles(int $attachmentId): array
	{
		$files = [];

		// Get attachment metadata and original file path
		$origPath = get_attached_file($attachmentId);
		if (!$origPath) {
			return [];
		}

		$meta = wp_get_attachment_metadata($attachmentId);
		if (!is_array($meta)) {
			$meta = [];
		}

		// Add original file
		$origKey = $this->deriveKey($origPath);
		$files[] = [
			'path' => $origPath,
			'key'  => $origKey,
			'size' => 'full',
		];

		// Add sub-sizes (thumbnails, medium, large, etc.)
		if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
			$uploads = wp_upload_dir();
			$basedir = trailingslashit($uploads['basedir']);

			// Get subdir from original path (e.g., '2026/01' from '2026/01/photo.webp')
			$relPath = _wp_relative_upload_path($origPath);
			$subdir = dirname($relPath);

			// Normalize subdir: '2026/01' or '.' (flat uploads)
			if ($subdir === '.') {
				$subdir = '';
			} else {
				$subdir = trailingslashit($subdir);
			}

			foreach ($meta['sizes'] as $sizeName => $sizeData) {
				if (!isset($sizeData['file'])) {
					continue;
				}

				// Construct full path to sub-size file
				$subPath = $basedir . $subdir . $sizeData['file'];

				// Derive R2 key: subdir + filename
				if ($subdir === '') {
					$subKey = $sizeData['file'];
				} else {
					$subKey = rtrim($subdir, '/') . '/' . $sizeData['file'];
				}

				$files[] = [
					'path' => $subPath,
					'key'  => $subKey,
					'size' => $sizeName,
				];
			}
		}

		return $files;
	}

	/**
	 * Derive R2 object key from local file path.
	 * Key is the uploads-relative path (e.g., '2026/01/photo.webp' or 'flat-uploads.jpg').
	 *
	 * @param string $filePath Absolute path to file
	 *
	 * @return string R2 object key
	 */
	private function deriveKey(string $filePath): string
	{
		$relPath = _wp_relative_upload_path($filePath);
		if (!$relPath) {
			// Fallback: use basename if relative path derivation fails
			return basename($filePath);
		}

		return $relPath;
	}

	/**
	 * Construct public URL for R2 object.
	 * Format: https://{public_base_url}/{key}
	 *
	 * @param string $key R2 object key
	 *
	 * @return string Public URL
	 */
	private function publicUrl(string $key): string
	{
		$baseUrl = method_exists($this->settings, 'getR2PublicBaseUrl')
			? $this->settings->getR2PublicBaseUrl()
			: (method_exists($this->settings, 'getR2CustomDomain') ? 'https://' . $this->settings->getR2CustomDomain() : '');

		if (empty($baseUrl)) {
			return '';
		}

		return rtrim($baseUrl, '/') . '/' . $this->getUrlEncodedKey(ltrim($key, '/'));
	}

	private function getUrlEncodedKey(string $key): string
	{
		$segments = explode('/', $key);
		$segments = array_map('rawurlencode', $segments);

		return implode('/', $segments);
	}

	/**
	 * Mark upload as failed without modifying local files.
	 *
	 * @param int $attachmentId WordPress attachment post ID
	 *
	 * @return void
	 */
	private function markUploadFailed(int $attachmentId): void
	{
		$meta = [
			'status' => 'failed',
		];

		update_post_meta($attachmentId, '_imgpress_r2', $meta);
	}

	private function keysFromMeta(array $meta): array
	{
		return array_values(array_filter(array_merge(
			[isset($meta['key']) ? (string) $meta['key'] : ''],
			array_values(is_array($meta['sizes'] ?? null) ? $meta['sizes'] : [])
		)));
	}

	private function cleanupDownloadedFiles(array $paths): void
	{
		foreach ($paths as $path) {
			if (file_exists($path)) {
				wp_delete_file($path);
			}
		}
	}
}
