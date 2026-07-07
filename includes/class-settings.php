<?php

namespace ImgPress;

defined('ABSPATH') || exit;

class Settings
{
    private const OPTION_KEY = Config::OPTION_KEY;

    private array $options;

    public function __construct()
    {
        $this->options = (array) get_option(self::OPTION_KEY, []);

        add_action('admin_menu', [$this, 'addMenuPage'], 20);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('wp_ajax_imgpress_test_connection', [$this, 'handleTestConnection']);
        add_action('wp_ajax_imgpress_test_r2', [$this, 'handleTestR2Connection']);
        add_action('update_option_' . self::OPTION_KEY, [$this, 'handleSettingsUpdated'], 10, 2);
    }

    public function addMenuPage(): void
    {
        add_submenu_page(
            Dashboard::menuSlug(),
            __('ImgPress Settings', 'imgpress-wp'),
            __('Settings', 'imgpress-wp'),
            'manage_options',
            'imgpress-settings',
            fn() => require IMGPRESS_WP_DIR . 'admin/page-settings.php'
        );
    }

    public function enqueueAssets(string $hook): void
    {
        if (!in_array($hook, ['imgpress_page_imgpress-settings', 'toplevel_page_imgpress', 'imgpress_page_imgpress', 'imgpress_page_imgpress-bulk', 'imgpress_page_imgpress-r2-bulk'], true)) {
            return;
        }

        wp_enqueue_style(
            'imgpress-settings',
            IMGPRESS_WP_URL . 'assets/css/settings.css',
            [],
            IMGPRESS_WP_VERSION
        );

        if ($hook !== 'imgpress_page_imgpress-settings') {
            return;
        }

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
            'config_version'   => IMGPRESS_WP_VERSION,
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
            'r2_push_on_compress' => !empty($input['r2_push_on_compress']),
            'r2_push_on_upload'   => !empty($input['r2_push_on_upload']),
            'r2_delete_local'     => !empty($input['r2_delete_local']),
            'r2_rewrite_content'  => !empty($input['r2_rewrite_content']),
            'cache_enabled'            => !empty($input['cache_enabled']),
            'cache_lifespan'           => max(MINUTE_IN_SECONDS, min(MONTH_IN_SECONDS, (int) ($input['cache_lifespan'] ?? DAY_IN_SECONDS))),
            'cache_advanced_dropin'    => !empty($input['cache_advanced_dropin']),
            'cache_preload'            => !empty($input['cache_preload']),
            'cache_logged_in'          => !empty($input['cache_logged_in']),
            'cache_mobile_separate'    => !empty($input['cache_mobile_separate']),
            'cache_excluded_urls'      => $this->sanitizeTextarea($input['cache_excluded_urls'] ?? ''),
            'cache_excluded_cookies'   => $this->sanitizeTextarea($input['cache_excluded_cookies'] ?? ''),
            'cache_ignored_query_args' => $this->sanitizeTextarea($input['cache_ignored_query_args'] ?? ''),
            'optimize_css_minify'      => !empty($input['optimize_css_minify']),
            'optimize_css_rucss'       => !empty($input['optimize_css_rucss']),
            'optimize_css_rucss_method' => in_array($input['optimize_css_rucss_method'] ?? 'async', ['async', 'remove', 'interaction'], true)
                ? $input['optimize_css_rucss_method']
                : 'async',
            'optimize_css_rucss_exclude_stylesheets' => $this->sanitizeTextarea($input['optimize_css_rucss_exclude_stylesheets'] ?? ''),
            'optimize_css_rucss_include_selectors'   => $this->sanitizeTextarea($input['optimize_css_rucss_include_selectors'] ?? ''),
            'optimize_js_minify'       => !empty($input['optimize_js_minify']),
            'optimize_js_defer'        => !empty($input['optimize_js_defer']),
            'optimize_js_defer_excludes' => $this->sanitizeTextarea($input['optimize_js_defer_excludes'] ?? ''),
            'optimize_js_delay'        => !empty($input['optimize_js_delay']),
            'optimize_js_delay_method'  => in_array($input['optimize_js_delay_method'] ?? 'selected', ['selected', 'all'], true)
                ? $input['optimize_js_delay_method']
                : 'selected',
            'optimize_js_delay_all_excludes' => $this->sanitizeTextarea($input['optimize_js_delay_all_excludes'] ?? ''),
            'optimize_js_delay_selected' => $this->sanitizeTextarea($input['optimize_js_delay_selected'] ?? ''),
            'optimize_html_minify'     => !empty($input['optimize_html_minify']),
            'optimize_excluded_assets' => $this->sanitizeTextarea($input['optimize_excluded_assets'] ?? ''),
            'bloat_disable_jquery_migrate' => !empty($input['bloat_disable_jquery_migrate']),
            'bloat_disable_emojis' => !empty($input['bloat_disable_emojis']),
            'bloat_disable_block_css' => !empty($input['bloat_disable_block_css']),
            'bloat_disable_oembeds' => !empty($input['bloat_disable_oembeds']),
            'bloat_disable_dashicons' => !empty($input['bloat_disable_dashicons']),
            'bloat_disable_xml_rpc' => !empty($input['bloat_disable_xml_rpc']),
            'bloat_disable_rss_feed' => !empty($input['bloat_disable_rss_feed']),
        ];
    }

    public function handleSettingsUpdated(array $oldValue, array $newValue): void
    {
        $this->options = array_merge(Config::defaults(), $newValue);

        $shouldInstallDropin = !empty($newValue['cache_enabled']) && !empty($newValue['cache_advanced_dropin']);
        $success = $shouldInstallDropin ? Cache_Dropin::install() : Cache_Dropin::remove();

        if (!$success) {
            add_settings_error(
                self::OPTION_KEY,
                'imgpress_cache_dropin',
                __('ImgPress could not update advanced-cache.php. Another plugin may own the file or wp-content may not be writable.', 'imgpress-wp'),
                'warning'
            );
        }
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

    private function sanitizeTextarea(string $value): string
    {
        $lines = preg_split('/\r\n|\r|\n/', wp_unslash($value));
        $lines = array_map(static fn($line) => sanitize_text_field(trim((string) $line)), $lines ?: []);
        $lines = array_filter($lines, static fn($line) => $line !== '');

        return implode("\n", $lines);
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
        return $this->isR2Configured() && (bool) ($this->options['r2_push_on_compress'] ?? false);
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

    public function isCacheEnabled(): bool
    {
        return (bool) ($this->options['cache_enabled'] ?? false);
    }

    public function getCacheLifespan(): int
    {
        return max(MINUTE_IN_SECONDS, (int) ($this->options['cache_lifespan'] ?? DAY_IN_SECONDS));
    }

    public function isCacheAdvancedDropinEnabled(): bool
    {
        return (bool) ($this->options['cache_advanced_dropin'] ?? false);
    }

    public function isCachePreloadEnabled(): bool
    {
        return (bool) ($this->options['cache_preload'] ?? false);
    }

    public function isCacheLoggedInEnabled(): bool
    {
        return (bool) ($this->options['cache_logged_in'] ?? false);
    }

    public function isCacheMobileSeparateEnabled(): bool
    {
        return (bool) ($this->options['cache_mobile_separate'] ?? false);
    }

    public function getCacheExcludedUrls(): array
    {
        return $this->linesFromOption('cache_excluded_urls');
    }

    public function getCacheExcludedCookies(): array
    {
        return $this->linesFromOption('cache_excluded_cookies');
    }

    public function getCacheIgnoredQueryArgs(): array
    {
        return $this->linesFromOption('cache_ignored_query_args');
    }

    public function isCssMinifyEnabled(): bool
    {
        return (bool) ($this->options['optimize_css_minify'] ?? false);
    }

    public function isRemoveUnusedCssEnabled(): bool
    {
        return (bool) ($this->options['optimize_css_rucss'] ?? false);
    }

    public function getRemoveUnusedCssMethod(): string
    {
        return (string) ($this->options['optimize_css_rucss_method'] ?? 'async');
    }

    public function getRemoveUnusedCssExcludeStylesheets(): array
    {
        return $this->linesFromOption('optimize_css_rucss_exclude_stylesheets');
    }

    public function getRemoveUnusedCssIncludeSelectors(): array
    {
        return $this->linesFromOption('optimize_css_rucss_include_selectors');
    }

    public function isJsMinifyEnabled(): bool
    {
        return (bool) ($this->options['optimize_js_minify'] ?? false);
    }

    public function isJsDeferEnabled(): bool
    {
        return (bool) ($this->options['optimize_js_defer'] ?? false);
    }

    public function getJsDeferExcludes(): array
    {
        return $this->linesFromOption('optimize_js_defer_excludes');
    }

    public function isJsDelayEnabled(): bool
    {
        return (bool) ($this->options['optimize_js_delay'] ?? false);
    }

    public function getJsDelayMethod(): string
    {
        return (string) ($this->options['optimize_js_delay_method'] ?? 'selected');
    }

    public function getJsDelayAllExcludes(): array
    {
        return $this->linesFromOption('optimize_js_delay_all_excludes');
    }

    public function getJsDelaySelected(): array
    {
        return $this->linesFromOption('optimize_js_delay_selected');
    }

    public function isHtmlMinifyEnabled(): bool
    {
        return (bool) ($this->options['optimize_html_minify'] ?? false);
    }

    public function getOptimizeExcludedAssets(): array
    {
        return $this->linesFromOption('optimize_excluded_assets');
    }

    public function isBloatDisabled(string $feature): bool
    {
        return (bool) ($this->options['bloat_disable_' . $feature] ?? false);
    }

    private function linesFromOption(string $key): array
    {
        $value = (string) ($this->options[$key] ?? Config::defaults()[$key] ?? '');
        $lines = preg_split('/\r\n|\r|\n/', $value);

        return array_values(array_filter(array_map('trim', $lines ?: []), static fn($line) => $line !== ''));
    }
}
