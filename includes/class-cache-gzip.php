<?php

namespace ImgPress;

defined('ABSPATH') || exit;

class Cache_Gzip
{
    private Cache_Manager $cache_manager;

    public function __construct(Cache_Manager $cache_manager)
    {
        $this->cache_manager = $cache_manager;

        if (!$this->cache_manager->get_option('cache_gzip')) {
            return;
        }

        add_action('shutdown', [$this, 'serve_gzipped_cache'], -9999);
    }

    public function serve_gzipped_cache(): void
    {
        if (headers_sent()) {
            return;
        }

        if (!$this->cache_manager->is_enabled()) {
            return;
        }

        if (!$this->should_serve_gzip()) {
            return;
        }

        $cache_key = $this->cache_manager->get_cache_key();
        $file_path = $this->cache_manager->get_cache_file_path($cache_key);
        $gz_file = $file_path . '.gz';

        if (!file_exists($gz_file)) {
            return;
        }

        $content = file_get_contents($gz_file);
        if (!$content) {
            return;
        }

        header('Content-Type: text/html; charset=UTF-8');
        header('Content-Encoding: gzip');
        header('Content-Length: ' . strlen($content));
        header('Vary: Accept-Encoding');
        header('X-Cache: HIT (gzipped)');

        echo $content;
        exit;
    }

    private function should_serve_gzip(): bool
    {
        if (!isset($_SERVER['HTTP_ACCEPT_ENCODING'])) {
            return false;
        }

        if (strpos($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') === false) {
            return false;
        }

        if (!$this->cache_manager->should_cache_request()) {
            return false;
        }

        return true;
    }

    public static function has_gzip_support(): bool
    {
        return function_exists('gzencode') && function_exists('gzdecode');
    }

    public static function get_compression_ratio(string $file_path): float
    {
        if (!file_exists($file_path) || !file_exists($file_path . '.gz')) {
            return 0;
        }

        $original_size = filesize($file_path);
        $compressed_size = filesize($file_path . '.gz');

        if ($original_size === 0) {
            return 0;
        }

        return (($original_size - $compressed_size) / $original_size) * 100;
    }
}
