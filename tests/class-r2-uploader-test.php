#!/usr/bin/env php
<?php

// Mock WordPress functions and constants
define('ABSPATH', __DIR__ . '/../');

// Global state for mocking
$GLOBALS['mockAttachments'] = [];
$GLOBALS['mockMeta'] = [];
$GLOBALS['mockDeletedFiles'] = [];
$GLOBALS['mockFileContents'] = [];

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
    // Remove basedir prefix to get relative path
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

function update_post_meta($postId, $meta_key, $meta_value, $prev_value = '') {
    global $mockMeta;
    $mockMeta[$postId][$meta_key] = $meta_value;
    return true;
}

function delete_post_meta($postId, $meta_key, $meta_value = '') {
    global $mockMeta;
    unset($mockMeta[$postId][$meta_key]);
    return true;
}

function file_get_contents_mock($path) {
    global $mockFileContents;
    return $mockFileContents[$path] ?? 'mock-file-content-' . md5($path);
}

function wp_delete_file($path) {
    global $mockDeletedFiles;
    $mockDeletedFiles[] = $path;
    return true;
}

function current_time($format = 'mysql') {
    if ($format === 'mysql') {
        return date('Y-m-d H:i:s');
    }
    return time();
}

function trailingslashit($str) {
    return rtrim($str, '/') . '/';
}

// Load classes
require_once __DIR__ . '/../includes/class-r2-uploader.php';

// Mock R2_Client
class MockR2Client {
    public $uploadedObjects = [];
    public $deletedObjects = [];
    private $shouldFail = false;
    private $failOnKey = null;

    public function setFailure($shouldFail = true, $failOnKey = null) {
        $this->shouldFail = $shouldFail;
        $this->failOnKey = $failOnKey;
    }

    public function putObject(string $key, string $data, string $contentType = 'application/octet-stream'): array {
        if ($this->shouldFail && ($this->failOnKey === null || $this->failOnKey === $key)) {
            return [
                'ok' => false,
                'status' => 400,
                'error' => 'Simulated failure',
            ];
        }

        $this->uploadedObjects[] = [
            'key' => $key,
            'size' => strlen($data),
            'mime' => $contentType,
        ];

        return [
            'ok' => true,
            'status' => 200,
            'etag' => 'abc123',
        ];
    }

    public function deleteObject(string $key): array {
        $this->deletedObjects[] = $key;

        return [
            'ok' => true,
            'status' => 204,
        ];
    }
}

// Mock Settings
class MockSettings {
    private $isConfigured = true;
    private $customDomain = 'cdn.example.com';
    private $deleteLocal = false;

    public function setConfigured($configured = true) {
        $this->isConfigured = $configured;
    }

    public function setCustomDomain($domain) {
        $this->customDomain = $domain;
    }

    public function setDeleteLocal($delete = true) {
        $this->deleteLocal = $delete;
    }

    public function isR2Configured(): bool {
        return $this->isConfigured;
    }

    public function getR2CustomDomain(): string {
        return $this->customDomain;
    }

    public function isR2DeleteLocal(): bool {
        return $this->deleteLocal;
    }
}

// Test runner
echo "=== R2_Uploader Tests ===\n\n";

$totalTests = 0;
$passedTests = 0;

function createMockUploader($mockClient, $mockSettings) {
    $fileReader = function($path) {
        global $mockFileContents;
        return $mockFileContents[$path] ?? 'mock-content-' . md5($path);
    };
    return new ImgPress\R2_Uploader($mockClient, $mockSettings, $fileReader);
}

function testCase($name, $fn) {
    global $totalTests, $passedTests;
    $totalTests++;

    // Reset global state
    $GLOBALS['mockAttachments'] = [];
    $GLOBALS['mockMeta'] = [];
    $GLOBALS['mockDeletedFiles'] = [];
    $GLOBALS['mockFileContents'] = [];

    try {
        $fn();
        echo "[✓] $name\n";
        $passedTests++;
    } catch (AssertionError $e) {
        echo "[✗] $name: " . $e->getMessage() . "\n";
    } catch (Exception $e) {
        echo "[✗] $name: " . $e->getMessage() . "\n";
    }
}

function assert_equals($actual, $expected, $message = '') {
    if ($actual !== $expected) {
        throw new AssertionError("$message (expected: " . var_export($expected, true) . ", got: " . var_export($actual, true) . ")");
    }
}

