<?php

namespace ImgPress;

use MatthiasMullie\Minify;

defined('ABSPATH') || exit;

class Asset_Optimizer
{
    public function __construct(private Settings $settings, private Logger $logger)
    {
    }

    public function init(): void
    {
        add_filter('style_loader_src', [$this, 'maybeMinifyCss'], 20, 2);
        add_filter('script_loader_src', [$this, 'maybeMinifyJs'], 20, 2);
        add_action('admin_post_imgpress_purge_asset_cache', [$this, 'handlePurge']);
    }

    public function maybeMinifyCss(string $src, string $handle): string
    {
        if (!$this->settings->isCssMinifyEnabled()) {
            return $src;
        }

        return $this->minify($src, 'css');
    }

    public function maybeMinifyJs(string $src, string $handle): string
    {
        if (!$this->settings->isJsMinifyEnabled()) {
            return $src;
        }

        return $this->minify($src, 'js');
    }

    public function purge(): bool
    {
        $dir = $this->cacheDir();
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

        if ($ok) {
            $this->logger->info('Asset optimization cache purged.');
        }

        return $ok;
    }

    public function handlePurge(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized', 'imgpress-wp'), 403);
        }

        check_admin_referer('imgpress_purge_asset_cache');
        $this->purge();
        wp_safe_redirect(admin_url('admin.php?page=imgpress'));
        exit;
    }

    public function cacheCount(): int
    {
        $files = glob($this->cacheDir() . '/*.{css,js}', GLOB_BRACE);
        return is_array($files) ? count($files) : 0;
    }

    private function minify(string $src, string $type): string
    {
        if (is_admin() || $this->isExcluded($src) || str_contains($src, '.min.' . $type)) {
            return $src;
        }

        $path = $this->urlToLocalPath($src);
        if ($path === '' || !is_readable($path)) {
            return $src;
        }

        $mtime = (string) filemtime($path);
        $target = $this->cacheDir() . '/' . md5($path . $mtime) . '.min.' . $type;
        if (!file_exists($target)) {
            wp_mkdir_p(dirname($target));
            try {
                $minifier = $type === 'css' ? new Minify\CSS($path) : new Minify\JS($path);
                $minifier->minify($target);
            } catch (\Throwable $throwable) {
                $this->logger->warning('Asset minification failed.', [
                    'type' => $type,
                    'file' => basename($path),
                    'error' => $throwable->getMessage(),
                ]);

                return $src;
            }
        }

        return content_url('cache/imgpress/assets/' . basename($target));
    }

    private function urlToLocalPath(string $src): string
    {
        $src = html_entity_decode($src);
        $srcParts = wp_parse_url($src);
        $homeParts = wp_parse_url(home_url());

        if (!empty($srcParts['host']) && !empty($homeParts['host']) && strtolower($srcParts['host']) !== strtolower($homeParts['host'])) {
            return '';
        }

        $path = $srcParts['path'] ?? '';
        if ($path === '') {
            return '';
        }

        $contentPath = wp_parse_url(content_url(), PHP_URL_PATH) ?: '/wp-content';
        if (str_starts_with($path, $contentPath)) {
            return WP_CONTENT_DIR . substr($path, strlen($contentPath));
        }

        return ABSPATH . ltrim($path, '/');
    }

    private function isExcluded(string $src): bool
    {
        foreach ($this->settings->getOptimizeExcludedAssets() as $needle) {
            if ($needle !== '' && str_contains($src, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function cacheDir(): string
    {
        return WP_CONTENT_DIR . '/cache/imgpress/assets';
    }
}
