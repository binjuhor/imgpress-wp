<?php

namespace ImgPress;

defined('ABSPATH') || exit;

class Cache_Dropin
{
    private const MARKER = 'IMGPRESS_ADVANCED_CACHE';

    public static function install(): bool
    {
        $path = self::path();
        if (file_exists($path) && !self::isOwned()) {
            return false;
        }

        $content = <<<'PHP'
<?php
/**
 * ImgPress advanced cache drop-in.
 */
defined('ABSPATH') || define('ABSPATH', dirname(__DIR__) . '/');
defined('IMGPRESS_ADVANCED_CACHE') || define('IMGPRESS_ADVANCED_CACHE', true);

$cacheFile = WP_CONTENT_DIR . '/cache/imgpress/advanced-cache.php';
if (is_readable($cacheFile)) {
    require $cacheFile;
}
PHP;

        return (bool) file_put_contents($path, $content);
    }

    public static function remove(): bool
    {
        $path = self::path();
        if (!file_exists($path)) {
            return true;
        }

        if (!self::isOwned()) {
            return false;
        }

        return unlink($path);
    }

    public static function isInstalled(): bool
    {
        return file_exists(self::path()) && self::isOwned();
    }

    public static function path(): string
    {
        return WP_CONTENT_DIR . '/advanced-cache.php';
    }

    private static function isOwned(): bool
    {
        $path = self::path();
        if (!file_exists($path) || !is_readable($path)) {
            return false;
        }

        $content = (string) file_get_contents($path, false, null, 0, 4096);
        return str_contains($content, self::MARKER);
    }
}
