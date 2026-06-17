<?php

namespace ImgPress;

defined('ABSPATH') || exit;

class Cache_CDN
{
    private Cache_Manager $cache_manager;
    private string $cdn_url = '';

    public function __construct(Cache_Manager $cache_manager)
    {
        $this->cache_manager = $cache_manager;
        $this->cdn_url = $this->cache_manager->get_option('cache_cdn_url', '');

        if (!$this->cache_manager->is_enabled()) {
            return;
        }

        if (empty($this->cdn_url)) {
            return;
        }

        add_filter('imgpress_cache_content', [$this, 'rewrite_urls_to_cdn']);
    }

    public function rewrite_urls_to_cdn(string $content): string
    {
        if (empty($content)) {
            return $content;
        }

        $site_url = home_url();

        $content = $this->rewrite_asset_urls($content, $site_url);
        $content = $this->rewrite_image_urls($content, $site_url);

        return $content;
    }

    private function rewrite_asset_urls(string $content, string $site_url): string
    {
        $asset_patterns = ['/wp-content/uploads/', '/wp-content/themes/', '/wp-content/plugins/'];

        foreach ($asset_patterns as $pattern) {
            $site_path = str_replace(home_url('/'), '', $site_url);
            $escaped_pattern = preg_quote($pattern, '/');

            $content = preg_replace_callback(
                '/href=["\'](.*?' . $escaped_pattern . '[^\s"\']*)["\']/',
                function ($matches) use ($pattern) {
                    $url = $matches[1];
                    if (strpos($url, 'http') !== 0) {
                        $url = home_url($url);
                    }

                    return 'href="' . $this->cdn_url_for($url) . '"';
                },
                $content
            );

            $content = preg_replace_callback(
                '/src=["\'](.*?' . $escaped_pattern . '[^\s"\']*)["\']/',
                function ($matches) use ($pattern) {
                    $url = $matches[1];
                    if (strpos($url, 'http') !== 0) {
                        $url = home_url($url);
                    }

                    return 'src="' . $this->cdn_url_for($url) . '"';
                },
                $content
            );
        }

        return $content;
    }

    private function rewrite_image_urls(string $content, string $site_url): string
    {
        return preg_replace_callback(
            '/src=["\']((?:https?:)?\/\/(?:[^\s"\']+\.jpg|[^\s"\']+\.png|[^\s"\']+\.gif|[^\s"\']+\.webp))["\']/',
            function ($matches) {
                $url = $matches[1];

                if (strpos($url, 'http') === false) {
                    $url = home_url($url);
                }

                if (strpos($url, home_url()) === 0) {
                    return 'src="' . $this->cdn_url_for($url) . '"';
                }

                return $matches[0];
            },
            $content
        );
    }

    private function cdn_url_for(string $url): string
    {
        $site_url = home_url();
        $relative_url = str_replace($site_url, '', $url);

        if (strpos($relative_url, '/') !== 0) {
            $relative_url = '/' . $relative_url;
        }

        return rtrim($this->cdn_url, '/') . $relative_url;
    }

    public static function get_cdn_url(): string
    {
        $cache_manager = new Cache_Manager();
        return $cache_manager->get_option('cache_cdn_url', '');
    }

    public static function set_cdn_url(string $url): void
    {
        $cache_manager = new Cache_Manager();
        $options = (array) get_option('imgpress_wp_options', []);
        $options['cache_cdn_url'] = esc_url_raw($url);
        update_option('imgpress_wp_options', $options);
    }
}
