<?php

namespace ImgPress;

defined('ABSPATH') || exit;

/**
 * Manages the advanced-cache.php drop-in pair:
 *
 *   wp-content/advanced-cache.php                 (thin owned loader)
 *   wp-content/cache/imgpress/advanced-cache.php  (generated standalone runtime)
 *
 * Enabling also sets WP_CACHE in wp-config.php so WordPress actually includes
 * the loader before plugins load. The runtime file is fully self-contained
 * (no WP functions) and mirrors Page_Cache's storage/naming so both layers can
 * serve the same files.
 */
class Cache_Dropin
{
    private const MARKER = 'IMGPRESS_ADVANCED_CACHE';
    private const WPCACHE_MARKER = 'WP_CACHE define managed by ImgPress';

    private static ?string $lastError = null;

    public static function install(): bool
    {
        $loader = self::loaderPath();

        if (file_exists($loader) && !self::isOwned()) {
            self::$lastError = 'advanced-cache.php is owned by another plugin and was not overwritten.';
            return false;
        }

        $body = self::renderRuntime();
        $bodyPath = WP_CONTENT_DIR . '/cache/imgpress/advanced-cache.php';
        $bodyFile = self::writeAtomic($bodyPath, $body);
        if (!$bodyFile) {
            self::$lastError = 'Could not write ' . $bodyPath;
            return false;
        }

        $loaderContent = self::renderLoader();
        $loaderFile = self::writeAtomic($loader, $loaderContent);
        if (!$loaderFile) {
            self::$lastError = 'Could not write ' . $loader;
            return false;
        }

        // Best effort: define WP_CACHE so WP loads the drop-in before plugins.
        self::setWpCache(true);

        self::$lastError = null;
        return true;
    }

    public static function remove(): bool
    {
        $ok = true;
        $loader = self::loaderPath();

        if (file_exists($loader)) {
            if (!self::isOwned()) {
                return false;
            }
            $ok = unlink($loader) && $ok;
        }

        $bodyPath = WP_CONTENT_DIR . '/cache/imgpress/advanced-cache.php';
        if (is_file($bodyPath)) {
            $ok = unlink($bodyPath) && $ok;
        }

        self::setWpCache(false);

        return $ok;
    }

    public static function isInstalled(): bool
    {
        return file_exists(self::loaderPath()) && self::isOwned();
    }

    public static function isWpCacheDefined(): bool
    {
        if (defined('WP_CACHE') && WP_CACHE) {
            return true;
        }

        $path = self::wpConfigPath();
        if ($path === '' || !is_readable($path)) {
            return false;
        }

        return str_contains((string) file_get_contents($path), self::WPCACHE_MARKER);
    }

    public static function lastError(): string
    {
        return (string) self::$lastError;
    }

    public static function loaderPath(): string
    {
        return WP_CONTENT_DIR . '/advanced-cache.php';
    }

    private static function renderLoader(): string
    {
        $content = <<<'PHP'
<?php
/**
 * ImgPress advanced cache drop-in (owned loader).
 */
defined('ABSPATH') || define('ABSPATH', dirname(__DIR__) . '/');
defined('IMGPRESS_ADVANCED_CACHE') || define('IMGPRESS_ADVANCED_CACHE', true);

$imgpressContentDir = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : (defined('ABSPATH') ? ABSPATH . 'wp-content' : dirname(__DIR__));
$imgpressCacheFile = $imgpressContentDir . '/cache/imgpress/advanced-cache.php';
if (is_readable($imgpressCacheFile)) {
    require $imgpressCacheFile;
}
PHP;

        return $content;
    }

