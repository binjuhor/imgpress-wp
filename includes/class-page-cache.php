<?php

namespace ImgPress;

defined('ABSPATH') || exit;

/**
 * File page cache.
 *
 * Storage layout (mirrored per host so multisite hosts never collide):
 *   wp-content/cache/imgpress/page-cache/{host}/{path...}/index[.html|.html.gz]
 *   Query variants: index-{md5}.html | index-{md5}-mobile.html
 *   Mobile variants: index-mobile.html (when "separate mobile cache" is enabled)
 *
 * Freshness is derived from file mtime; no per-file meta is written. Cache
 * files are stored atomically (tmp + rename) and a gzip twin is kept so both
 * the advanced-cache drop-in (pre-WP early exit) and this PHP fallback can
 * serve gzipped responses with Last-Modified / 304 semantics.
 */
class Page_Cache
{
    public const CACHE_BUST_KEY = 'imgpress_cache_bust';
    public const LAYOUT_VERSION = 2;

    private const LAYOUT_OPTION = 'imgpress_cache_layout_v2';

    private ?string $pendingFile = null;
    private ?string $pendingGzipFile = null;
    private float $startedAt = 0.0;
    private bool $storeEnabled = false;

    public function __construct(private Settings $settings, private Logger $logger)
    {
    }

    public function init(): void
    {
        add_action('template_redirect', [$this, 'maybeServe'], 0);
        add_action('save_post', [$this, 'purgeRelated'], 10, 3);
        add_action('deleted_post', [$this, 'purgeRelated']);
        add_action('wp_trash_post', [$this, 'purgeRelated']);
        add_action('untrash_post', [$this, 'purgeRelated']);
        add_action('switch_theme', [$this, 'purgeAll']);
        add_action('activated_plugin', [$this, 'purgeAll']);
        add_action('deactivated_plugin', [$this, 'purgeAll']);

        // Comments: purge the commented post (not the whole cache).
        add_action('comment_post', [$this, 'onComment'], 10, 2);
        add_action('edit_comment', [$this, 'onComment'], 10, 2);

        // Menus and terms that render on the front end.
        add_action('wp_update_nav_menu', [$this, 'purgeAll']);
        add_action('wp_create_nav_menu', [$this, 'purgeAll']);
        add_action('wp_delete_nav_menu', [$this, 'purgeAll']);
        add_action('created_term', [$this, 'onTerm'], 10, 3);
        add_action('edited_term', [$this, 'onTerm'], 10, 3);
        add_action('delete_term', [$this, 'purgeHome'], 10, 1);

        // Widget changes affect every page that renders a sidebar.
        add_action('updated_option', [$this, 'onUpdatedOption'], 10, 3);

        // Settings that change rendered HTML purge the cache (compression/R2 rewrites, optimizers, bloat).
        add_action('update_option_' . Config::OPTION_KEY, [$this, 'onImgpressOptionsUpdated'], 10, 2);

        // WooCommerce stock changes affect product, shop and category pages.
        if (class_exists('WooCommerce')) {
            add_action('woocommerce_product_set_stock', [$this, 'onWcProduct']);
            add_action('woocommerce_variation_set_stock', [$this, 'onWcProduct']);
        }

        add_action('init', [$this, 'maybeMigrateLegacy']);
    }

    public function maybeServe(): void
    {
        if (!$this->isCacheableRequest()) {
            return;
        }

        $isPreload = $this->isPreloadRequest();

        if (!$isPreload && $this->serveIfHit()) {
            return;
        }

        header('X-ImgPress-Cache: MISS');
        $target = $this->requestTarget();
        $this->pendingFile = $target['file'];
        $this->pendingGzipFile = $target['file'] . '.gz';
        $this->storeEnabled = true;
        $this->startedAt = microtime(true) * 1000;
        ob_start([$this, 'storeBuffer']);
    }

