<?php

namespace ImgPress;

defined('ABSPATH') || exit;

class Cache_Compatibility
{
    private array $knownPlugins = [
        'wp-rocket/wp-rocket.php' => 'WP Rocket',
        'flying-press/flying-press.php' => 'FlyingPress',
        'litespeed-cache/litespeed-cache.php' => 'LiteSpeed Cache',
        'wp-fastest-cache/wpFastestCache.php' => 'WP Fastest Cache',
        'w3-total-cache/w3-total-cache.php' => 'W3 Total Cache',
        'wp-super-cache/wp-cache.php' => 'WP Super Cache',
    ];

    public function __construct(private Settings $settings)
    {
    }

    public function init(): void
    {
        add_action('admin_notices', [$this, 'adminNotice']);
    }

    public function activeCachePlugins(): array
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $active = [];
        foreach ($this->knownPlugins as $pluginFile => $name) {
            if (is_plugin_active($pluginFile)) {
                $active[$pluginFile] = $name;
            }
        }

        return $active;
    }

    public function hasConflict(): bool
    {
        return !empty($this->activeCachePlugins());
    }

    public function adminNotice(): void
    {
        if (!current_user_can('manage_options') || !$this->settings->isCacheEnabled() || !$this->hasConflict()) {
            return;
        }

        $names = implode(', ', array_values($this->activeCachePlugins()));
        printf(
            '<div class="notice notice-warning"><p>%s</p></div>',
            esc_html(sprintf(
                __('ImgPress page cache is enabled while another cache plugin is active: %s. Use only one page cache to avoid stale pages or duplicate cache files.', 'imgpress-wp'),
                $names
            ))
        );
    }
}
