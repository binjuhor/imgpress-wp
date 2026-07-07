<?php
/**
 * Plugin Name: ImgPress
 * Plugin URI:  https://github.com/binjuhor/imgpress-wp
 * Description: Automatically compress images, PDFs, audio, and video via the imgpress API.
 * Version:     1.1.4
 * Author:      Binjuhor
 * Author URI: https://binjuhor.com
 * License:     MIT
 * Text Domain: imgpress-wp
 */

defined('ABSPATH') || exit;

defined('IMGPRESS_WP_VERSION') || define('IMGPRESS_WP_VERSION', '1.1.4');
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
        ]);
    }
});

add_action('plugins_loaded', function (): void {
    $settings      = new ImgPress\Settings();
    $apiClient     = new ImgPress\Api_Client($settings);
    $r2Client      = new ImgPress\R2_Client($settings);
    $r2Uploader    = new ImgPress\R2_Uploader($r2Client, $settings);
    $compressor    = new ImgPress\Compressor($apiClient, $settings, $r2Uploader);

    new ImgPress\Auto_Compress($compressor, $r2Uploader, $settings);
    new ImgPress\Media_Columns($compressor, $settings, $r2Uploader);
    new ImgPress\Bulk_Compress($compressor, $settings);
    new ImgPress\R2_Bulk($r2Uploader, $settings);
    new ImgPress\R2_URL_Rewriter($settings);

    // Make $settings available globally for page templates
    $GLOBALS['imgpress_settings'] = $settings;
});
