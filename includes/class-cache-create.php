<?php

namespace ImgPress;

defined('ABSPATH') || exit;

class Cache_Create
{
    private Cache_Manager $cache_manager;
    private string $buffer_content = '';
    private bool $should_cache = false;

    public function __construct(Cache_Manager $cache_manager)
    {
        $this->cache_manager = $cache_manager;

        if ($this->cache_manager->is_enabled()) {
            add_action('init', [$this, 'start_buffer'], -9999);
            add_action('shutdown', [$this, 'end_buffer'], -9999);
        }
    }

    public function start_buffer(): void
    {
        if (!$this->cache_manager->should_cache_request()) {
            return;
        }

        $this->should_cache = true;
        ob_start([$this, 'process_buffer']);
    }

    public function process_buffer(string $buffer): string
    {
        $this->buffer_content = $buffer;
        return $buffer;
    }

    public function end_buffer(): void
    {
        if (!$this->should_cache || !$this->buffer_content) {
            return;
        }

        try {
            $content = apply_filters('imgpress_cache_content', $this->buffer_content);
            $this->cache_manager->write_cache($content);
        } catch (\Exception $e) {
            error_log('ImgPress Cache Error: ' . $e->getMessage());
        }
    }

    public function serve_cached_page(): bool
    {
        if (!$this->cache_manager->is_enabled()) {
            return false;
        }

        if (!$this->cache_manager->should_cache_request()) {
            return false;
        }

        $cached_content = $this->cache_manager->read_cache();

        if (!$cached_content) {
            return false;
        }

        header('Content-Type: text/html; charset=UTF-8');
        header('X-Cache: HIT');

        if (function_exists('gzencode') && isset($_SERVER['HTTP_ACCEPT_ENCODING'])) {
            if (strpos($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') !== false) {
                header('Content-Encoding: gzip');
                echo gzencode($cached_content, 9);
                return true;
            }
        }

        echo $cached_content;
        return true;
    }

    public function get_current_page_type(): string
    {
        if (is_front_page()) {
            return 'homepage';
        } elseif (is_category()) {
            return 'category';
        } elseif (is_tag()) {
            return 'tag';
        } elseif (is_singular('post')) {
            return 'post';
        } elseif (is_page()) {
            return 'page';
        } elseif (is_attachment()) {
            return 'attachment';
        } elseif (is_archive()) {
            return 'archive';
        } elseif (is_search()) {
            return 'search';
        } else {
            return 'other';
        }
    }
}
