<?php

namespace ImgPress;

defined('ABSPATH') || exit;

class Cache_JS_Optimize
{
    private Cache_Manager $cache_manager;

    public function __construct(Cache_Manager $cache_manager)
    {
        $this->cache_manager = $cache_manager;

        if (!$this->cache_manager->is_enabled()) {
            return;
        }

        if (!$this->cache_manager->get_option('cache_js_defer_enabled', true)) {
            return;
        }

        add_filter('imgpress_cache_content', [$this, 'optimize_javascript']);
    }

    public function optimize_javascript(string $content): string
    {
        if (empty($content)) {
            return $content;
        }

        if ($this->cache_manager->get_option('cache_js_defer_enabled', true)) {
            $content = $this->add_defer_attribute($content);
        }

        if ($this->cache_manager->get_option('cache_js_lazy_enabled', false)) {
            $content = $this->add_lazy_load($content);
        }

        return $content;
    }

    private function add_defer_attribute(string $content): string
    {
        return preg_replace_callback(
            '/<script([^>]*)>(.*?)<\/script>/is',
            function ($matches) {
                $attrs = $matches[1];
                $script_content = $matches[2];

                if (empty(trim($script_content))) {
                    return $matches[0];
                }

                if (stripos($attrs, 'defer') !== false || stripos($attrs, 'async') !== false) {
                    return $matches[0];
                }

                if (stripos($attrs, 'type="module"') !== false) {
                    return $matches[0];
                }

                $attrs = rtrim($attrs) . ' defer';

                return '<script' . $attrs . '>' . $script_content . '</script>';
            },
            $content
        );
    }

    private function add_lazy_load(string $content): string
    {
        return preg_replace_callback(
            '/<script[^>]*src="([^"]+)"[^>]*>/i',
            function ($matches) {
                $src = $matches[1];
                $full_tag = $matches[0];

                if ($this->is_critical_script($src)) {
                    return $full_tag;
                }

                return str_ireplace('<script', '<script loading="lazy"', $full_tag);
            },
            $content
        );
    }

    private function is_critical_script(string $src): bool
    {
        $critical_patterns = [
            'jquery',
            'bootstrap',
            'analytics',
            'gtag',
            'tracking',
        ];

        foreach ($critical_patterns as $pattern) {
            if (stripos($src, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }
}
