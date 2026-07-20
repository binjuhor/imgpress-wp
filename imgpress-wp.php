<?php
/**
 * Plugin Name: ImgPress
 * Plugin URI:  https://github.com/binjuhor/imgpress-wp
 * Description: Optimize media, offload files to R2, and speed up WordPress with opt-in caching tools.
 * Version:     1.2.2
 * Author:      Binjuhor
 * Author URI: https://binjuhor.com
 * License:     MIT
 * Text Domain: imgpress-wp
 */

defined('ABSPATH') || exit;

defined('IMGPRESS_WP_VERSION') || define('IMGPRESS_WP_VERSION', '1.2.0');
defined('IMGPRESS_WP_DIR')     || define('IMGPRESS_WP_DIR', plugin_dir_path(__FILE__));
defined('IMGPRESS_WP_URL')     || define('IMGPRESS_WP_URL', plugin_dir_url(__FILE__));

$composerAutoload = IMGPRESS_WP_DIR . 'vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
}

spl_autoload_register(function (string $class): void {
    if (class_exists($class, false)) {
        return;
    }

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
        add_option('imgpress_wp_options', ImgPress\Config::defaults());
    } else {
        ImgPress\Config::migrate();
    }
});

add_action('plugins_loaded', function (): void {
    ImgPress\Config::migrate();

    $settings      = new ImgPress\Settings();
    $logger        = new ImgPress\Logger();
    $jobs          = new ImgPress\Jobs($logger);
    $compatibility = new ImgPress\Cache_Compatibility($settings);
    $apiClient     = new ImgPress\Api_Client($settings);
    $r2Client      = new ImgPress\R2_Client($settings);
    $r2Uploader    = new ImgPress\R2_Uploader($r2Client, $settings);
    $compressor    = new ImgPress\Compressor($apiClient, $settings, $r2Uploader);

    $pageCache = new ImgPress\Page_Cache($settings, $logger);
    $assetOptimizer = new ImgPress\Asset_Optimizer($settings, $logger);
    $htmlOptimizer = new ImgPress\Html_Optimizer($settings, $logger);
    $databaseOptimizer = new ImgPress\Database_Optimizer($settings, $logger);
    $preload = new ImgPress\Preload($settings, $logger, $jobs, $pageCache);
    $bloat = new ImgPress\Bloat($settings);

    new ImgPress\Auto_Compress($compressor, $r2Uploader, $settings);
    new ImgPress\Media_Columns($compressor, $settings, $r2Uploader);
    new ImgPress\Bulk_Compress($compressor, $settings);
    new ImgPress\R2_Bulk($r2Uploader, $settings);
    new ImgPress\R2_URL_Rewriter($settings);
    new ImgPress\Dashboard($settings, $logger, $pageCache, $compatibility, $preload, $assetOptimizer);

    $jobs->init();
    $compatibility->init();
    $pageCache->init();
    $assetOptimizer->init();
    $htmlOptimizer->init();
    $databaseOptimizer->init();
    $preload->init();
    $bloat->init();

    // Make $settings available globally for page templates
    $GLOBALS['imgpress_settings'] = $settings;
});
