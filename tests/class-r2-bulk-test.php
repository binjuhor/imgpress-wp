#!/usr/bin/env php
<?php

// Mock WordPress functions and constants
define('ABSPATH', __DIR__ . '/../');
define('IMGPRESS_WP_DIR', __DIR__ . '/../');
define('IMGPRESS_WP_URL', 'https://example.com/wp-content/plugins/imgpress-wp/');
define('IMGPRESS_WP_VERSION', '1.1.0');

// Global state for mocking
$GLOBALS['mockAttachments'] = [];
$GLOBALS['mockMeta'] = [];
$GLOBALS['mockOptions'] = [];
$GLOBALS['test_results'] = [];

// Mock WordPress functions
function get_option($option, $default = false) {
	global $mockOptions;
	return $mockOptions[$option] ?? $default;
}

function add_option($option, $value) {
	global $mockOptions;
	$mockOptions[$option] = $value;
	return true;
}

function update_option($option, $value) {
	global $mockOptions;
	$mockOptions[$option] = $value;
	return true;
}

function get_attached_file($attachmentId) {
	global $mockAttachments;
	return $mockAttachments[$attachmentId]['file'] ?? null;
}

function get_post_mime_type($attachmentId) {
	global $mockAttachments;
	return $mockAttachments[$attachmentId]['mime'] ?? null;
}

function wp_get_attachment_metadata($attachmentId) {
	global $mockAttachments;
	return $mockAttachments[$attachmentId]['metadata'] ?? [];
}

function wp_upload_dir() {
	return [
		'basedir' => '/var/www/html/wp-content/uploads',
		'baseurl' => 'https://example.com/wp-content/uploads',
	];
}

function _wp_relative_upload_path($filePath) {
	$basedir = '/var/www/html/wp-content/uploads/';
	if (str_starts_with($filePath, $basedir)) {
		return substr($filePath, strlen($basedir));
	}
	return basename($filePath);
}

function get_post_meta($postId, $meta_key, $single = false) {
	global $mockMeta;
	if ($single) {
		return $mockMeta[$postId][$meta_key] ?? null;
	}
	return $mockMeta[$postId][$meta_key] ?? [];
}

function update_post_meta($postId, $meta_key, $meta_value) {
	global $mockMeta;
	if (!isset($mockMeta[$postId])) {
		$mockMeta[$postId] = [];
	}
	$mockMeta[$postId][$meta_key] = $meta_value;
	return true;
}

function delete_post_meta($postId, $meta_key) {
	global $mockMeta;
	if (isset($mockMeta[$postId][$meta_key])) {
		unset($mockMeta[$postId][$meta_key]);
	}
	return true;
}

function get_the_title($postId) {
	global $mockAttachments;
	return $mockAttachments[$postId]['title'] ?? null;
}

function add_action($hook, $function_to_add, $priority = 10, $accepted_args = 1) {
	// No-op for testing
	return true;
}

function add_media_page($page_title, $menu_title, $capability, $menu_slug, $function) {
	// No-op for testing
	return true;
}

function add_filter($hook, $function_to_add, $priority = 10, $accepted_args = 1) {
	// No-op for testing
	return true;
}

function check_ajax_referer($nonce) {
	// No-op for testing
	return true;
}

function current_user_can($capability) {
	return true;
}

function wp_send_json_error($data = '', $status_code = null) {
	throw new Exception("AJAX Error: {$data}");
}

function wp_send_json_success($data = null) {
	global $test_results;
	$test_results['ajax_response'] = ['success' => true, 'data' => $data];
}

function current_time($type = 'mysql', $gmt = 0) {
	return date($type === 'mysql' ? 'Y-m-d H:i:s' : 'U');
}

function trailingslashit($string) {
	return rtrim($string, '/') . '/';
}

class WP_Query {
	public $posts = [];

