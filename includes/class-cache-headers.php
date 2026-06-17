<?php

namespace ImgPress;

defined('ABSPATH') || exit;

class Cache_Headers
{
    private Cache_Manager $cache_manager;

    public function __construct(Cache_Manager $cache_manager)
    {
        $this->cache_manager = $cache_manager;

        add_action('send_headers', [$this, 'send_cache_headers']);
        add_action('send_headers', [$this, 'send_gzip_headers']);
        add_action('send_headers', [$this, 'send_etag_header']);
        add_action('send_headers', [$this, 'send_last_modified_header']);
    }

    public function send_cache_headers(): void
    {
        if (is_admin() || is_user_logged_in()) {
            return;
        }

        if (!$this->cache_manager->is_enabled()) {
            return;
        }

        $ttl = (int) $this->cache_manager->get_option('cache_ttl');

        if ($ttl > 0) {
            $expires = gmdate('D, d M Y H:i:s', time() + $ttl) . ' GMT';
            header('Expires: ' . $expires);
            header('Cache-Control: public, max-age=' . $ttl);
        } else {
            header('Cache-Control: public, must-revalidate, max-age=3600');
        }

        header('Vary: Accept-Encoding');

        if (defined('WP_ENV') && WP_ENV === 'production') {
            header('X-Cache-Status: managed-by-imgpress');
        }
    }

    public function send_gzip_headers(): void
    {
        if (!$this->cache_manager->get_option('cache_gzip')) {
            return;
        }

        if (!function_exists('gzencode')) {
            return;
        }

        $accept_encoding = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';

        if (strpos($accept_encoding, 'gzip') !== false) {
            header('Content-Encoding: gzip');
        }
    }

    public function send_etag_header(): void
    {
        if (!$this->cache_manager->get_option('cache_etag_enabled', true)) {
            return;
        }

        if (is_admin() || is_user_logged_in()) {
            return;
        }

        if (!$this->cache_manager->is_enabled()) {
            return;
        }

        $cache_key = $this->cache_manager->get_cache_key();
        $file_path = $this->cache_manager->get_cache_file_path($cache_key);

        if (!file_exists($file_path)) {
            return;
        }

        $etag = $this->generate_etag($file_path);
        header('ETag: "' . $etag . '"');

        $if_none_match = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        if ($if_none_match === '"' . $etag . '"') {
            http_response_code(304);
            exit;
        }
    }

    private function generate_etag(string $file_path): string
    {
        if (!file_exists($file_path)) {
            return '';
        }

        $mtime = filemtime($file_path);
        $size = filesize($file_path);

        return md5($mtime . '-' . $size);
    }

    public function send_last_modified_header(): void
    {
        if (!$this->cache_manager->get_option('cache_last_modified', true)) {
            return;
        }

        if (is_admin() || is_user_logged_in()) {
            return;
        }

        if (!$this->cache_manager->is_enabled()) {
            return;
        }

        $cache_key = $this->cache_manager->get_cache_key();
        $file_path = $this->cache_manager->get_cache_file_path($cache_key);

        if (!file_exists($file_path)) {
            return;
        }

        $mtime = filemtime($file_path);
        $last_modified = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';
        header('Last-Modified: ' . $last_modified);

        $if_modified_since = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
        if ($if_modified_since === $last_modified) {
            http_response_code(304);
            exit;
        }
    }

    public static function setup_htaccess(): bool
    {
        $htaccess_path = ABSPATH . '.htaccess';

        if (!is_writable($htaccess_path) && !is_writable(ABSPATH)) {
            return false;
        }

        $contents = file_exists($htaccess_path) ? file_get_contents($htaccess_path) : '';

        $marker_begin = '# BEGIN ImgPress Cache';
        $marker_end = '# END ImgPress Cache';

        if (strpos($contents, $marker_begin) === false) {
            $rules = self::get_htaccess_rules();
            $new_content = $marker_begin . "\n" . $rules . "\n" . $marker_end . "\n\n" . $contents;

            return file_put_contents($htaccess_path, $new_content) !== false;
        }

        return true;
    }

    public static function remove_htaccess(): bool
    {
        $htaccess_path = ABSPATH . '.htaccess';

        if (!file_exists($htaccess_path) || !is_writable($htaccess_path)) {
            return false;
        }

        $contents = file_get_contents($htaccess_path);
        $marker_begin = '# BEGIN ImgPress Cache';
        $marker_end = '# END ImgPress Cache';

        $pattern = '/' . preg_quote($marker_begin) . '.*' . preg_quote($marker_end) . "\n/s";
        $new_content = preg_replace($pattern, '', $contents);

        return file_put_contents($htaccess_path, $new_content) !== false;
    }

    private static function get_htaccess_rules(): string
    {
        $cache_dir = str_replace(ABSPATH, '', WP_CONTENT_DIR) . '/cache/imgpress';

        return <<<HTACCESS
<FilesMatch "\.html?$">
    <IfModule mod_headers.c>
        Header set Cache-Control "public, max-age=3600"
        Header set Vary "Accept-Encoding"
    </IfModule>
</FilesMatch>

<IfModule mod_gzip.c>
    mod_gzip_on Yes
    mod_gzip_dechunk Yes
    mod_gzip_item_include file \.(html|txt|php|xml)$
    mod_gzip_item_include handler ^cgi-script$
    mod_gzip_item_include mime ^text/.*
    mod_gzip_item_include mime ^application/x-javascript.*
    mod_gzip_item_exclude mime ^image/.*
    mod_gzip_level 6
    mod_gzip_buffers 1024 8
    mod_gzip_min_http 1000
    mod_gzip_vary On
</IfModule>

<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/html text/plain text/xml text/css text/javascript application/javascript application/x-javascript
</IfModule>

<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase /

    # Serve gzipped cache files if available and supported
    <IfModule mod_headers.c>
        <IfModule mod_mime.c>
            AddType text/html .html
            AddEncoding gzip .gz
        </IfModule>
    </IfModule>

    RewriteCond %{HTTP_ACCEPT_ENCODING} gzip
    RewriteCond %{REQUEST_FILENAME}\.gz -s
    RewriteRule ^(.+)$ \$1.gz [QSA]

    RewriteRule \.html\.gz\$ - [T=text/html,E=no-gzip:1]
</IfModule>
HTACCESS;
    }
}
