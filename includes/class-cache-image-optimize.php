<?php

namespace ImgPress;

defined('ABSPATH') || exit;

class Cache_Image_Optimize
{
    private Cache_Manager $cache_manager;

    public function __construct(Cache_Manager $cache_manager)
    {
        $this->cache_manager = $cache_manager;

        if (!$this->cache_manager->is_enabled()) {
            return;
        }

        if (!$this->cache_manager->get_option('cache_image_lazy_enabled', true)) {
            return;
        }

        add_filter('imgpress_cache_content', [$this, 'optimize_images']);
    }

    public function optimize_images(string $content): string
    {
        if (empty($content)) {
            return $content;
        }

        if ($this->cache_manager->get_option('cache_image_lazy_enabled', true)) {
            $content = $this->add_lazy_loading($content);
        }

        return $content;
    }

    private function add_lazy_loading(string $content): string
    {
        return preg_replace_callback(
            '/<img([^>]*)>/i',
            function ($matches) {
                $attrs = $matches[1];
                $full_tag = $matches[0];

                if (stripos($attrs, 'loading') !== false) {
                    return $full_tag;
                }

                if (stripos($attrs, 'wp-lazyload') !== false) {
                    return $full_tag;
                }

                if (stripos($attrs, 'lazyload') !== false) {
                    return $full_tag;
                }

                if ($this->is_above_fold($attrs)) {
                    return $full_tag;
                }

                return str_ireplace('<img', '<img loading="lazy"', $full_tag);
            },
            $content
        );
    }

    private function is_above_fold(string $attrs): bool
    {
        if (preg_match('/class=["\']([^"\']*)["\']/', $attrs, $matches)) {
            $classes = $matches[1];

            $above_fold_patterns = [
                'logo',
                'header',
                'banner',
                'hero',
                'featured',
                'thumbnail',
            ];

            foreach ($above_fold_patterns as $pattern) {
                if (stripos($classes, $pattern) !== false) {
                    return true;
                }
            }
        }

        if (preg_match('/src=["\']([^"\']+)["\']/', $attrs, $matches)) {
            $src = $matches[1];

            if (stripos($src, 'logo') !== false) {
                return true;
            }
        }

        return false;
    }
}