	public function __construct($args) {
		global $mockMeta;

		// Simulate WP_Query for attachment post type
		if (($args['post_type'] ?? '') === 'attachment') {
			$ids = array_keys($GLOBALS['mockAttachments'] ?? []);

			if (!empty($args['meta_query'])) {
				// Filter by meta_query
				$meta_query = $args['meta_query'];

				foreach ($ids as $id) {
					$include = true;

					// Simple meta_query logic (supports basic NOT EXISTS and OR relation)
					foreach ($meta_query as $query) {
						if (!is_array($query)) {
							continue;
						}

						$compare = $query['compare'] ?? 'EXISTS';

						if ($compare === 'NOT EXISTS') {
							if (isset($mockMeta[$id][$query['key'] ?? ''])) {
								$include = false;
							}
						} elseif ($compare === 'NOT LIKE') {
							$meta_val = get_post_meta($id, $query['key'] ?? '', true);
							if (is_array($meta_val)) {
								$meta_val = json_encode($meta_val);
							}
							if (strpos((string) $meta_val, $query['value'] ?? '') !== false) {
								$include = false;
							}
						}
					}

					if ($include) {
						$this->posts[] = $id;
					}
				}
			} else {
				$this->posts = $ids;
			}
		}
	}
}

class MockR2Client {
	public function putObject($key, $data, $mime) {
		return ['ok' => true];
	}

	public function deleteObject($key) {
		return ['ok' => true];
	}

	public function headBucket() {
		return ['ok' => true];
	}
}

class MockSettings {
	private $opts;

	public function __construct() {
		$this->opts = [
			'r2_enabled'       => true,
			'r2_account_id'    => 'test123',
			'r2_access_key'    => 'key',
			'r2_secret_key'    => 'secret',
			'r2_bucket'        => 'test-bucket',
			'r2_custom_domain' => 'cdn.example.com',
			'r2_delete_local'  => false,
		];
	}

	public function isR2Configured(): bool {
		return $this->opts['r2_enabled']
			&& !empty($this->opts['r2_account_id'])
			&& !empty($this->opts['r2_access_key'])
			&& !empty($this->opts['r2_secret_key'])
			&& !empty($this->opts['r2_bucket'])
			&& !empty($this->opts['r2_custom_domain']);
	}

	public function getR2CustomDomain(): string {
		return $this->opts['r2_custom_domain'] ?? '';
	}

	public function isR2DeleteLocal(): bool {
		return $this->opts['r2_delete_local'] ?? false;
	}
}

class MockR2Uploader {
	private $uploadedFiles = [];

	public function upload(int $attachmentId): bool {
		$this->uploadedFiles[] = $attachmentId;
		update_post_meta($attachmentId, '_imgpress_r2', [
			'status'      => 'uploaded',
			'key'         => '2026/01/test.jpg',
			'url'         => 'https://cdn.example.com/2026/01/test.jpg',
			'sizes'       => [],
			'uploaded_at' => current_time('mysql'),
		]);
		return true;
	}

	public function getStatus(int $attachmentId): ?array {
		return get_post_meta($attachmentId, '_imgpress_r2', true);
	}

	public function remove(int $attachmentId): bool {
		delete_post_meta($attachmentId, '_imgpress_r2');
		return true;
	}

	public function getUploadedCount(): int {
		return count($this->uploadedFiles);
	}
}

// Load the real R2_Uploader and R2_Bulk classes
require_once IMGPRESS_WP_DIR . 'includes/class-r2-uploader.php';
require_once IMGPRESS_WP_DIR . 'includes/class-r2-bulk.php';

// Test class
class TestR2Bulk {
	private $r2Uploader;
	private $settings;

	public function __construct() {
		// Use real R2_Uploader with mock R2_Client
		$this->settings = new MockSettings();
		$r2Client = new MockR2Client();
		$this->r2Uploader = new ImgPress\R2_Uploader($r2Client, $this->settings);
	}

