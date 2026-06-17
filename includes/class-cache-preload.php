<?php

namespace ImgPress;

defined('ABSPATH') || exit;

class Cache_Preload
{
    private Cache_Manager $cache_manager;

    public function __construct(Cache_Manager $cache_manager)
    {
        $this->cache_manager = $cache_manager;

        if (!$this->cache_manager->is_enabled()) {
            return;
        }

        if (!$this->cache_manager->get_option('cache_preload_enabled', false)) {
            return;
        }

        add_filter('imgpress_cache_content', [$this, 'add_preload_hints']);
    }

    public function add_preload_hints(string $content): string
    {
        if (empty($content)) {
            return $content;
        }

        $preload_links = $this->extract_preload_resources($content);

        if (empty($preload_links)) {
            return $content;
        }

        $head_pos = stripos($content, '</head>');
        if ($head_pos === false) {
            return $content;
        }

        $links_html = implode("\n", $preload_links);
        $content = substr_replace($content, $links_html . "\n</head>", $head_pos, 7);

        return $content;
    }

    private function extract_preload_resources(string $content): array
    {
        $preload_links = [];

        preg_match_all('/<link[^>]+rel=["\']stylesheet["\'][^>]+href=["\']([^"\']+)["\'][^>]*>/i', $content, $stylesheets);
        foreach ($stylesheets[1] ?? [] as $href) {
            if ($this->should_preload_stylesheet($href)) {
                $preload_links[] = sprintf('<link rel="preload" as="style" href="%s">', esc_attr($href));
            }
        }

        preg_match_all('/<script[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $content, $scripts);
        foreach ($scripts[1] ?? [] as $src) {
            if ($this->should_preload_script($src)) {
                $preload_links[] = sprintf('<link rel="preload" as="script" href="%s">', esc_attr($src));
            }
        }

        preg_match_all('/<link[^>]+href=["\']([^"\']+\.(woff2?|ttf|otf))["\'][^>]*>/i', $content, $fonts);
        foreach ($fonts[1] ?? [] as $href) {
            $preload_links[] = sprintf('<link rel="preload" as="font" href="%s" crossorigin>', esc_attr($href));
        }

        return array_unique($preload_links);
    }

    private function should_preload_stylesheet(string $href): bool
    {
        $critical_patterns = [
            'bootstrap',
            'style.css',
            'main.css',
            'critical',
        ];

        foreach ($critical_patterns as $pattern) {
            if (stripos($href, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    private function should_preload_script(string $src): bool
    {
        $critical_patterns = [
            'jquery',
            'bootstrap.min.js',
            'main.js',
        ];

        foreach ($critical_patterns as $pattern) {
            if (stripos($src, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }
}