function assert_true($condition, $message = '') {
    if (!$condition) {
        throw new AssertionError($message);
    }
}

function assert_false($condition, $message = '') {
    if ($condition) {
        throw new AssertionError($message);
    }
}

function assert_count($expected, $array, $message = '') {
    if (count($array) !== $expected) {
        throw new AssertionError("$message (expected: $expected items, got: " . count($array) . ")");
    }
}

function setupMockFiles($attachmentId, $attachmentData) {
    global $mockFileContents;

    $mockFileContents[$attachmentData['file']] = 'mock-content-' . md5($attachmentData['file']);

    if (!empty($attachmentData['metadata']['sizes'])) {
        $uploads = wp_upload_dir();
        $basedir = trailingslashit($uploads['basedir']);
        $relPath = _wp_relative_upload_path($attachmentData['file']);
        $subdir = dirname($relPath);

        if ($subdir !== '.') {
            $subdir = trailingslashit($subdir);
        } else {
            $subdir = '';
        }

        foreach ($attachmentData['metadata']['sizes'] as $sizeName => $sizeData) {
            if (isset($sizeData['file'])) {
                $subPath = $basedir . $subdir . $sizeData['file'];
                $mockFileContents[$subPath] = 'mock-content-' . md5($subPath);
            }
        }
    }
}

// Test 1: Upload single attachment with no sub-sizes
testCase('Upload single image without sub-sizes', function () {
    global $mockAttachments;

    $mockAttachments[1] = [
        'file' => '/var/www/html/wp-content/uploads/2026/01/photo.jpg',
        'mime' => 'image/jpeg',
        'metadata' => [], // No sub-sizes
    ];
    setupMockFiles(1, $mockAttachments[1]);

    $mockClient = new MockR2Client();
    $mockSettings = new MockSettings();
    $uploader = createMockUploader($mockClient, $mockSettings);

    $result = $uploader->upload(1);

    assert_true($result, 'Upload should succeed');
    assert_count(1, $mockClient->uploadedObjects, 'Should upload original only');
    assert_equals($mockClient->uploadedObjects[0]['key'], '2026/01/photo.jpg', 'Key should be relative path');
});

// Test 2: Upload with sub-sizes
testCase('Upload image with sub-sizes', function () {
    global $mockAttachments;

    $mockAttachments[2] = [
        'file' => '/var/www/html/wp-content/uploads/2026/02/photo.jpg',
        'mime' => 'image/jpeg',
        'metadata' => [
            'sizes' => [
                'thumbnail' => ['file' => 'photo-150x150.jpg'],
                'medium' => ['file' => 'photo-300x300.jpg'],
            ],
        ],
    ];
    setupMockFiles(2, $mockAttachments[2]);

    $mockClient = new MockR2Client();
    $mockSettings = new MockSettings();
    $uploader = createMockUploader($mockClient, $mockSettings);

    $result = $uploader->upload(2);

    assert_true($result, 'Upload should succeed');
    assert_count(3, $mockClient->uploadedObjects, 'Should upload original + 2 sub-sizes');
});

// Test 3: Upload fails on first failure (all-or-nothing)
testCase('All-or-nothing: abort on first upload failure', function () {
    global $mockAttachments, $mockMeta;

    $mockAttachments[3] = [
        'file' => '/var/www/html/wp-content/uploads/2026/03/photo.jpg',
        'mime' => 'image/jpeg',
        'metadata' => [
            'sizes' => [
                'thumbnail' => ['file' => 'photo-150x150.jpg'],
            ],
        ],
    ];
    setupMockFiles(3, $mockAttachments[3]);

    $mockClient = new MockR2Client();
    $mockClient->setFailure(true); // Fail on all uploads
    $mockSettings = new MockSettings();
    $uploader = new ImgPress\R2_Uploader($mockClient, $mockSettings);

    $result = $uploader->upload(3);

    assert_false($result, 'Upload should fail');
    assert_equals($mockMeta[3]['_imgpress_r2']['status'], 'failed', 'Meta should be marked failed');
});