	public function testGetPendingIds() {
		// Setup: Mock 3 attachments (1 uploaded, 2 pending)
		global $mockAttachments, $mockMeta;

		$mockAttachments = [
			1 => ['file' => '/var/www/html/wp-content/uploads/2026/01/photo1.jpg', 'mime' => 'image/jpeg', 'title' => 'Photo 1', 'metadata' => []],
			2 => ['file' => '/var/www/html/wp-content/uploads/2026/01/photo2.jpg', 'mime' => 'image/jpeg', 'title' => 'Photo 2', 'metadata' => []],
			3 => ['file' => '/var/www/html/wp-content/uploads/2026/01/photo3.jpg', 'mime' => 'image/jpeg', 'title' => 'Photo 3', 'metadata' => []],
		];

		// Mark attachment 1 as uploaded
		$mockMeta[1] = ['_imgpress_r2' => ['status' => 'uploaded', 'key' => '2026/01/photo1.jpg', 'url' => 'https://cdn.example.com/2026/01/photo1.jpg']];

		// Create R2_Bulk instance and test getPendingIds
		$r2Bulk = new ImgPress\R2_Bulk($this->r2Uploader, $this->settings);

		// Use reflection to call private getPendingIds method
		$reflection = new ReflectionClass('ImgPress\R2_Bulk');
		$method = $reflection->getMethod('getPendingIds');
		$method->setAccessible(true);

		$pending = $method->invoke($r2Bulk);

		// Verify: Should return IDs 2 and 3 (not uploaded)
		if (count($pending) !== 2) {
			throw new Exception("getPendingIds should return 2 pending files, got " . count($pending));
		}
		if (!in_array(2, $pending)) {
			throw new Exception("ID 2 should be pending");
		}
		if (!in_array(3, $pending)) {
			throw new Exception("ID 3 should be pending");
		}
		if (in_array(1, $pending)) {
			throw new Exception("ID 1 (uploaded) should not be pending");
		}

		return "✓ testGetPendingIds passed";
	}

	public function testHandleGetIds() {
		// Setup
		global $mockAttachments, $mockMeta, $test_results;
		$mockAttachments = [
			1 => ['file' => '/var/www/html/wp-content/uploads/2026/01/test.jpg', 'mime' => 'image/jpeg', 'title' => 'Test', 'metadata' => []],
		];
		$mockMeta = [];
		$test_results = [];

		// Simulate AJAX call
		$_POST['_ajax_nonce'] = 'test_nonce';
		$r2Bulk = new ImgPress\R2_Bulk($this->r2Uploader, $this->settings);
		$r2Bulk->handleGetIds();

		// Verify response
		$this->assert(isset($test_results['ajax_response']), "AJAX response should be set");
		$this->assert($test_results['ajax_response']['success'] === true, "AJAX should succeed");
		$this->assert(count($test_results['ajax_response']['data']['ids']) === 1, "Should return 1 pending ID");

		return "✓ testHandleGetIds passed";
	}

	public function testHandlePush() {
		// Setup
		global $mockAttachments, $mockMeta, $test_results;
		$mockAttachments = [
			1 => ['file' => '/var/www/html/wp-content/uploads/2026/01/test.jpg', 'mime' => 'image/jpeg', 'title' => 'Test Photo', 'metadata' => []],
		];
		$mockMeta = [];
		$test_results = [];

		// Simulate AJAX call
		$_POST['_ajax_nonce'] = 'test_nonce';
		$_POST['id'] = 1;

		$r2Bulk = new ImgPress\R2_Bulk($this->r2Uploader, $this->settings);
		$r2Bulk->handlePush();

		// Verify response
		$this->assert(isset($test_results['ajax_response']), "AJAX response should be set");
		$this->assert($test_results['ajax_response']['success'] === true, "AJAX should succeed");
		$this->assert($test_results['ajax_response']['data']['id'] === 1, "Response should contain attachment ID");
		$this->assert($test_results['ajax_response']['data']['name'] === 'Test Photo', "Response should contain attachment name");
		$this->assert(strpos($test_results['ajax_response']['data']['url'], 'https://cdn.example.com') === 0, "URL should be from custom domain");

		// Verify metadata was written
		$meta = get_post_meta(1, '_imgpress_r2', true);
		$this->assert($meta['status'] === 'uploaded', "Meta status should be 'uploaded'");

		return "✓ testHandlePush passed";
	}

