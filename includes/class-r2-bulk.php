<?php

namespace ImgPress;

defined('ABSPATH') || exit;

/**
 * R2_Bulk — Orchestrates bulk offloading of the media library to Cloudflare R2.
 * Mirrors Bulk_Compress pattern: menu page + AJAX handlers for sequential batch processing.
 * Each file is uploaded individually with resumable semantics via post meta.
 */
class R2_Bulk
{
	public function __construct(
		private R2_Uploader $uploader,
		private $settings
	) {
		add_action('admin_menu', [$this, 'addMenuPage']);
		add_action('wp_ajax_imgpress_r2_bulk_get_ids', [$this, 'handleGetIds']);
		add_action('wp_ajax_imgpress_r2_bulk_push', [$this, 'handlePush']);
		add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
	}

	public function addMenuPage(): void
	{
		add_submenu_page(
			'imgpress',
			__('ImgPress R2 Offload', 'imgpress-wp'),
			__('R2 Offload', 'imgpress-wp'),
			'manage_options',
			'imgpress-r2-bulk',
			fn() => require IMGPRESS_WP_DIR . 'admin/page-r2-bulk.php'
		);
	}

	public function handleGetIds(): void
	{
		check_ajax_referer('imgpress_r2_bulk');

		if (!current_user_can('upload_files')) {
			wp_send_json_error('Unauthorized', 403);
		}

		if (!$this->settings->isR2Configured()) {
			wp_send_json_error('R2 is not configured');
		}

		$ids = $this->getPendingIds();
		wp_send_json_success(['ids' => $ids, 'total' => count($ids)]);
	}

	public function handlePush(): void
	{
		check_ajax_referer('imgpress_r2_bulk');

		if (!current_user_can('upload_files')) {
			wp_send_json_error('Unauthorized', 403);
		}

		if (!$this->settings->isR2Configured()) {
			wp_send_json_error('R2 is not configured');
		}

		$attachmentId = (int) ($_POST['id'] ?? 0);
		if (!$attachmentId) {
			wp_send_json_error('Invalid ID');
		}

		$ok     = $this->uploader->upload($attachmentId);
		$name   = get_the_title($attachmentId) ?: basename(get_attached_file($attachmentId) ?: '');
		$status = $this->uploader->getStatus($attachmentId);

		if ($ok && $status) {
			wp_send_json_success([
				'id'     => $attachmentId,
				'name'   => $name,
				'status' => $status['status'] ?? 'unknown',
				'url'    => $status['url'] ?? '',
			]);
		} else {
			wp_send_json_error(['id' => $attachmentId, 'name' => $name]);
		}
	}

	/**
	 * Get IDs of attachments pending offload to R2.
	 * A file is pending if: _imgpress_r2 meta doesn't exist OR status != 'uploaded'.
	 *
	 * @return array<int> Attachment post IDs
	 */
	private function getPendingIds(): array
	{
		$query = new \WP_Query([
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		]);

		return array_values(array_filter(array_map('intval', $query->posts), function (int $id): bool {
			$status = $this->uploader->getStatus($id);

			return !is_array($status) || ($status['status'] ?? '') !== 'uploaded';
		}));
	}

	public function enqueueAssets(string $hook): void
	{
		if ($hook !== 'imgpress_page_imgpress-r2-bulk') {
			return;
		}

		wp_enqueue_style(
			'imgpress-badges',
			IMGPRESS_WP_URL . 'assets/css/badges.css',
			[],
			IMGPRESS_WP_VERSION
		);
		wp_enqueue_style(
			'imgpress-bulk-results',
			IMGPRESS_WP_URL . 'assets/css/bulk-results.css',
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
			'imgpress-r2-bulk',
			IMGPRESS_WP_URL . 'assets/js/r2-bulk.js',
			['jquery'],
			IMGPRESS_WP_VERSION,
			true
		);

		wp_localize_script('imgpress-r2-bulk', 'ImgPressAdmin', [
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'nonce'   => wp_create_nonce('imgpress_r2_bulk'),
		]);

		wp_enqueue_script(
			'imgpress-admin',
			IMGPRESS_WP_URL . 'assets/admin.js',
			['jquery'],
			IMGPRESS_WP_VERSION,
			true
		);

		wp_localize_script('imgpress-admin', 'ImgPressMediaAdmin', [
			'ajaxUrl'   => admin_url('admin-ajax.php'),
			'nonce'     => wp_create_nonce('imgpress_r2_bulk'),
			'r2Nonce'   => wp_create_nonce('imgpress_r2'),
		]);
	}
}
