<?php

namespace ImgPress;

defined('ABSPATH') || exit;

class Settings
{
    private const OPTION_KEY = 'imgpress_wp_options';

    private array $options;

    public function __construct()
    {
        $this->options = (array) get_option(self::OPTION_KEY, []);

        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('wp_ajax_imgpress_test_connection', [$this, 'handleTestConnection']);
    }

    public function addMenuPage(): void
    {
        add_options_page(
            'ImgPress Settings',
            'ImgPress',
            'manage_options',
            'imgpress-settings',
            fn() => require IMGPRESS_WP_DIR . 'admin/page-settings.php'
        );
    }

    public function registerSettings(): void
    {
        register_setting('imgpress_wp', self::OPTION_KEY, [
            'sanitize_callback' => [$this, 'sanitize'],
        ]);
    }

    public function sanitize(array $input): array
    {
        return [
            'api_url'         => esc_url_raw(rtrim($input['api_url'] ?? '', '/')),
            'auto_compress'   => !empty($input['auto_compress']),
            'quality'         => max(1, min(100, (int) ($input['quality'] ?? 80))),
            'format'          => in_array($input['format'] ?? '', ['webp', 'avif', 'jpeg', 'auto'], true)
                                    ? $input['format']
                                    : 'webp',
            'max_width'       => max(100, (int) ($input['max_width'] ?? 1600)),
            'enabled_types'   => array_intersect(
                (array) ($input['enabled_types'] ?? []),
                ['image', 'pdf', 'audio', 'video']
            ),
            'request_timeout' => max(10, min(600, (int) ($input['request_timeout'] ?? 120))),
        ];
    }

    public function handleTestConnection(): void
    {
        check_ajax_referer('imgpress_test_connection');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }

        $url      = $this->getApiUrl() . '/api/compress';
        $response = wp_remote_get($this->getApiUrl(), ['timeout' => 5]);

        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 500) {
            wp_send_json_success('Connected');
        } else {
            wp_send_json_error("HTTP {$code}");
        }
    }

    public function getApiUrl(): string
    {
        return $this->options['api_url'] ?? 'http://localhost:3000';
    }

    public function isAutoCompress(): bool
    {
        return (bool) ($this->options['auto_compress'] ?? true);
    }

    public function getQuality(): int
    {
        return (int) ($this->options['quality'] ?? 80);
    }

    public function getFormat(): string
    {
        return $this->options['format'] ?? 'webp';
    }

    public function getMaxWidth(): int
    {
        return (int) ($this->options['max_width'] ?? 1600);
    }

    public function getEnabledTypes(): array
    {
        return (array) ($this->options['enabled_types'] ?? ['image', 'pdf', 'audio', 'video']);
    }

    public function getRequestTimeout(): int
    {
        return (int) ($this->options['request_timeout'] ?? 120);
    }

    public function isTypeEnabled(string $mime): bool
    {
        $enabled = $this->getEnabledTypes();

        if (str_starts_with($mime, 'image/') && in_array('image', $enabled, true)) {
            return true;
        }
        if (str_starts_with($mime, 'audio/') && in_array('audio', $enabled, true)) {
            return true;
        }
        if (str_starts_with($mime, 'video/') && in_array('video', $enabled, true)) {
            return true;
        }
        if ($mime === 'application/pdf' && in_array('pdf', $enabled, true)) {
            return true;
        }

        return false;
    }

    public function getAll(): array
    {
        return $this->options;
    }
}