	public function testUploaderUpload() {
		// Setup
		global $mockAttachments, $mockMeta;
		$mockAttachments = [
			1 => [
				'file'     => '/var/www/html/wp-content/uploads/2026/01/test.jpg',
				'mime'     => 'image/jpeg',
				'title'    => 'Test',
				'metadata' => ['sizes' => ['thumbnail' => ['file' => 'test-150x150.jpg']]],
			],
		];
		$mockMeta = [];

		$r2Client = new MockR2Client();
		$r2Uploader = new ImgPress\R2_Uploader($r2Client, $this->settings);

		$result = $r2Uploader->upload(1);

		// Verify: Upload should succeed
		$this->assert($result === true, "Upload should return true");

		// Verify: Meta should be written
		$meta = get_post_meta(1, '_imgpress_r2', true);
		$this->assert($meta !== null, "Meta should be written");
		$this->assert($meta['status'] === 'uploaded', "Status should be 'uploaded'");
		$this->assert(!empty($meta['url']), "URL should be set");

		// Verify: Re-running getPendingIds should exclude this file
		$pending = $r2Uploader->getStatus(1);
		$this->assert($pending !== null, "getStatus should return meta");

		return "✓ testUploaderUpload passed";
	}

	public function testResumable() {
		// Setup: Upload one file, then simulate resuming with new files
		global $mockAttachments, $mockMeta;

		$mockAttachments = [
			1 => ['file' => '/var/www/html/wp-content/uploads/2026/01/photo1.jpg', 'mime' => 'image/jpeg', 'title' => 'Photo 1', 'metadata' => []],
			2 => ['file' => '/var/www/html/wp-content/uploads/2026/01/photo2.jpg', 'mime' => 'image/jpeg', 'title' => 'Photo 2', 'metadata' => []],
		];

		$r2Client = new MockR2Client();
		$r2Uploader = new ImgPress\R2_Uploader($r2Client, $this->settings);

		// First run: Upload file 1
		$r2Uploader->upload(1);
		$meta1 = get_post_meta(1, '_imgpress_r2', true);
		$this->assert($meta1['status'] === 'uploaded', "File 1 should be uploaded");

		// Simulate resuming: Get pending again
		$r2Bulk = new ImgPress\R2_Bulk($r2Uploader, $this->settings);
		$reflection = new ReflectionClass('ImgPress\R2_Bulk');
		$method = $reflection->getMethod('getPendingIds');
		$method->setAccessible(true);
		$pending = $method->invoke($r2Bulk);

		// Verify: Only file 2 should be pending
		$this->assert(count($pending) === 1, "Only 1 file should be pending, got " . count($pending));
		$this->assert(in_array(2, $pending), "File 2 should be pending");
		$this->assert(!in_array(1, $pending), "File 1 (already uploaded) should not be pending");

		return "✓ testResumable passed";
	}

	private function assert($condition, $message) {
		if (!$condition) {
			throw new Exception("ASSERTION FAILED: {$message}");
		}
	}

	public function runAllTests() {
		$tests = [
			'testGetPendingIds',
			'testHandleGetIds',
			'testHandlePush',
			'testUploaderUpload',
			'testResumable',
		];

		$results = [];
		foreach ($tests as $test) {
			try {
				$results[] = $this->$test();
			} catch (Exception $e) {
				$results[] = "✗ {$test} failed: " . $e->getMessage();
			}
		}

		return $results;
	}
}

// Run tests
if (php_sapi_name() === 'cli') {
	$tester = new TestR2Bulk();
	$results = $tester->runAllTests();
	foreach ($results as $result) {
		echo $result . "\n";
	}
	echo "\n" . count($results) . " tests completed.\n";
}