    public function storeBuffer(string $html): string
    {
        if (!$this->storeEnabled || $this->pendingFile === null) {
            return $html;
        }

        $this->storeEnabled = false;

        if (http_response_code() !== 200 || stripos($html, '</html>') === false) {
            return $html;
        }

        $this->writeAtomic($this->pendingFile, $html);
        $this->writeAtomic($this->pendingGzipFile, (string) gzencode($html, 6));

        return $html;
    }

    /**
     * Serve a fresh cache file for the current request. Returns true when a
     * response was sent (and the request terminated).
     */
    public function serveIfHit(): bool
    {
        foreach ($this->requestCandidates() as $file) {
            if ($this->isFresh($file)) {
                $this->serveFile($file);
                return true;
            }
        }

        return false;
    }

    public function serveFile(string $file): void
    {
        $gzip = strpos((string) ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''), 'gzip') !== false
            && is_file($file . '.gz');

        if (!headers_sent()) {
            header('Content-Type: text/html; charset=UTF-8');
            header('X-ImgPress-Cache: HIT');
            header('X-ImgPress-Engine: imgpress-php');
            header('Cache-Control: public, max-age=0, must-revalidate');
            if ($gzip) {
                header('Content-Encoding: gzip');
            }
            header('Vary: Accept-Encoding');

            $modified = (int) filemtime($file);
            $etag = '"' . md5((string) $modified . filesize($file)) . '"';
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $modified) . ' GMT');
            header('ETag: ' . $etag);

            $noneMatch = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
            $modifiedSince = (string) ($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '');
            if ($noneMatch !== '' && str_contains($noneMatch, $etag)) {
                http_response_code(304);
                exit;
            }
            if ($modifiedSince !== '' && strtotime($modifiedSince) >= $modified) {
                http_response_code(304);
                exit;
            }
        }

        readfile($gzip ? $file . '.gz' : $file);
        exit;
    }

    public function purgeAll(): bool
    {
        $dir = $this->dirForHost($this->requestHost());
        $ok = $this->deleteDirectoryContents($dir);

        if ($ok) {
            $this->logger->info('Page cache purged.');
        }

        return $ok;
    }

    public function purgeUrl(string $url): bool
    {
        $dir = $this->dirForUrl($url);
        if ($dir === '' || !is_dir($dir)) {
            return true;
        }

        $ok = true;
        foreach (glob($dir . '/index*.html') ?: [] as $file) {
            $ok = unlink($file) && $ok;
            $gz = $file . '.gz';
            if (is_file($gz)) {
                $ok = unlink($gz) && $ok;
            }
        }

        return $ok;
    }

    public function purgeUrls(array $urls): bool
    {
        $ok = true;
        $seen = [];
        foreach ($urls as $url) {
            $url = (string) $url;
            $key = wp_parse_url($url, PHP_URL_HOST) . (wp_parse_url($url, PHP_URL_PATH) ?: '/');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $ok = $this->purgeUrl($url) && $ok;
        }

        return $ok;
    }

    public function purgeHome(): bool
    {
        return $this->purgeUrl(home_url('/'));
    }

    public function purgeRelated(int $postId = 0, ?\WP_Post $post = null, bool $update = false): void
    {
        $urls = $this->relatedUrls($postId, $post);
        if (empty($urls)) {
            return;
        }

        $this->purgeUrls($urls);
        $this->logger->info('Related cache purged.', ['post_id' => (string) $postId]);
    }

    /**
     * Warm a URL through a normal HTTP request so it is generated and cached.
     * The cache-bust argument + header force regeneration on cached pages.
     */
    public function warmUrl(string $url): void
    {
        $separator = str_contains($url, '?') ? '&' : '?';
        $warmUrl = $url . $separator . self::CACHE_BUST_KEY . '=' . rawurlencode((string) time());

        $response = wp_remote_get($warmUrl, [
            'timeout' => 10,
            'redirection' => 3,
            'blocking' => true,
            'headers' => ['X-ImgPress-Preload' => '1'],
            'sslverify' => (bool) apply_filters('https_local_ssl_verify', false),
        ]);

        if (is_wp_error($response)) {
            $this->logger->warning('Cache preload request failed.', [
                'url' => $url,
                'error' => $response->get_error_message(),
            ]);
        }
    }

    public function onComment(int $commentId, $approved = null): void
    {
        $comment = get_comment($commentId);
        if ($comment instanceof \WP_Comment && !empty($comment->comment_post_ID)) {
            $this->purgeRelated((int) $comment->comment_post_ID);
        }
    }

    public function onTerm(int $termId, int $taxonomyTermId = 0, string $taxonomy = ''): void
    {
        $urls = [home_url('/')];
        $term = get_term($termId, $taxonomy);
        if ($term instanceof \WP_Term && !is_wp_error($term)) {
            $link = get_term_link($term);
            if (!is_wp_error($link)) {
                $urls[] = (string) $link;
            }
        }

        $this->purgeUrls($urls);
    }

    public function onUpdatedOption(string $option, $oldValue, $value): void
    {
        if ($option === 'sidebars_widgets' || str_starts_with($option, 'widget_')) {
            $this->purgeAll();
        }
    }

    public function onWcProduct($product): void
    {
        $productId = is_object($product) && method_exists($product, 'get_id') ? (int) $product->get_id() : 0;
        if ($productId > 0) {
            $this->purgeRelated($productId);
        }
    }

    /**
     * Purge whenever a save changes a setting that affects rendered output so
     * cached HTML can never serve stale markup/asset references.
     */
    public function onImgpressOptionsUpdated($old, $new): void
    {
        if (empty($new['cache_enabled']) || !is_array($old) || !is_array($new)) {
            return;
        }

        $outputKeys = [
            'cache_lifespan', 'cache_mobile_separate', 'cache_excluded_urls',
            'cache_excluded_cookies', 'cache_ignored_query_args',
            'auto_compress', 'quality', 'format', 'max_width', 'enabled_types',
            'r2_rewrite_content', 'r2_custom_domain', 'r2_delete_local',
            'optimize_css_minify', 'optimize_css_rucss', 'optimize_css_rucss_method',
            'optimize_css_rucss_exclude_stylesheets', 'optimize_css_rucss_include_selectors',
            'optimize_js_minify', 'optimize_js_defer', 'optimize_js_defer_excludes',
            'optimize_js_delay', 'optimize_js_delay_method', 'optimize_js_delay_all_excludes',
            'optimize_js_delay_selected', 'optimize_js_delay_timeout',
            'optimize_fonts_swap', 'optimize_img_lazyload', 'optimize_img_lazyload_above_fold',
            'optimize_img_add_dimensions', 'optimize_iframe_lazyload', 'optimize_html_minify',
            'optimize_excluded_assets',
            'bloat_disable_jquery_migrate', 'bloat_disable_emojis', 'bloat_disable_block_css',
            'bloat_disable_oembeds', 'bloat_disable_dashicons', 'bloat_disable_xml_rpc',
            'bloat_disable_rss_feed', 'bloat_disable_query_strings',
            'bloat_disable_woo_cart_fragments', 'bloat_disable_heartbeat', 'bloat_disable_google_fonts',
        ];

        $changed = false;
        foreach ($outputKeys as $key) {
            if (($old[$key] ?? null) !== ($new[$key] ?? null)) {
                $changed = true;
                break;
            }
        }

        if ($changed) {
            $this->purgeAll();
        }
    }

    public function cacheCount(): int
    {
        $dir = $this->dirForHost($this->requestHost());
        if (!is_dir($dir)) {
            return 0;
        }

        $count = 0;
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        ) as $file) {
            $name = $file->getFilename();
            if ($file->isFile() && str_ends_with($name, '.html') && !str_ends_with($name, '.gz')) {
                $count++;
            }
        }

        return $count;
    }

    public function cacheSize(): int
    {
        $dir = $this->dirForHost($this->requestHost());
        if (!is_dir($dir)) {
            return 0;
        }

        $size = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }

        return $size;
    }

    public function cacheDir(): string
    {
        return WP_CONTENT_DIR . '/cache/imgpress/page-cache';
    }

    public function maybeMigrateLegacy(): void
    {
        if (get_option(self::LAYOUT_OPTION)) {
            return;
        }

        // Legacy layout wrote flat md5(.html/.json) files directly under the
        // root. Remove them; keep directories (host trees).
        $dir = $this->cacheDir();
        if (is_dir($dir)) {
            foreach (new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS) as $entry) {
                if ($entry->isFile()) {
                    @unlink($entry->getPathname());
                }
            }
        }

        update_option(self::LAYOUT_OPTION, self::LAYOUT_VERSION, false);
    }

    /**
     * Resolve the storage directory for a URL (host + path mirror).
     */
    public function dirForUrl(string $url): string
    {
        $host = (string) wp_parse_url($url, PHP_URL_HOST);
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        if ($host === '') {
            return '';
        }

        $dir = $this->dirForHost($host);
        $segments = self::pathSegments($path);
        return $dir . (empty($segments) ? '' : '/' . implode('/', $segments));
    }

    public function dirForHost(string $host): string
    {
        $host = self::sanitizeHost($host);
        return $this->cacheDir() . '/' . $host;
    }

    public static function sanitizeHost(string $host): string
    {
        $host = strtolower((string) $host);
        $host = preg_replace('/[^a-z0-9._-]/', '-', $host) ?: 'localhost';
        return trim($host, '.-');
    }

    /**
     * Split a request path into safe directory segments.
     */
    public static function pathSegments(string $path): array
    {
        $parts = explode('/', (string) $path);
        $segments = [];
        foreach ($parts as $part) {
            $part = rawurldecode($part);
            if ($part === '' || $part === '.' || $part === '..') {
                continue;
            }

            $part = preg_replace('/[^A-Za-z0-9._~-]+/', '-', $part) ?: '';
            $part = trim($part, '-');
            if ($part === '') {
                continue;
            }
            if (strlen($part) > 120) {
                $part = substr($part, 0, 100) . '-' . substr(md5($part), 0, 8);
            }
            $segments[] = $part;
        }

        return $segments;
    }

    public function requestHost(): string
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? wp_parse_url(home_url(), PHP_URL_HOST));
        return $host !== '' ? $host : (string) wp_parse_url(home_url(), PHP_URL_HOST);
    }

    public function requestPath(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        return (string) (wp_parse_url($uri, PHP_URL_PATH) ?: '/');
    }

    private function requestDir(): string
    {
        $dir = $this->dirForHost($this->requestHost());
        $segments = self::pathSegments($this->requestPath());

        return $dir . (empty($segments) ? '' : '/' . implode('/', $segments));
    }

    public function canonicalQuery(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $query = [];
        $rawQuery = wp_parse_url($uri, PHP_URL_QUERY) ?: '';
        if ($rawQuery !== '') {
            parse_str($rawQuery, $query);
            foreach ($this->settings->getCacheIgnoredQueryArgs() as $arg) {
                unset($query[$arg]);
            }
            unset($query[self::CACHE_BUST_KEY]);
            ksort($query);
        }

        return http_build_query($query);
    }

    public function isMobileUserAgent(): bool
    {
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        return (bool) preg_match('/Mobile|Android|iPhone|iPod|Opera Mini|IEMobile|BlackBerry|WPDesktop/i', $ua);
    }

    public function isPreloadRequest(): bool
    {
        if (isset($_SERVER['HTTP_X_IMGPRESS_PRELOAD'])) {
            return true;
        }

        $query = [];
        parse_str((string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_QUERY), $query);
        return isset($query[self::CACHE_BUST_KEY]);
    }

    private function isFresh(string $file): bool
    {
        if (!is_file($file) || !is_readable($file)) {
            return false;
        }

        $lifespan = $this->settings->getCacheLifespan();
        if ($lifespan === 0) {
            return true;
        }

        return (time() - (int) filemtime($file)) < $lifespan;
    }

    /**
     * Candidate files for the current request, most specific first.
     */
    private function requestCandidates(): array
    {
        $query = $this->canonicalQuery();
        $dir = $this->requestDir();
        $base = $dir . '/index';

        $hasQuery = $query !== '';
        $separateMobile = $this->settings->isCacheMobileSeparateEnabled() && $this->isMobileUserAgent();

        if ($hasQuery) {
            $queryName = '-'.md5($query);
        } else {
            $queryName = '';
        }

        $plain = $base . $queryName . '.html';
        if (!$separateMobile) {
            return [$plain];
        }

        return [$base . $queryName . '-mobile.html', $plain];
    }

    private function requestTarget(): array
    {
        $query = $this->canonicalQuery();
        $dir = $this->requestDir();
        $base = $dir . '/index';

        $hasQuery = $query !== '';
        $queryName = $hasQuery ? '-'.md5($query) : '';
        $separateMobile = $this->settings->isCacheMobileSeparateEnabled() && $this->isMobileUserAgent();
        $mobileName = $separateMobile ? '-mobile' : '';

        $file = $base . $queryName . $mobileName . '.html';

        return ['file' => $file, 'dir' => $dir];
    }

    private function writeAtomic(string $file, string $contents): bool
    {
        if ($contents === '' && !is_file($file)) {
            return false;
        }

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

    private function isCacheableRequest(): bool
    {
        if (!$this->settings->isCacheEnabled()) {
            return false;
        }

        if (is_admin() || wp_doing_ajax() || wp_doing_cron() || strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'GET') {
            return false;
        }

        if (!$this->settings->isCacheLoggedInEnabled() && is_user_logged_in()) {
            return false;
        }

        if (is_search() || is_feed() || is_preview() || is_404() || is_robots() || is_trackback()) {
            return false;
        }

        if ($this->hasExcludedCookie() || $this->isExcludedUrl()) {
            return false;
        }

        return true;
    }

    private function isExcludedUrl(): bool
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = wp_parse_url($uri, PHP_URL_PATH) ?: '/';

        foreach ($this->settings->getCacheExcludedUrls() as $pattern) {
            if ($pattern === '') {
                continue;
            }

            if (fnmatch($pattern, $path) || fnmatch($pattern, $uri) || str_contains($uri, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function hasExcludedCookie(): bool
    {
        foreach ($this->settings->getCacheExcludedCookies() as $needle) {
            if ($needle === '') {
                continue;
            }

            foreach (array_keys($_COOKIE) as $cookie) {
                if (str_contains((string) $cookie, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function deleteDirectoryContents(string $dir): bool
    {
        if (!is_dir($dir)) {
            return true;
        }

        $ok = true;
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        ) as $path) {
            $ok = $path->isDir() ? rmdir($path->getPathname()) && $ok : unlink($path->getPathname()) && $ok;
        }

        return $ok;
    }

    private function relatedUrls(int $postId, ?\WP_Post $post = null): array
    {
        $urls = [home_url('/')];
        $post = $post ?: get_post($postId);

        if (!$post instanceof \WP_Post) {
            return $urls;
        }

        // Trashed/removed posts must still clear their former permalink.
        $permalink = get_permalink($post);
        if ($permalink && !is_wp_error($permalink)) {
            $urls[] = (string) $permalink;
        }

        if ($post->post_status !== 'publish') {
            return $urls;
        }

        $archive = get_post_type_archive_link($post->post_type);
        if ($archive) {
            $urls[] = (string) $archive;
        }

        foreach (get_object_taxonomies($post->post_type) as $taxonomy) {
            $terms = get_the_terms($postId, $taxonomy);
            if (!is_array($terms)) {
                continue;
            }

            foreach ($terms as $term) {
                if (empty($term->term_id)) {
                    continue;
                }

                $link = get_term_link($term);
                if (!is_wp_error($link)) {
                    $urls[] = (string) $link;
                }
            }
        }

        return array_values(array_unique(array_filter($urls)));
    }
}