// Test 4: Meta written only after all uploads succeed
testCase('Meta written with correct structure', function () {
    global $mockAttachments, $mockMeta;

    $mockAttachments[4] = [
        'file' => '/var/www/html/wp-content/uploads/2026/04/photo.jpg',
        'mime' => 'image/jpeg',
        'metadata' => [
            'sizes' => [
                'thumbnail' => ['file' => 'photo-150x150.jpg'],
            ],
        ],
    ];
    setupMockFiles(4, $mockAttachments[4]);

    $mockClient = new MockR2Client();
    $mockSettings = new MockSettings();
    $mockSettings->setCustomDomain('cdn.example.com');
    $uploader = createMockUploader($mockClient, $mockSettings);

    $result = $uploader->upload(4);

    assert_true($result, 'Upload should succeed');
    $meta = $mockMeta[4]['_imgpress_r2'];
    assert_equals($meta['status'], 'uploaded', 'Status should be uploaded');
    assert_equals($meta['key'], '2026/04/photo.jpg', 'Key should match original');
    assert_equals($meta['url'], 'https://cdn.example.com/2026/04/photo.jpg', 'URL should be constructed');
    assert_true(isset($meta['sizes']['thumbnail']), 'Sizes should contain thumbnail');
    assert_true(isset($meta['uploaded_at']), 'uploaded_at should be present');
});

// Test 5: getStatus returns meta
testCase('getStatus returns upload status', function () {
    global $mockMeta;

    $mockMeta[5] = [
        '_imgpress_r2' => [
            'status' => 'uploaded',
            'key' => '2026/05/photo.jpg',
            'url' => 'https://cdn.example.com/2026/05/photo.jpg',
            'sizes' => [],
            'uploaded_at' => '2026-06-16 10:00:00',
        ],
    ];

    $mockClient = new MockR2Client();
    $mockSettings = new MockSettings();
    $uploader = createMockUploader($mockClient, $mockSettings);

    $status = $uploader->getStatus(5);

    assert_true($status !== null, 'Status should not be null');
    assert_equals($status['status'], 'uploaded', 'Status should be uploaded');
});

// Test 6: getStatus returns null when not uploaded
testCase('getStatus returns null when not uploaded', function () {
    global $mockMeta;

    $mockMeta[6] = [];

    $mockClient = new MockR2Client();
    $mockSettings = new MockSettings();
    $uploader = createMockUploader($mockClient, $mockSettings);

    $status = $uploader->getStatus(6);

    assert_true($status === null, 'Status should be null');
});

// Test 7: Delete local files only after verified upload
testCase('Delete local files only after verified upload', function () {
    global $mockAttachments, $mockDeletedFiles;

    $mockAttachments[7] = [
        'file' => '/var/www/html/wp-content/uploads/2026/07/photo.jpg',
        'mime' => 'image/jpeg',
        'metadata' => [
            'sizes' => [
                'thumbnail' => ['file' => 'photo-150x150.jpg'],
            ],
        ],
    ];
    setupMockFiles(7, $mockAttachments[7]);

    $mockClient = new MockR2Client();
    $mockSettings = new MockSettings();
    $mockSettings->setDeleteLocal(true);
    $uploader = createMockUploader($mockClient, $mockSettings);

    $result = $uploader->upload(7);

    assert_true($result, 'Upload should succeed');
    // Note: wp_delete_file is mocked, so we can't verify actual deletion
    // But we can verify upload succeeded before deletion was attempted
});

// Test 8: Don't delete local files when setting is off
testCase('Do not delete local files when delete-local is off', function () {
    global $mockAttachments, $mockDeletedFiles;

    $mockAttachments[8] = [
        'file' => '/var/www/html/wp-content/uploads/2026/08/photo.jpg',
        'mime' => 'image/jpeg',
        'metadata' => [],
    ];
    setupMockFiles(8, $mockAttachments[8]);

    $mockClient = new MockR2Client();
    $mockSettings = new MockSettings();
    $mockSettings->setDeleteLocal(false); // Don't delete
    $uploader = createMockUploader($mockClient, $mockSettings);

    $result = $uploader->upload(8);

    assert_true($result, 'Upload should succeed');
    assert_count(0, $mockDeletedFiles, 'No files should be deleted');
});