    private static function renderRuntime(): string
    {
        $template = <<<'PHP'
<?php
/* ImgPress advanced cache runtime - generated file, do not edit. */
if (!defined('IMGPRESS_ADVANCED_CACHE_RUNTIME')) {
    define('IMGPRESS_ADVANCED_CACHE_RUNTIME', true);
}

__IMGPRESS_CONFIG__

if (true !== $config['enabled']) {
    return;
}

if (!isset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']) || !isset($_SERVER['HTTP_HOST'])) {
    return;
}

$method = strtoupper($_SERVER['REQUEST_METHOD']);
if ($method !== 'GET' && $method !== 'HEAD') {
    return;
}

$uri = $_SERVER['REQUEST_URI'];
$urlParts = parse_url($uri);
$path = isset($urlParts['path']) ? (string) $urlParts['path'] : '/';

// Never serve administrative or script endpoints from cache.
if (preg_match('#^/(wp-admin|wp-login\.php|wp-json|wp-cron\.php|wp-comments-post\.php|xmlrpc\.php)(/|$)#i', $path) || str_ends_with($path, '.php')) {
    return;
}

// Preload cache-bust requests always hit WordPress to regenerate the page.
parse_str(isset($urlParts['query']) ? (string) $urlParts['query'] : '', $queryParams);
if (isset($queryParams['imgpress_cache_bust'])) {
    return;
}

// Never serve logged-in content unless explicitly enabled.
$cookieNames = array_keys($_COOKIE);
if (empty($config['logged_in'])) {
    foreach ($cookieNames as $cookieName) {
        if (str_starts_with((string) $cookieName, 'wordpress_logged_in')) {
            return;
        }
    }
}

// Respect excluded cookie names (cart, password, comment author, etc).
foreach ($config['excluded_cookies'] as $needle) {
    if ($needle === '') {
        continue;
    }
    foreach ($cookieNames as $cookieName) {
        if (str_contains((string) $cookieName, (string) $needle)) {
            return;
        }
    }
}

// Canonical query (ignored tracking args stripped) so variants match PHP.
$query = array();
if (isset($urlParts['query'])) {
    parse_str((string) $urlParts['query'], $query);
    foreach ($config['ignored_query_args'] as $arg) {
        unset($query[$arg]);
    }
    unset($query['imgpress_cache_bust']);
    ksort($query);
}
$canonicalQuery = http_build_query($query);

// Host-scoped, path-mirrored layout (must match Page_Cache).
$host = strtolower((string) $_SERVER['HTTP_HOST']);
$host = trim(preg_replace('/[^a-z0-9._-]/', '-', $host), '.-');
if ($host === '') {
    return;
}

$root = dirname(__FILE__) . '/page-cache/' . $host;
$dir = $root;
$segments = explode('/', $path);
foreach ($segments as $segment) {
    $segment = rawurldecode($segment);
    if ($segment === '' || $segment === '.' || $segment === '..') {
        continue;
    }
    $segment = preg_replace('/[^A-Za-z0-9._~-]+/', '-', $segment);
    $segment = trim((string) $segment, '-');
    if ($segment === '') {
        continue;
    }
    if (strlen($segment) > 120) {
        $segment = substr($segment, 0, 100) . '-' . substr(md5($segment), 0, 8);
    }
    $dir .= '/' . $segment;
}

$mobile = false;
if (!empty($config['mobile_separate'])) {
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
    $mobile = (bool) preg_match('/Mobile|Android|iPhone|iPod|Opera Mini|IEMobile|BlackBerry|WPDesktop/i', $ua);
}

$base = $dir . '/index';
$queryName = $canonicalQuery === '' ? '' : '-' . md5($canonicalQuery);

if ($mobile && !empty($config['mobile_separate'])) {
    $candidates = array($base . $queryName . '-mobile.html', $base . $queryName . '.html');
} else {
    $candidates = array($base . $queryName . '.html');
}

$lifespan = (int) $config['lifespan'];
foreach ($candidates as $candidate) {
    if (!is_file($candidate) || !is_readable($candidate)) {
        continue;
    }
    if ($lifespan > 0 && (time() - (int) filemtime($candidate)) > $lifespan) {
        continue;
    }

    $encoding = isset($_SERVER['HTTP_ACCEPT_ENCODING']) ? (string) $_SERVER['HTTP_ACCEPT_ENCODING'] : '';
    $serveGzip = strpos($encoding, 'gzip') !== false && is_file($candidate . '.gz');

    if (!headers_sent()) {
        header('Content-Type: text/html; charset=UTF-8');
        header('X-ImgPress-Cache: HIT');
        header('X-ImgPress-Engine: imgpress-dropin');
        header('Cache-Control: public, max-age=0, must-revalidate');
        if ($serveGzip) {
            header('Content-Encoding: gzip');
        }
        header('Vary: Accept-Encoding');
        $modified = (int) filemtime($candidate);
        $etag = '"' . md5((string) $modified . filesize($candidate)) . '"';
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $modified) . ' GMT');
        header('ETag: ' . $etag);

        $noneMatch = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? trim((string) $_SERVER['HTTP_IF_NONE_MATCH']) : '';
        $ifModifiedSince = isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ? (string) $_SERVER['HTTP_IF_MODIFIED_SINCE'] : '';
        if ($noneMatch !== '' && strpos($noneMatch, $etag) !== false) {
            http_response_code(304);
            exit;
        }
        if ($ifModifiedSince !== '' && strtotime($ifModifiedSince) >= $modified) {
            http_response_code(304);
            exit;
        }
    }

