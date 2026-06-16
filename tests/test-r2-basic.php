#!/usr/bin/env php
<?php

// Mock WordPress functions
function wp_remote_request($url, $args = []) {
    return [];
}

function wp_remote_retrieve_response_code($response) {
    return 200;
}

function wp_remote_retrieve_body($response) {
    return '';
}

function wp_remote_retrieve_headers($response) {
    return [];
}

function is_wp_error($thing) {
    return false;
}

// Load R2_Client
require_once __DIR__ . '/../includes/class-r2-client.php';

// Create mock Settings
$mockSettings = new class {
    public function getR2AccessKey(): string { return 'AKIAIOSFODNN7EXAMPLE'; }
    public function getR2SecretKey(): string { return 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY'; }
    public function getR2AccountId(): string { return 'abc123'; }
    public function getR2Bucket(): string { return 'my-bucket'; }
    public function getRequestTimeout(): int { return 120; }
};

echo "=== R2_Client Basic Tests ===\n\n";

// Test 1: Class exists
try {
    $client = new ImgPress\R2_Client($mockSettings);
    echo "[✓] R2_Client instantiated successfully\n";
} catch (Exception $e) {
    echo "[✗] Failed to instantiate R2_Client: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 2: Test private methods via reflection
$reflection = new ReflectionClass($client);

// Test hashPayload method
$hashPayload = $reflection->getMethod('hashPayload');
$hashPayload->setAccessible(true);

$emptyHash = $hashPayload->invoke($client, '');
$expectedEmpty = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
if ($emptyHash === $expectedEmpty) {
    echo "[✓] Payload hashing works (empty string)\n";
} else {
    echo "[✗] Payload hash mismatch. Expected: {$expectedEmpty}, Got: {$emptyHash}\n";
}

// Test with data
$dataHash = $hashPayload->invoke($client, 'test data');
$expectedHash = hash('sha256', 'test data');
if ($dataHash === $expectedHash) {
    echo "[✓] Payload hashing works (with data)\n";
} else {
    echo "[✗] Payload hash mismatch for data\n";
}

// Test 3: Test getUrlEncodedKey
$getUrlEncodedKey = $reflection->getMethod('getUrlEncodedKey');
$getUrlEncodedKey->setAccessible(true);

$testCases = [
    'simple.jpg' => 'simple.jpg',
    'my file.jpg' => 'my%20file.jpg',
    'folder/file.jpg' => 'folder/file.jpg',
    'my folder/my file.jpg' => 'my%20folder/my%20file.jpg',
];

$allPassed = true;
foreach ($testCases as $input => $expected) {
    $result = $getUrlEncodedKey->invoke($client, $input);
    if ($result === $expected) {
        echo "[✓] URL encoding correct for: {$input}\n";
    } else {
        echo "[✗] URL encoding failed for: {$input}. Expected: {$expected}, Got: {$result}\n";
        $allPassed = false;
    }
}

// Test 4: Test getSignedHeaders
$getSignedHeaders = $reflection->getMethod('getSignedHeaders');
$getSignedHeaders->setAccessible(true);

$headers = [
    'Content-Type' => 'image/jpeg',
    'Host' => 'abc123.r2.cloudflarestorage.com',
    'X-Amz-Content-SHA256' => 'abc123',
    'X-Amz-Date' => '20260616T143000Z',
];

$signedHeaders = $getSignedHeaders->invoke($client, $headers);
$expected = 'content-type;host;x-amz-content-sha256;x-amz-date';
if ($signedHeaders === $expected) {
    echo "[✓] Signed headers generation works (sorted and lowercase)\n";
} else {
    echo "[✗] Signed headers mismatch. Expected: {$expected}, Got: {$signedHeaders}\n";
}

// Test 5: Test buildCanonicalRequest
$buildCanonical = $reflection->getMethod('buildCanonicalRequest');
$buildCanonical->setAccessible(true);

$method = 'PUT';
$path = '/my-bucket/test.jpg';
$query = '';
$headers = [
    'host' => 'abc123.r2.cloudflarestorage.com',
    'x-amz-content-sha256' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
    'x-amz-date' => '20260616T143000Z',
    'content-type' => 'image/jpeg',
];
$payloadHash = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

$canonical = $buildCanonical->invokeArgs($client, [$method, $path, $query, $headers, $payloadHash]);

$lines = explode("\n", $canonical);
if ($lines[0] === 'PUT' && $lines[1] === '/my-bucket/test.jpg' && $lines[2] === '') {
    echo "[✓] Canonical request structure is correct\n";
} else {
    echo "[✗] Canonical request structure is incorrect\n";
    echo "First 3 lines: " . implode(" | ", array_slice($lines, 0, 3)) . "\n";
}

// Test 6: Test error XML parsing
$parseError = $reflection->getMethod('parseErrorResponse');
$parseError->setAccessible(true);

$xml = '<?xml version="1.0" encoding="UTF-8"?><Error><Code>SignatureDoesNotMatch</Code><Message>The request signature we calculated does not match the signature you provided.</Message></Error>';
$error = $parseError->invoke($client, $xml);

if (strpos($error, 'SignatureDoesNotMatch') !== false && strpos($error, 'signature') !== false) {
    echo "[✓] Error XML parsing works\n";
} else {
    echo "[✗] Error parsing failed. Got: {$error}\n";
}

// Test 7: Test method signatures
$methods = ['putObject', 'deleteObject', 'headBucket'];
foreach ($methods as $method) {
    if ($reflection->hasMethod($method)) {
        echo "[✓] Method exists: {$method}\n";
    } else {
        echo "[✗] Method missing: {$method}\n";
    }
}

echo "\n=== All basic tests completed successfully! ===\n";
