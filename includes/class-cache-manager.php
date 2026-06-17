<?php

namespace ImgPress;

defined('ABSPATH') || exit;

class Cache_Manager
{
    private string $cache_dir = '';
    private string $cache_url = '';
    private array $options = [];

    public function __construct()
    {
        $this->load_options();
        $this->setup_directories();
    }

    private function load_options(): void
    {
        $defaults = [
            'cache_enabled'      => false,
            'cache_ttl'          => 0,
            'cache_gzip'         => true,
            'cache_exclude'      => [],
            'cache_mobile'       => false,
            'cache_logged_in'    => false,
            'cache_clear_on_new' => true,
            'cache_clear_on_update' => true,
        ];

        $saved = (array) get_option('imgpress_wp_options', []);
        $this->options = array_merge($defaults, $saved);
    }

    public function setup_directories(): void
    {
        $wp_content = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : WP_CONTENT_DIR;
        $this->cache_dir = $wp_content . '/cache/imgpress/';

        if (!is_dir($this->cache_dir)) {
            wp_mkdir_p($this->cache_dir);
        }

        $this->cache_url = WP_CONTENT_URL . '/cache/imgpress/';

        $this->create_index_files();
    }

    private function create_index_files(): void
    {
        $index = $this->cache_dir . 'index.html';
        if (!file_exists($index)) {
            file_put_contents($index, '');
        }
    }

    public function get_cache_dir(): string
    {
        return $this->cache_dir;
    }

    public function get_cache_url(): string
    {
        return $this->cache_url;
    }

    public function is_enabled(): bool
    {
        return (bool) $this->options['cache_enabled'];
    }

    public function get_option(string $key, $default = null)
    {
        return $this->options[$key] ?? $default;
    }

    public function set_option(string $key, $value): void
    {
        $this->options[$key] = $value;
        $this->save_options();
    }

    private function save_options(): void
    {
        update_option('imgpress_wp_options', $this->options);
    }

    public function get_cache_key(string $url = ''): string
    {
        if (!$url) {
            $url = $_SERVER['REQUEST_URI'] ?? '/';
        }

        $url = preg_replace('/\?.*$/', '', $url);
        $url = rtrim($url, '/') ?: '/';

        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $host = preg_replace('/:\d+$/', '', $host);

        return $host . $url;
    }

    public function get_cache_file_path(string $cache_key = ''): string
    {
        if (!$cache_key) {
            $cache_key = $this->get_cache_key();
        }

        $type = 'all';

        if ($this->is_mobile() && $this->options['cache_mobile']) {
            $type = 'mobile';
        }

        $file_path = $this->cache_dir . $type . '/' . $cache_key . '/index.html';

        return $file_path;
    }

    public function write_cache(string $content, string $cache_key = ''): bool
    {
        if (!$this->is_enabled()) {
            return false;
        }

        if (!$cache_key) {
            $cache_key = $this->get_cache_key();
        }

        $file_path = $this->get_cache_file_path($cache_key);
        $dir = dirname($file_path);

        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        $result = file_put_contents($file_path, $content);

        if ($result && $this->options['cache_gzip']) {
            $this->write_gzip_cache($content, $file_path . '.gz');
        }

        return $result !== false;
    }

    private function write_gzip_cache(string $content, string $file_path): bool
    {
        if (!function_exists('gzencode')) {
            return false;
        }

        $gzipped = gzencode($content, 9);
        return file_put_contents($file_path, $gzipped) !== false;
    }

    public function read_cache(string $cache_key = ''): ?string
    {
        if (!$this->is_enabled()) {
            return null;
        }

        if (!$cache_key) {
            $cache_key = $this->get_cache_key();
        }

        $file_path = $this->get_cache_file_path($cache_key);

        if (!file_exists($file_path)) {
            return null;
        }

        $mtime = filemtime($file_path);
        $ttl = (int) $this->options['cache_ttl'];

        if ($ttl > 0 && (time() - $mtime > $ttl)) {
            unlink($file_path);
            if (file_exists($file_path . '.gz')) {
                unlink($file_path . '.gz');
            }
            return null;
        }

        return file_get_contents($file_path);
    }

    public function purge_all(): bool
    {
        return $this->delete_directory($this->cache_dir . 'all/') &&
               $this->delete_directory($this->cache_dir . 'mobile/');
    }

    public function purge_cache_key(string $cache_key): bool
    {
        $file_path = $this->get_cache_file_path($cache_key);
        $dir = dirname($file_path);

        if (is_file($file_path)) {
            unlink($file_path);
        }

        if (file_exists($file_path . '.gz')) {
            unlink($file_path . '.gz');
        }

        if (is_dir($dir) && count(scandir($dir)) === 2) {
            rmdir($dir);
        }

        return true;
    }

    public function purge_by_pattern(string $pattern): int
    {
        $count = 0;
        $iterator = new \RecursiveDirectoryIterator($this->cache_dir);
        $recursive = new \RecursiveIteratorIterator($iterator);

        foreach ($recursive as $file) {
            if ($file->isFile() && preg_match($pattern, $file->getPathname())) {
                unlink($file->getPathname());
                $count++;
            }
        }

        return $count;
    }

