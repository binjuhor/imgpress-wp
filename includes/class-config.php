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
            'api_url' => 'http://imgpress.binjuhor.com',
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
            'optimize_js_minify' => false,
            'optimize_html_minify' => false,
            'optimize_excluded_assets' => '',
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