    readfile($serveGzip ? $candidate . '.gz' : $candidate);
    exit;
}

PHP;

        $config = self::runtimeConfig();
        $configPhp = '$config = ' . var_export($config, true) . ';';

        return str_replace('__IMGPRESS_CONFIG__', $configPhp, $template);
    }

    private static function runtimeConfig(): array
    {
        $defaults = Config::defaults();
        $opts = array_merge($defaults, (array) get_option(Config::OPTION_KEY, []));

        return [
            'enabled' => !empty($opts['cache_enabled']) && !empty($opts['cache_advanced_dropin']),
            'lifespan' => max(0, (int) ($opts['cache_lifespan'] ?? DAY_IN_SECONDS)),
            'logged_in' => !empty($opts['cache_logged_in']),
            'mobile_separate' => !empty($opts['cache_mobile_separate']),
            'excluded_cookies' => self::lines((string) ($opts['cache_excluded_cookies'] ?? '')),
            'ignored_query_args' => self::lines((string) ($opts['cache_ignored_query_args'] ?? '')),
        ];
    }

    private static function lines(string $value): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $value) ?: [];
        return array_values(array_filter(array_map('trim', $lines), static fn($line) => $line !== ''));
    }

    private static function wpConfigPath(): string
    {
        $candidates = [
            ABSPATH . 'wp-config.php',
            dirname(rtrim(ABSPATH, '/')) . '/wp-config.php',
            rtrim(ABSPATH, '/') . '/../wp-config.php',
        ];

        foreach (array_unique($candidates) as $path) {
            if (is_file($path)) {
                return (string) $path;
            }
        }

        return '';
    }

    private static function setWpCache(bool $enable): bool
    {
        $path = self::wpConfigPath();
        if ($path === '') {
            return false;
        }

        $contents = (string) file_get_contents($path);
        if ($contents === '') {
            return false;
        }

        $markerLine = "/* " . self::WPCACHE_MARKER . " */ if ( ! defined( 'WP_CACHE' ) ) { define( 'WP_CACHE', true ); }";

        if ($enable) {
            // Respect an existing, non-managed WP_CACHE definition.
            if (preg_match("/define\s*\(\s*['\"]WP_CACHE['\"]\s*,/", $contents)) {
                return defined('WP_CACHE') && WP_CACHE;
            }

            if (!str_contains($contents, self::WPCACHE_MARKER)) {
                $inserted = preg_replace('/<\?php/', "<?php\n" . $markerLine, $contents, 1);
                if ($inserted !== null && $inserted !== $contents) {
                    $contents = $inserted;
                } else {
                    $contents .= "\n" . $markerLine;
                }
            }

            return self::writeWpConfig($path, $contents);
        }

        if (!str_contains($contents, self::WPCACHE_MARKER)) {
            return true;
        }

        $clean = preg_replace('/\s*\/\* ' . preg_quote(self::WPCACHE_MARKER, '/') . ' \*\/[^\n]*\n?/', '', $contents, 1);
        if ($clean === null) {
            return false;
        }

        return self::writeWpConfig($path, $clean);
    }

    private static function writeWpConfig(string $path, string $contents): bool
    {
        if (!is_writable($path)) {
            self::$lastError = 'wp-config.php is not writable. Define WP_CACHE manually.';
            return false;
        }

        return file_put_contents($path, $contents, LOCK_EX) !== false;
    }

    private static function writeAtomic(string $file, string $contents): bool
    {
        wp_mkdir_p(dirname($file));
        $tmp = $file . '.tmp.' . wp_generate_password(8, false, false);
        if (file_put_contents($tmp, $contents, LOCK_EX) === false) {
            return false;
        }

        $ok = rename($tmp, $file);
        if (!$ok) {
            @unlink($tmp);
        }

        return $ok;
    }

    private static function isOwned(): bool
    {
        $path = self::loaderPath();
        if (!file_exists($path) || !is_readable($path)) {
            return false;
        }

        $content = (string) file_get_contents($path, false, null, 0, 4096);
        return str_contains($content, self::MARKER);
    }
}
