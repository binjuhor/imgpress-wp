#!/usr/bin/env php
<?php

declare(strict_types=1);

defined('ABSPATH') || define('ABSPATH', __DIR__ . '/../');

require_once __DIR__ . '/../includes/class-page-cache.php';

$pageCache = (new ReflectionClass(ImgPress\Page_Cache::class))->newInstanceWithoutConstructor();
$startedAt = new ReflectionProperty($pageCache, 'startedAt');

set_error_handler(static function (int $severity, string $message): never {
    throw new ErrorException($message, 0, $severity);
});

try {
    $startedAt->setValue($pageCache, microtime(true) * 1000);
} finally {
    restore_error_handler();
}

if (!is_float($startedAt->getValue($pageCache))) {
    fwrite(STDERR, "Page cache start time did not preserve millisecond precision.\n");
    exit(1);
}

echo "Page cache timing precision test passed.\n";
