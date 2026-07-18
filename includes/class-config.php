<?php

namespace ImgPress;

defined('ABSPATH') || exit;

class Config
{
    public const OPTION_KEY = 'imgpress_wp_options';

    public static function defaults(): array
    {
        return [
            'config_version' => IMGPRESS_WP_VERSION,
            'api_url' => 'https://imgpress.org',
            'auto_compress' => true,
            'quality' => 80,
            'format' => 'webp',
            'max_width' => 1600,
            'enabled_types' => ['image', 'pdf', 'audio', 'video'],
            'request_timeout' => 120,
            'r2_enabled' => false,
            'r2_account_id' => '',
            'r2_access_key' => '',
            'r2_secret_key' => '',
            'r2_bucket' => '',
            'r2_custom_domain' => '',
            'r2_push_on_compress' => false,
            'r2_push_on_upload' => false,
            'r2_delete_local' => false,
            'r2_rewrite_content' => false,
            'cache_enabled' => false,
            'cache_lifespan' => DAY_IN_SECONDS,
            'cache_advanced_dropin' => false,
            'cache_preload' => false,
            'cache_logged_in' => false,
            'cache_mobile_separate' => false,
            'cache_excluded_urls' => "/wp-admin/*\n/wp-login.php*",
            'cache_excluded_cookies' => "wordpress_logged_in_\nwp-postpass_\nwoocommerce_items_in_cart",
            'cache_ignored_query_args' => "utm_source\nutm_medium\nutm_campaign\nutm_content\nutm_term\nfbclid\ngclid",
            'optimize_css_minify' => false,
            'optimize_css_rucss' => false,
            'optimize_css_rucss_method' => 'async',
            'optimize_css_rucss_exclude_stylesheets' => '',
            'optimize_css_rucss_include_selectors' => '',
            'optimize_js_minify' => false,
            'optimize_js_defer' => false,
            'optimize_js_defer_excludes' => '',
            'optimize_js_delay' => false,
            'optimize_js_delay_method' => 'selected',
            'optimize_js_delay_all_excludes' => '',
            'optimize_js_delay_selected' => "googletagmanager.com\ngoogle-analytics.com\nfbq\nadsbygoogle.js",
            'optimize_html_minify' => false,
            'optimize_excluded_assets' => '',
            'db_cleanup_enabled' => false,
            'db_cleanup_schedule' => 'off',
            'db_cleanup_post_revisions' => true,
            'db_cleanup_trashed_contents' => true,
            'db_cleanup_trashed_spam_comments' => true,
            'db_cleanup_trackback_pingback' => true,
            'db_cleanup_transient_options' => true,
            'db_cleanup_orphaned_post_meta' => true,
            'db_cleanup_orphaned_comment_meta' => true,
            'db_cleanup_orphaned_user_meta' => true,
            'db_cleanup_orphaned_term_meta' => true,
            'db_cleanup_orphaned_term_relationships' => true,
            'bloat_disable_jquery_migrate' => false,
            'bloat_disable_emojis' => false,
            'bloat_disable_block_css' => false,
            'bloat_disable_oembeds' => false,
            'bloat_disable_dashicons' => false,
            'bloat_disable_xml_rpc' => false,
            'bloat_disable_rss_feed' => false,
        ];
    }

    public static function all(): array
    {
        return array_merge(self::defaults(), (array) get_option(self::OPTION_KEY, []));
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $options = self::all();
        return $options[$key] ?? $default;
    }

    public static function migrate(): void
    {
        $existing = get_option(self::OPTION_KEY);
        if (!is_array($existing)) {
            update_option(self::OPTION_KEY, self::defaults());
            return;
        }

        $merged = array_merge(self::defaults(), $existing);
        $merged['config_version'] = IMGPRESS_WP_VERSION;

        if ($merged !== $existing) {
            update_option(self::OPTION_KEY, $merged);
        }
    }
}