    private function delete_directory(string $dir): bool
    {
        if (!is_dir($dir)) {
            return true;
        }

        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->delete_directory($path);
            } else {
                unlink($path);
            }
        }

        return rmdir($dir);
    }

    private function is_mobile(): bool
    {
        if (!isset($_SERVER['HTTP_USER_AGENT'])) {
            return false;
        }

        $mobile_agents = [
            'Mobile',
            'Android',
            'iPhone',
            'iPad',
            'iPod',
            'BlackBerry',
            'Windows Phone',
            'Opera Mini',
            'IEMobile',
        ];

        foreach ($mobile_agents as $agent) {
            if (stripos($_SERVER['HTTP_USER_AGENT'], $agent) !== false) {
                return true;
            }
        }

        return false;
    }

    public function get_cache_stats(): array
    {
        $total_size = 0;
        $file_count = 0;

        if (is_dir($this->cache_dir)) {
            $iterator = new \RecursiveDirectoryIterator($this->cache_dir);
            $recursive = new \RecursiveIteratorIterator($iterator);

            foreach ($recursive as $file) {
                if ($file->isFile()) {
                    $total_size += $file->getSize();
                    $file_count++;
                }
            }
        }

        return [
            'size'       => $total_size,
            'file_count' => $file_count,
            'size_human' => size_format($total_size),
        ];
    }

    public function should_cache_request(): bool
    {
        if (!$this->is_enabled()) {
            return false;
        }

        if (is_admin()) {
            return false;
        }

        if ($this->is_excluded_request()) {
            return false;
        }

        if (!$this->is_cacheable_method()) {
            return false;
        }

        if (is_user_logged_in() && !$this->options['cache_logged_in']) {
            return false;
        }

        return true;
    }

    private function is_excluded_request(): bool
    {
        $request_uri = $_SERVER['REQUEST_URI'] ?? '/';
        $exclude_rules = (array) $this->options['cache_exclude'];

        foreach ($exclude_rules as $rule) {
            if (preg_match('~' . $rule . '~', $request_uri)) {
                return true;
            }
        }

        $excluded_cookies = ['wordpress_logged_in', 'woocommerce_'];
        foreach ($excluded_cookies as $cookie_pattern) {
            foreach ($_COOKIE as $cookie_name => $value) {
                if (strpos($cookie_name, $cookie_pattern) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    private function is_cacheable_method(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET';
    }

    public function get_cache_directory_size(): int
    {
        $total_size = 0;

        if (is_dir($this->cache_dir)) {
            $iterator = new \RecursiveDirectoryIterator($this->cache_dir);
            $recursive = new \RecursiveIteratorIterator($iterator);

            foreach ($recursive as $file) {
                if ($file->isFile()) {
                    $total_size += $file->getSize();
                }
            }
        }

        return $total_size;
    }

    public function cleanup_by_size_limit(): int
    {
        $size_limit = (int) $this->get_option('cache_size_limit', 524288000);
        $current_size = $this->get_cache_directory_size();

        if ($current_size <= $size_limit) {
            return 0;
        }

        $files_to_delete = [];
        $iterator = new \RecursiveDirectoryIterator($this->cache_dir);
        $recursive = new \RecursiveIteratorIterator($iterator);

        foreach ($recursive as $file) {
            if ($file->isFile() && $file->getExtension() === 'html') {
                $files_to_delete[] = [
                    'path'  => $file->getPathname(),
                    'size'  => $file->getSize(),
                    'mtime' => $file->getMTime(),
                ];
            }
        }

        usort($files_to_delete, function ($a, $b) {
            return $a['mtime'] <=> $b['mtime'];
        });

        $deleted = 0;
        $target_size = $size_limit * 0.8;

        foreach ($files_to_delete as $file) {
            if ($current_size <= $target_size) {
                break;
            }

            unlink($file['path']);
            if (file_exists($file['path'] . '.gz')) {
                unlink($file['path'] . '.gz');
            }

            $current_size -= $file['size'];
            $deleted++;
        }

        return $deleted;
    }

    public function cleanup_expired_cache(): int
    {
        $ttl = (int) $this->get_option('cache_ttl', 3600);
        $deleted = 0;

        if (!is_dir($this->cache_dir)) {
            return 0;
        }

        $iterator = new \RecursiveDirectoryIterator($this->cache_dir);
        $recursive = new \RecursiveIteratorIterator($iterator);

        foreach ($recursive as $file) {
            if ($file->isFile() && $file->getExtension() === 'html') {
                $mtime = $file->getMTime();
                if ((time() - $mtime) > $ttl) {
                    unlink($file->getPathname());
                    if (file_exists($file->getPathname() . '.gz')) {
                        unlink($file->getPathname() . '.gz');
                    }
                    $deleted++;
                }
            }
        }

        return $deleted;
    }
}
