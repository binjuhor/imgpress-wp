<?php

namespace ImgPress;

defined('ABSPATH') || exit;

class Page_Cache
{
    private ?string $pendingFile = null;
    private ?string $pendingMetaFile = null;
    private int $startedAt = 0;

    public function __construct(private Settings $settings, private Logger $logger)
    {
    }

    public function init(): void
    {
        add_action('template_redirect', [$this, 'maybeServe'], 0);
        add_action('save_post', [$this, 'purgeRelated'], 10, 3);
        add_action('deleted_post', [$this, 'purgeRelated']);
        add_action('switch_theme', [$this, 'purgeAll']);
        add_action('comment_post', [$this, 'purgeAll']);
        add_action('edit_comment', [$this, 'purgeAll']);
        add_action('wp_update_nav_menu', [$this, 'purgeAll']);
    }

    public function maybeServe(): void
    {
        if (!$this->isCacheableRequest()) {
            return;
        }

        $file = $this->cacheFile();
        $metaFile = $file . '.json';

        if ($this->isFresh($file, $metaFile)) {
            header('X-ImgPress-Cache: HIT');
            readfile($file);
            exit;
        }

        header('X-ImgPress-Cache: MISS');
        $this->pendingFile = $file;
        $this->pendingMetaFile = $metaFile;
        $this->startedAt = microtime(true) * 1000;
        ob_start([$this, 'storeBuffer']);
    }

    public function storeBuffer(string $html): string
    {
        if ($this->pendingFile === null || $this->pendingMetaFile === null) {
            return $html;
        }

        $statusCode = http_response_code();
        if ($statusCode !== 200 || stripos($html, '</html>') === false) {
            return $html;
        }

        wp_mkdir_p(dirname($this->pendingFile));
        file_put_contents($this->pendingFile, $html, LOCK_EX);
        file_put_contents($this->pendingMetaFile, wp_json_encode([
            'created_at' => time(),
            'url' => $this->currentUrl(),
            'duration_ms' => max(0, (int) ((microtime(true) * 1000) - $this->startedAt)),
        ]), LOCK_EX);

        return $html;
    }

    public function purgeAll(): bool
    {
        $dir = $this->cacheDir();
        if (!is_dir($dir)) {
            return true;
        }

        $deleted = $this->deleteDirectoryContents($dir);
        if ($deleted) {
            $this->logger->info('Page cache purged.');
        }

        return $deleted;
    }

    public function purgeUrl(string $url): bool
    {
        $file = $this->cacheFileForUrl($url);
        $meta = $file . '.json';
        $ok = true;

        if (is_file($file)) {
            $ok = unlink($file) && $ok;
        }

        if (is_file($meta)) {
            $ok = unlink($meta) && $ok;
        }

        return $ok;
    }

    public function purgeUrls(array $urls): bool
    {
        $ok = true;
        foreach ($urls as $url) {
            $ok = $this->purgeUrl((string) $url) && $ok;
        }

        return $ok;
    }

    public function warmUrl(string $url): void
    {
        $response = wp_remote_get($url, [
            'timeout' => 15,
            'redirection' => 3,
            'headers' => [
                'X-ImgPress-Preload' => '1',
            ],
        ]);

        if (is_wp_error($response)) {
            $this->logger->warning('Cache preload request failed.', [
                'url' => $url,
                'error' => $response->get_error_message(),
            ]);
        }
    }

    public function purgeRelated(int $postId = 0, ?\WP_Post $post = null, bool $update = false): void
    {
        $urls = $this->relatedUrls($postId, $post);
        if (empty($urls)) {
            return;
        }

        $this->purgeUrls($urls);
        $this->logger->info('Related cache purged.', [
            'post_id' => (string) $postId,
        ]);
    }

    public function cacheCount(): int
    {
        $dir = $this->cacheDir();
        if (!is_dir($dir)) {
            return 0;
        }

        $files = glob($dir . '/*.html');
        return is_array($files) ? count($files) : 0;
    }

    public function cacheSize(): int
    {
        $dir = $this->cacheDir();
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

    private function isCacheableRequest(): bool
    {
        if (!$this->settings->isCacheEnabled()) {
            return false;
        }

        if (is_admin() || wp_doing_ajax() || wp_doing_cron() || strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
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

    private function isFresh(string $file, string $metaFile): bool
    {
        if (!is_readable($file) || !is_readable($metaFile)) {
            return false;
        }

        $meta = json_decode((string) file_get_contents($metaFile), true);
        $createdAt = is_array($meta) ? (int) ($meta['created_at'] ?? 0) : 0;

        return $createdAt > 0 && (time() - $createdAt) < $this->settings->getCacheLifespan();
    }

    private function cacheFile(): string
    {
        return $this->cacheFileForUrl($this->currentUrl());
    }

    public function cacheFileForUrl(string $url): string
    {
        return $this->cacheDir() . '/' . md5($url) . '.html';
    }

    private function currentUrl(): string
    {
        $scheme = is_ssl() ? 'https' : 'http';
        $host = sanitize_text_field($_SERVER['HTTP_HOST'] ?? wp_parse_url(home_url(), PHP_URL_HOST));
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $parts = wp_parse_url($uri);
        $path = $parts['path'] ?? '/';
        $query = [];

        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
            foreach ($this->settings->getCacheIgnoredQueryArgs() as $arg) {
                unset($query[$arg]);
            }
            ksort($query);
        }

        return $scheme . '://' . $host . $path . (empty($query) ? '' : '?' . http_build_query($query));
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
        $post = $post ?: get_post($postId);
        if (!$post instanceof \WP_Post || $post->post_status !== 'publish') {
            return [home_url('/')];
        }

        $urls = [home_url('/'), get_permalink($postId)];
        $archive = get_post_type_archive_link($post->post_type);
        if ($archive) {
            $urls[] = $archive;
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
                    $urls[] = $link;
                }
            }
        }

        return array_values(array_unique(array_filter($urls)));
    }
}
