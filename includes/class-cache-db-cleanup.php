<?php

namespace ImgPress;

defined('ABSPATH') || exit;

class Cache_DB_Cleanup
{
    private Cache_Manager $cache_manager;

    public function __construct(Cache_Manager $cache_manager)
    {
        $this->cache_manager = $cache_manager;

        add_action('init', [$this, 'schedule_cleanup']);
        add_action('imgpress_cache_cleanup', [$this, 'run_cleanup']);
    }

    public function schedule_cleanup(): void
    {
        if (wp_next_scheduled('imgpress_cache_cleanup')) {
            return;
        }

        wp_schedule_event(time(), 'daily', 'imgpress_cache_cleanup');
    }

    public function run_cleanup(): void
    {
        $this->cleanup_old_transients();
        $this->cleanup_orphaned_posts();
    }

    private function cleanup_old_transients(): void
    {
        global $wpdb;

        $transient_prefix = '_transient_timeout_imgpress';

        $old_transients = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_id FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
                $wpdb->esc_like($transient_prefix) . '%',
                time()
            )
        );

        foreach ($old_transients as $option_id) {
            delete_transient(substr(get_option('option_name'), strlen('_transient_timeout_')));
        }
    }

    private function cleanup_orphaned_posts(): void
    {
        global $wpdb;

        $orphaned = $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'auto-draft' AND post_date < DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );

        foreach ($orphaned as $post_id) {
            wp_delete_post($post_id, true);
        }
    }

    public static function cleanup_on_uninstall(): void
    {
        global $wpdb;

        delete_option('imgpress_wp_options');

        wp_clear_scheduled_hook('imgpress_cache_cleanup');

        $transient_keys = $wpdb->get_col(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient%imgpress%'"
        );

        foreach ($transient_keys as $key) {
            delete_option($key);
        }
    }
}
