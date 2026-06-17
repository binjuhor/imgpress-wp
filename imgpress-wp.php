<?php
/**
 * Plugin Name: ImgPress
 * Plugin URI:  https://github.com/binjuhor/imgpress-wp
 * Description: Automatically compress images, PDFs, audio, and video via the imgpress API.
 * Version:     1.1.1
 * Author:      Binjuhor
 * Author URI: https://binjuhor.com
 * License:     MIT
 * Text Domain: imgpress-wp
 */

defined('ABSPATH') || exit;

defined('IMGPRESS_WP_VERSION') || define('IMGPRESS_WP_VERSION', '1.1.0');
defined('IMGPRESS_WP_DIR')     || define('IMGPRESS_WP_DIR', plugin_dir_path(__FILE__));
defined('IMGPRESS_WP_URL')     || define('IMGPRESS_WP_URL', plugin_dir_url(__FILE__));

spl_autoload_register(function (string $class): void {
    if (!str_starts_with($class, 'ImgPress\\')) {
        return;
    }
    $relative = str_replace('ImgPress\\', '', $class);
    $file = IMGPRESS_WP_DIR . 'includes/class-' . strtolower(str_replace('_', '-', $relative)) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

register_activation_hook(__FILE__, function (): void {
    if (!get_option('imgpress_wp_options')) {
        add_option('imgpress_wp_options', [
            'api_url'        => 'http://imgpress.binjuhor.com',
            'auto_compress'  => true,
            'quality'        => 80,
            'format'         => 'webp',
            'max_width'      => 1600,
            'enabled_types'  => ['image', 'pdf', 'audio', 'video'],
            'request_timeout' => 120,
            'r2_enabled'          => false,
            'r2_account_id'       => '',
            'r2_access_key'       => '',
            'r2_secret_key'       => '',
            'r2_bucket'           => '',
            'r2_custom_domain'    => '',
            'r2_push_on_compress' => false,
            'r2_push_on_upload'   => false,
            'r2_delete_local'     => false,
            'r2_rewrite_content'  => false,
            'cache_enabled'       => false,
            'cache_ttl'           => 0,
            'cache_gzip'          => true,
            'cache_mobile'        => false,
            'cache_logged_in'     => false,
            'cache_clear_on_new'  => true,
            'cache_clear_on_update' => true,
            'cache_size_limit'    => 524288000,
            'cache_log_enabled'   => false,
            'cache_etag_enabled'  => true,
            'cache_last_modified' => true,
            'cache_minify_enabled' => true,
            'cache_js_defer_enabled' => true,
            'cache_js_lazy_enabled' => false,
            'cache_image_lazy_enabled' => true,
            'cache_preload_enabled' => false,
            'cache_cdn_url' => '',
        ]);
    }
});

add_action('plugins_loaded', function (): void {
    $settings      = new ImgPress\Settings();
    $apiClient     = new ImgPress\Api_Client($settings);
    $r2Client      = new ImgPress\R2_Client($settings);
    $r2Uploader    = new ImgPress\R2_Uploader($r2Client, $settings);
    $compressor    = new ImgPress\Compressor($apiClient, $settings, $r2Uploader);

    // Cache system
    $cache_manager   = new ImgPress\Cache_Manager();
    $cache_create    = new ImgPress\Cache_Create($cache_manager);
    $cache_invalidation = new ImgPress\Cache_Invalidation($cache_manager);
    $cache_admin     = new ImgPress\Cache_Admin($cache_manager, $cache_invalidation);
    $cache_headers   = new ImgPress\Cache_Headers($cache_manager);
    $cache_gzip      = new ImgPress\Cache_Gzip($cache_manager);
    $cache_minify    = new ImgPress\Cache_Minify($cache_manager);
    $cache_js_optimize = new ImgPress\Cache_JS_Optimize($cache_manager);
    $cache_image_optimize = new ImgPress\Cache_Image_Optimize($cache_manager);
    $cache_preload = new ImgPress\Cache_Preload($cache_manager);
    $cache_db_cleanup = new ImgPress\Cache_DB_Cleanup($cache_manager);
    $cache_cdn = new ImgPress\Cache_CDN($cache_manager);

    new ImgPress\Auto_Compress($compressor, $r2Uploader, $settings);
    new ImgPress\Media_Columns($compressor, $settings, $r2Uploader);
    new ImgPress\Bulk_Compress($compressor, $settings);
    new ImgPress\R2_Bulk($r2Uploader, $settings);
    new ImgPress\R2_URL_Rewriter($settings);

    // Make $settings available globally for page templates
    $GLOBALS['imgpress_settings'] = $settings;
});