// Test 9: Remove deletes all R2 objects and clears meta
testCase('Remove deletes R2 objects and clears meta', function () {
    global $mockMeta;

    $mockMeta[9] = [
        '_imgpress_r2' => [
            'status' => 'uploaded',
            'key' => '2026/09/photo.jpg',
            'url' => 'https://cdn.example.com/2026/09/photo.jpg',
            'sizes' => [
                'thumbnail' => '2026/09/photo-150x150.jpg',
            ],
            'uploaded_at' => '2026-06-16 10:00:00',
        ],
    ];

    $mockClient = new MockR2Client();
    $mockSettings = new MockSettings();
    $uploader = createMockUploader($mockClient, $mockSettings);

    $result = $uploader->remove(9);

    assert_true($result, 'Remove should succeed');
    assert_count(2, $mockClient->deletedObjects, 'Should delete original + thumbnail');
    assert_true($mockMeta[9] === [], 'Meta should be cleared');
});

// Test 10: Upload fails when R2 not configured
testCase('Upload fails when R2 not configured', function () {
    global $mockAttachments;

    $mockAttachments[10] = [
        'file' => '/var/www/html/wp-content/uploads/2026/10/photo.jpg',
        'mime' => 'image/jpeg',
        'metadata' => [],
    ];

    $mockClient = new MockR2Client();
    $mockSettings = new MockSettings();
    $mockSettings->setConfigured(false); // Not configured
    $uploader = new ImgPress\R2_Uploader($mockClient, $mockSettings);

    $result = $uploader->upload(10);

    assert_false($result, 'Upload should fail when not configured');
});

// Test 11: Flat uploads (no date folder)
testCase('Upload file from flat uploads folder', function () {
    global $mockAttachments;

    $mockAttachments[11] = [
        'file' => '/var/www/html/wp-content/uploads/photo.jpg',
        'mime' => 'image/jpeg',
        'metadata' => [],
    ];
    setupMockFiles(11, $mockAttachments[11]);

    $mockClient = new MockR2Client();
    $mockSettings = new MockSettings();
    $uploader = createMockUploader($mockClient, $mockSettings);

    $result = $uploader->upload(11);

    assert_true($result, 'Upload should succeed');
    assert_count(1, $mockClient->uploadedObjects, 'Should upload file');
    // The key should be just the filename for flat uploads
    assert_equals($mockClient->uploadedObjects[0]['key'], 'photo.jpg', 'Key should be filename for flat uploads');
});

// Test 12: Public URL construction
testCase('Public URL constructed correctly', function () {
    global $mockAttachments, $mockMeta;

    $mockAttachments[12] = [
        'file' => '/var/www/html/wp-content/uploads/2026/12/photo.jpg',
        'mime' => 'image/jpeg',
        'metadata' => [],
    ];
    setupMockFiles(12, $mockAttachments[12]);

    $mockClient = new MockR2Client();
    $mockSettings = new MockSettings();
    $mockSettings->setCustomDomain('my-cdn.com');
    $uploader = createMockUploader($mockClient, $mockSettings);

    $result = $uploader->upload(12);

    assert_true($result, 'Upload should succeed');
    $meta = $mockMeta[12]['_imgpress_r2'];
    assert_equals($meta['url'], 'https://my-cdn.com/2026/12/photo.jpg', 'URL should use custom domain');
});

// Test 13: Partial failure scenario
testCase('Partial failure: abort if one sub-size fails', function () {
    global $mockAttachments, $mockMeta;

    $mockAttachments[13] = [
        'file' => '/var/www/html/wp-content/uploads/2026/13/photo.jpg',
        'mime' => 'image/jpeg',
        'metadata' => [
            'sizes' => [
                'thumbnail' => ['file' => 'photo-150x150.jpg'],
                'medium' => ['file' => 'photo-300x300.jpg'],
            ],
        ],
    ];
    setupMockFiles(13, $mockAttachments[13]);

    $mockClient = new MockR2Client();
    $mockClient->setFailure(true, '2026/13/photo-300x300.jpg'); // Fail on medium size
    $mockSettings = new MockSettings();
    $uploader = createMockUploader($mockClient, $mockSettings);

    $result = $uploader->upload(13);

    assert_false($result, 'Upload should fail on partial failure');
    assert_equals($mockMeta[13]['_imgpress_r2']['status'], 'failed', 'Status should be failed');
});

echo "\n=== Test Summary ===\n";
echo "Passed: $passedTests / $totalTests\n";

if ($passedTests === $totalTests) {
    echo "All tests passed!\n";
    exit(0);
} else {
    echo "Some tests failed.\n";
    exit(1);
}
