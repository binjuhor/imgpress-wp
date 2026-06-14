<?php
/**
 * Plugin Name: ImgPress
 * Plugin URI:  https://github.com/binjuhor/imgpress
 * Description: Automatically compress images, PDFs, audio, and video via the imgpress API.
 * Version:     1.1.0
 * Author:      Hoang Kiem
 * License:     MIT
 * Text Domain: imgpress-wp
 */

defined('ABSPATH') || exit;

define('IMGPRESS_WP_VERSION', '1.1.0');
define('IMGPRESS_WP_DIR', plugin_dir_path(__FILE__));
define('IMGPRESS_WP_URL', plugin_dir_url(__FILE__));

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
        ]);
    }
});

add_action('plugins_loaded', function (): void {
    $settings      = new ImgPress\Settings();
    $apiClient     = new ImgPress\Api_Client($settings);
    $compressor    = new ImgPress\Compressor($apiClient, $settings);

    new ImgPress\Auto_Compress($apiClient, $compressor, $settings);
    new ImgPress\Media_Columns($compressor, $settings);
    new ImgPress\Bulk_Compress($compressor, $settings);
});
