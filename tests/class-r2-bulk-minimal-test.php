#!/usr/bin/env php
<?php

/**
 * Minimal unit tests for R2_Bulk class
 * Tests core functionality without full WordPress mocking
 */

define('ABSPATH', __DIR__ . '/../');
define('IMGPRESS_WP_DIR', __DIR__ . '/../');
define('IMGPRESS_WP_VERSION', '1.1.0');

// Test 1: Verify R2_Bulk class exists and can be instantiated
echo "Test 1: R2_Bulk class exists\n";
require_once IMGPRESS_WP_DIR . 'includes/class-r2-bulk.php';
if (class_exists('ImgPress\R2_Bulk')) {
	echo "  ✓ R2_Bulk class found\n";
} else {
	echo "  ✗ R2_Bulk class not found\n";
	exit(1);
}

// Test 2: Verify constructor takes R2_Uploader and Settings
echo "Test 2: R2_Bulk constructor signature\n";
$reflection = new ReflectionClass('ImgPress\R2_Bulk');
$constructor = $reflection->getConstructor();
$params = $constructor->getParameters();

if (count($params) === 2) {
	echo "  ✓ Constructor has 2 parameters\n";
} else {
	echo "  ✗ Constructor has " . count($params) . " parameters (expected 2)\n";
	exit(1);
}

// Test 3: Verify methods exist
echo "Test 3: R2_Bulk methods exist\n";
$requiredMethods = ['addMenuPage', 'handleGetIds', 'handlePush', 'enqueueAssets'];
foreach ($requiredMethods as $method) {
	if ($reflection->hasMethod($method)) {
		echo "  ✓ Method '$method' exists\n";
	} else {
		echo "  ✗ Method '$method' missing\n";
		exit(1);
	}
}

// Test 4: Verify private method getPendingIds exists
echo "Test 4: R2_Bulk getPendingIds method\n";
if ($reflection->hasMethod('getPendingIds')) {
	$method = $reflection->getMethod('getPendingIds');
	if (!$method->isPublic()) {
		echo "  ✓ getPendingIds is private/protected (good encapsulation)\n";
	} else {
		echo "  ✓ getPendingIds exists\n";
	}
} else {
	echo "  ✗ getPendingIds method missing\n";
	exit(1);
}

// Test 5: Verify class-r2-bulk.php has no syntax errors
echo "Test 5: PHP syntax validation\n";
$output = [];
$return = 0;
exec("php -l " . escapeshellarg(IMGPRESS_WP_DIR . 'includes/class-r2-bulk.php') . " 2>&1", $output, $return);

if ($return === 0) {
	echo "  ✓ class-r2-bulk.php has no syntax errors\n";
} else {
	echo "  ✗ Syntax error in class-r2-bulk.php:\n";
	foreach ($output as $line) {
		echo "    $line\n";
	}
	exit(1);
}

// Test 6: Verify page template exists
echo "Test 6: R2 bulk page template\n";
if (file_exists(IMGPRESS_WP_DIR . 'admin/page-r2-bulk.php')) {
	echo "  ✓ admin/page-r2-bulk.php exists\n";
} else {
	echo "  ✗ admin/page-r2-bulk.php not found\n";
	exit(1);
}

// Test 7: Verify page template has required elements
echo "Test 7: R2 bulk page template content\n";
$pageContent = file_get_contents(IMGPRESS_WP_DIR . 'admin/page-r2-bulk.php');

$requiredElements = [
	'ip-r2-pending-count' => 'pending count element',
	'ip-r2-progress-bar'  => 'progress bar element',
	'ip-r2-bulk-btn'      => 'start button element',
	'ip-r2-results-tbody' => 'results table body element',
];

foreach ($requiredElements as $id => $desc) {
	if (strpos($pageContent, "id=\"$id\"") !== false) {
		echo "  ✓ $desc ($id) found\n";
	} else {
		echo "  ✗ $desc ($id) missing\n";
		exit(1);
	}
}

// Test 8: Verify admin.js has R2 bulk handler
echo "Test 8: admin.js R2 bulk handler\n";
$jsContent = file_get_contents(IMGPRESS_WP_DIR . 'assets/admin.js');

$requiredJsElements = [
	'processNextR2'   => 'processNextR2 function',
	'ip-r2-bulk-btn' => 'button selector',
	'imgpress_r2_bulk_push' => 'AJAX action',
];

foreach ($requiredJsElements as $elem => $desc) {
	if (strpos($jsContent, $elem) !== false) {
		echo "  ✓ $desc ($elem) found\n";
	} else {
		echo "  ✗ $desc ($elem) missing\n";
		exit(1);
	}
}

// Test 9: Verify main plugin file has R2_Bulk DI
echo "Test 9: Plugin DI container\n";
$pluginContent = file_get_contents(IMGPRESS_WP_DIR . 'imgpress-wp.php');

if (strpos($pluginContent, 'new ImgPress\R2_Bulk') !== false) {
	echo "  ✓ R2_Bulk DI wiring found\n";
} else {
	echo "  ✗ R2_Bulk DI wiring missing\n";
	exit(1);
}

// Test 10: Verify documentation exists
echo "Test 10: Documentation files\n";
$docFiles = [
	'docs/r2-setup-guide.md'   => 'Setup guide',
	'docs/r2-architecture.md'  => 'Architecture docs',
	'docs/r2-faq.md'           => 'FAQ',
];

foreach ($docFiles as $path => $desc) {
	if (file_exists(IMGPRESS_WP_DIR . $path)) {
		$content = file_get_contents(IMGPRESS_WP_DIR . $path);
		$wordCount = str_word_count($content);
		echo "  ✓ $desc exists ($wordCount words)\n";
	} else {
		echo "  ✗ $desc missing ($path)\n";
		exit(1);
	}
}

// Test 11: Verify README was updated
echo "Test 11: README R2 section\n";
$readmeContent = file_get_contents(IMGPRESS_WP_DIR . 'README.md');

if (strpos($readmeContent, 'Cloudflare R2') !== false) {
	echo "  ✓ README mentions Cloudflare R2\n";
} else {
	echo "  ✗ README missing R2 documentation\n";
	exit(1);
}

if (strpos($readmeContent, 'class-r2-bulk.php') !== false) {
	echo "  ✓ README file structure includes R2_Bulk\n";
} else {
	echo "  ✗ README file structure missing R2_Bulk\n";
	exit(1);
}

// Test 12: Verify settings page link was added
echo "Test 12: Settings page R2 link\n";
$settingsContent = file_get_contents(IMGPRESS_WP_DIR . 'admin/page-settings.php');

if (strpos($settingsContent, 'imgpress-r2-bulk') !== false) {
	echo "  ✓ Settings page has link to R2 bulk offload\n";
} else {
	echo "  ✗ Settings page missing R2 bulk offload link\n";
	exit(1);
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "✓ All 12 tests passed!\n";
echo str_repeat("=", 60) . "\n";
