#!/usr/bin/env php
<?php

define('ABSPATH', __DIR__ . '/../');
define('IMGPRESS_WP_VERSION', 'test');
define('DAY_IN_SECONDS', 86400);

$GLOBALS['r2LastRequest'] = [];

function wp_remote_request($url, $args = []) {
    $GLOBALS['r2LastRequest'] = ['url' => $url, 'args' => $args];
    return [];
}

function wp_remote_retrieve_response_code($response) { return 200; }
function wp_remote_retrieve_body($response) { return ''; }
function wp_remote_retrieve_headers($response) { return []; }
function is_wp_error($thing) { return false; }

require_once __DIR__ . '/../includes/class-config.php';
require_once __DIR__ . '/../includes/class-settings.php';
require_once __DIR__ . '/../includes/class-r2-client.php';

$settingsReflection = new ReflectionClass(ImgPress\Settings::class);
$settings = $settingsReflection->newInstanceWithoutConstructor();
$options = $settingsReflection->getProperty('options');
$options->setAccessible(true);
$options->setValue($settings, [
    'r2_access_key' => 'key',
    'r2_secret_key' => 'secret',
    'r2_account_id' => 'account',
    'r2_bucket' => 'bucket',
    'r2_cache_control' => 'public, max-age=31536000, immutable',
    'request_timeout' => 120,
]);

$client = new ImgPress\R2_Client($settings);
$client->putObject('uploads/photo.webp', 'image', 'image/webp', [
    'ContentDisposition' => 'inline',
    'ContentEncoding' => 'gzip',
]);
$headers = $GLOBALS['r2LastRequest']['args']['headers'];
assert(($headers['cache-control'] ?? '') === 'public, max-age=31536000, immutable');
assert(($headers['content-type'] ?? '') === 'image/webp');
assert(($headers['content-disposition'] ?? '') === 'inline');
assert(($headers['content-encoding'] ?? '') === 'gzip');

$client->putObject('temporary/export.json', '{}', 'application/json');
assert(!isset($GLOBALS['r2LastRequest']['args']['headers']['cache-control']));

$reflection = new ReflectionClass($client);
$objectMetadata = $reflection->getMethod('objectMetadata');
$objectMetadata->setAccessible(true);
$metadata = $objectMetadata->invoke($client, [
    'Content-Type' => 'image/svg+xml',
    'Content-Disposition' => 'inline',
    'Content-Encoding' => 'gzip',
    'X-Amz-Meta-Source' => 'wordpress',
]);
$metadata['CacheControl'] = 'public, max-age=60';
$client->copyObject('uploads/icon.svg', $metadata);
$headers = $GLOBALS['r2LastRequest']['args']['headers'];
assert(($headers['x-amz-metadata-directive'] ?? '') === 'REPLACE');
assert(($headers['content-type'] ?? '') === 'image/svg+xml');
assert(($headers['content-disposition'] ?? '') === 'inline');
assert(($headers['content-encoding'] ?? '') === 'gzip');
assert(($headers['x-amz-meta-source'] ?? '') === 'wordpress');
assert(($headers['cache-control'] ?? '') === 'public, max-age=60');

echo "R2 Cache-Control tests passed.\n";
