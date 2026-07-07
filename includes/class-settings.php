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
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('wp_ajax_imgpress_test_connection', [$this, 'handleTestConnection']);
        add_action('wp_ajax_imgpress_test_r2', [$this, 'handleTestR2Connection']);
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

    public function enqueueAssets(string $hook): void
    {
        if ($hook !== 'settings_page_imgpress-settings') {
            return;
        }

        wp_enqueue_style(
            'imgpress-settings',
            IMGPRESS_WP_URL . 'assets/css/settings.css',
            [],
            IMGPRESS_WP_VERSION
        );

        wp_enqueue_script(
            'imgpress-settings-tabs',
            IMGPRESS_WP_URL . 'assets/js/settings-tabs.js',
            ['jquery'],
            IMGPRESS_WP_VERSION,
            true
        );

        wp_enqueue_script(
            'imgpress-settings-tests',
            IMGPRESS_WP_URL . 'assets/js/settings-tests.js',
            ['jquery'],
            IMGPRESS_WP_VERSION,
            true
        );

        wp_localize_script('imgpress-settings-tests', 'ImgPressAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('imgpress_compress_single'),
            'r2Nonce' => wp_create_nonce('imgpress_r2'),
            'i18n'    => [
                'testing'        => __('Testing…', 'imgpress-wp'),
                'connected'      => __('Connected', 'imgpress-wp'),
                'failed'         => __('Failed', 'imgpress-wp'),
                'requestFailed'  => __('Request failed', 'imgpress-wp'),
                'testConnection' => __('Test Connection', 'imgpress-wp'),
                'testR2'         => __('Test R2 Connection', 'imgpress-wp'),
            ],
            'nonce' => [
                'testConnection' => wp_create_nonce('imgpress_test_connection'),
                'testR2'         => wp_create_nonce('imgpress_test_r2'),
            ],
        ]);
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
            'r2_enabled'          => !empty($input['r2_enabled']),
            'r2_account_id'       => sanitize_text_field($input['r2_account_id'] ?? ''),
            'r2_access_key'       => sanitize_text_field($input['r2_access_key'] ?? ''),
            'r2_secret_key'       => $this->sanitizeSecret($input['r2_secret_key'] ?? ''),
            'r2_bucket'           => sanitize_text_field($input['r2_bucket'] ?? ''),
            'r2_custom_domain'    => $this->sanitizeHost($input['r2_custom_domain'] ?? ''),
            'r2_push_on_compress' => !empty($input['r2_enabled']),
            'r2_push_on_upload'   => !empty($input['r2_push_on_upload']),
            'r2_delete_local'     => !empty($input['r2_delete_local']),
            'r2_rewrite_content'  => !empty($input['r2_rewrite_content']),
        ];
    }

    private function sanitizeSecret(string $value): string
    {
        if ($value === '' || $value === '********') {
            return $this->options['r2_secret_key'] ?? '';
        }
        return sanitize_text_field($value);
    }

    private function sanitizeHost(string $value): string
    {
        $value = trim($value);
        if (empty($value)) {
            return '';
        }

        $value = sanitize_text_field($value);
        $candidate = preg_match('#^https?://#i', $value) ? $value : 'https://' . $value;
        $parsed = wp_parse_url($candidate, PHP_URL_HOST);
        if ($parsed) {
            return rtrim($parsed, '/');
        }

        return rtrim(preg_replace('#^https?://#i', '', $value), '/');
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

    public function handleTestR2Connection(): void
    {
        check_ajax_referer('imgpress_test_r2');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }

        if (isset($_POST[self::OPTION_KEY]) && is_array($_POST[self::OPTION_KEY])) {
            $current = (array) get_option(self::OPTION_KEY, []);
            $incoming = wp_unslash($_POST[self::OPTION_KEY]);
            $incoming = array_merge([
                'auto_compress' => '',
                'enabled_types' => [],
                'r2_enabled' => '',
                'r2_push_on_compress' => '',
                'r2_push_on_upload' => '',
                'r2_delete_local' => '',
                'r2_rewrite_content' => '',
            ], $incoming);
            $this->options = $this->sanitize(array_merge($current, $incoming));
            update_option(self::OPTION_KEY, $this->options);
        }

        if (!$this->isR2Configured()) {
            wp_send_json_error('R2 is not properly configured. Please fill in all required fields.');
        }

        $client = new R2_Client($this);
        $result = $client->headBucket();

        if ($result['ok']) {
            wp_send_json_success([
                'message' => 'Connected to R2 bucket',
                'publicDomain' => $this->getR2CustomDomain(),
            ]);
        } else {
            wp_send_json_error($result['error'] ?? 'Connection failed');
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

    public function isR2Enabled(): bool
    {
        return (bool) ($this->options['r2_enabled'] ?? false);
    }

    public function getR2AccountId(): string
    {
        return (string) ($this->options['r2_account_id'] ?? '');
    }

    public function getR2AccessKey(): string
    {
        return (string) ($this->options['r2_access_key'] ?? '');
    }

    public function getR2SecretKey(): string
    {
        return (string) ($this->options['r2_secret_key'] ?? '');
    }

    public function getR2Bucket(): string
    {
        return (string) ($this->options['r2_bucket'] ?? '');
    }

    public function getR2CustomDomain(): string
    {
        return (string) ($this->options['r2_custom_domain'] ?? '');
    }

    public function getR2PublicBaseUrl(): string
    {
        $domain = $this->getR2CustomDomain();
        if (empty($domain)) {
            return '';
        }

        return 'https://' . preg_replace('#^https?://#', '', rtrim($domain, '/'));
    }

    public function isR2PushOnCompress(): bool
    {
        return $this->isR2Configured();
    }

    public function isR2PushOnUpload(): bool
    {
        return (bool) ($this->options['r2_push_on_upload'] ?? false);
    }

    public function isR2DeleteLocal(): bool
    {
        return (bool) ($this->options['r2_delete_local'] ?? false);
    }

    public function isR2RewriteContent(): bool
    {
        return (bool) ($this->options['r2_rewrite_content'] ?? false);
    }

    public function getR2Endpoint(): string
    {
        $accountId = $this->getR2AccountId();
        if (empty($accountId)) {
            return '';
        }
        return "https://{$accountId}.r2.cloudflarestorage.com";
    }

    public function isR2Configured(): bool
    {
        return $this->isR2Enabled()
            && !empty($this->getR2AccountId())
            && !empty($this->getR2AccessKey())
            && !empty($this->getR2SecretKey())
            && !empty($this->getR2Bucket());
    }
}
