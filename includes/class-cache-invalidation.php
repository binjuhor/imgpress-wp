<?php

namespace ImgPress;

defined('ABSPATH') || exit;

class Cache_Invalidation
{
    private Cache_Manager $cache_manager;

    public function __construct(Cache_Manager $cache_manager)
    {
        $this->cache_manager = $cache_manager;

        if (!$this->cache_manager->is_enabled()) {
            return;
        }

        $this->setup_hooks();
    }

    private function setup_hooks(): void
    {
        add_action('save_post', [$this, 'on_post_save'], 10, 2);
        add_action('post_updated', [$this, 'on_post_updated'], 10, 3);
        add_action('transition_post_status', [$this, 'on_post_status_change'], 10, 3);
        add_action('delete_post', [$this, 'on_post_delete']);
        add_action('comment_post', [$this, 'on_comment_posted']);
        add_action('edit_comment', [$this, 'on_comment_edited']);
        add_action('delete_comment', [$this, 'on_comment_deleted']);
        add_action('switch_theme', [$this, 'on_theme_switch']);
        add_action('wp_update_nav_menu', [$this, 'on_menu_update']);

        add_action('imgpress_compress_complete', [$this, 'on_image_compressed']);
        add_action('imgpress_r2_upload_complete', [$this, 'on_r2_upload']);
    }

    public function on_post_save(int $post_id, \WP_Post $post): void
    {
        if (!$this->should_invalidate_post($post_id, $post)) {
            return;
        }

        if ($this->cache_manager->get_option('cache_clear_on_new')) {
            $this->purge_post_cache($post_id);
            $this->purge_related_pages();
        }
    }

    public function on_post_updated(int $post_id, \WP_Post $post_after, \WP_Post $post_before): void
    {
        if (!$this->should_invalidate_post($post_id, $post_after)) {
            return;
        }

        if ($post_after->post_status === 'publish' && $this->cache_manager->get_option('cache_clear_on_update')) {
            $this->purge_post_cache($post_id);
            $this->purge_related_pages();
        }
    }

    public function on_post_status_change(string $new_status, string $old_status, \WP_Post $post): void
    {
        if ($new_status !== 'publish' && $old_status === 'publish') {
            $this->purge_post_cache($post->ID);
            $this->purge_related_pages();
        }
    }

    public function on_post_delete(int $post_id): void
    {
        $this->purge_post_cache($post_id);
        $this->purge_related_pages();
    }

    public function on_comment_posted(int $comment_ID): void
    {
        $comment = get_comment($comment_ID);
        if ($comment && $comment->comment_approved) {
            $post_id = (int) $comment->comment_post_ID;
            $this->purge_post_cache($post_id);
        }
    }

    public function on_comment_edited(int $comment_ID): void
    {
        $this->on_comment_posted($comment_ID);
    }

    public function on_comment_deleted(int $comment_ID): void
    {
        $comment = get_comment($comment_ID);
        if ($comment) {
            $post_id = (int) $comment->comment_post_ID;
            $this->purge_post_cache($post_id);
        }
    }

    public function on_theme_switch(): void
    {
        $this->purge_all();
    }

    public function on_menu_update(): void
    {
        $this->purge_all();
    }

    public function on_image_compressed(int $post_id): void
    {
        $this->purge_post_cache($post_id);
    }

    public function on_r2_upload(int $post_id): void
    {
        $this->purge_post_cache($post_id);
    }

    private function should_invalidate_post(int $post_id, \WP_Post $post): bool
    {
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return false;
        }

        $post_type = get_post_type($post_id);
        if (!in_array($post_type, ['post', 'page'], true)) {
            return false;
        }

        return true;
    }

    private function purge_post_cache(int $post_id): void
    {
        $post = get_post($post_id);
        if (!$post) {
            return;
        }

        $permalink = get_permalink($post_id);
        if ($permalink) {
            $cache_key = $this->cache_manager->get_cache_key($permalink);
            $this->cache_manager->purge_cache_key($cache_key);
        }
    }

    private function purge_related_pages(): void
    {
        $home = home_url('/');
        $cache_key = $this->cache_manager->get_cache_key($home);
        $this->cache_manager->purge_cache_key($cache_key);

        $cache_key = $this->cache_manager->get_cache_key(home_url('/blog/'));
        $this->cache_manager->purge_cache_key($cache_key);

        $cache_key = $this->cache_manager->get_cache_key(home_url('/news/'));
        $this->cache_manager->purge_cache_key($cache_key);
    }

    public function purge_all(): void
    {
        $this->cache_manager->purge_all();
        $this->log_invalidation('manual_clear_all', null);
        $this->cache_manager->cleanup_by_size_limit();
    }

    public function purge_by_pattern(string $pattern): int
    {
        $count = $this->cache_manager->purge_by_pattern($pattern);
        if ($count > 0) {
            $this->log_invalidation('purge_pattern', null);
        }
        return $count;
    }

    public function get_invalidation_log(): array
    {
        $log_file = $this->cache_manager->get_cache_dir() . 'invalidation.log';
        if (!file_exists($log_file)) {
            return [];
        }

        $lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $entries = [];

        foreach (array_slice($lines, -50) as $line) {
            $entry = json_decode($line, true);
            if ($entry) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    private function log_invalidation(string $reason, ?int $post_id = null): void
    {
        if (!$this->cache_manager->get_option('cache_log_enabled', false)) {
            return;
        }

        $log_file = $this->cache_manager->get_cache_dir() . 'invalidation.log';
        $log_dir = dirname($log_file);

        if (!is_dir($log_dir)) {
            wp_mkdir_p($log_dir);
        }

        $entry = [
            'timestamp' => time(),
            'reason'    => $reason,
            'post_id'   => $post_id,
            'url'       => $_SERVER['REQUEST_URI'] ?? '',
        ];

        $line = json_encode($entry) . "\n";
        error_log($line, 3, $log_file);

        $this->rotate_invalidation_log($log_file);
    }

    private function rotate_invalidation_log(string $log_file): void
    {
        if (!file_exists($log_file)) {
            return;
        }

        $lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (count($lines) > 1000) {
            $keep_lines = array_slice($lines, -500);
            file_put_contents($log_file, implode("\n", $keep_lines) . "\n");
        }
    }
}
