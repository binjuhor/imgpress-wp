<?php

namespace ImgPress;

defined('ABSPATH') || exit;

class Cache_Minify
{
    private Cache_Manager $cache_manager;

    public function __construct(Cache_Manager $cache_manager)
    {
        $this->cache_manager = $cache_manager;

        if (!$this->cache_manager->is_enabled()) {
            return;
        }

        if (!$this->cache_manager->get_option('cache_minify_enabled', true)) {
            return;
        }

        add_filter('imgpress_cache_content', [$this, 'minify_html']);
    }

    public function minify_html(string $content): string
    {
        if (empty($content)) {
            return $content;
        }

        $content = $this->minify_html_content($content);
        $content = $this->minify_inline_css($content);
        $content = $this->minify_inline_js($content);

        return $content;
    }

    private function minify_html_content(string $content): string
    {
        $content = preg_replace('/<!--.*?-->/s', '', $content);
        $content = preg_replace('/\s+/s', ' ', $content);
        $content = preg_replace('/\s*([<>])\s*/', '$1', $content);
        $content = preg_replace('/>\s+</', '><', $content);

        return trim($content);
    }

    private function minify_inline_css(string $content): string
    {
        return preg_replace_callback('/<style[^>]*>(.*?)<\/style>/is', function ($matches) {
            $css = $matches[1];
            $css = preg_replace('/\/\*.*?\*\//s', '', $css);
            $css = preg_replace('/\s+/', ' ', $css);
            $css = preg_replace('/\s*([{}:;,>+~])\s*/', '$1', $css);
            $css = trim($css);

            return '<style>' . $css . '</style>';
        }, $content);
    }

    private function minify_inline_js(string $content): string
    {
        return preg_replace_callback('/<script[^>]*>(.*?)<\/script>/is', function ($matches) {
            $js = $matches[1];

            if (trim($js) === '') {
                return $matches[0];
            }

            $js = preg_replace('/\/\/.*?$/m', '', $js);
            $js = preg_replace('/\/\*.*?\*\//s', '', $js);
            $js = preg_replace('/\s+/', ' ', $js);
            $js = preg_replace('/\s*([{}();:,=])\s*/', '$1', $js);
            $js = trim($js);

            return '<script>' . $js . '</script>';
        }, $content);
    }

    public static function get_minification_ratio(string $original, string $minified): float
    {
        $original_size = strlen($original);
        if ($original_size === 0) {
            return 0;
        }

        return (($original_size - strlen($minified)) / $original_size) * 100;
    }
}
