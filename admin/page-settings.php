<?php defined('ABSPATH') || exit; ?>
<div class="wrap imgpress-settings-wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

    <?php settings_errors('imgpress_wp_options'); ?>

    <?php
    $opts   = get_option('imgpress_wp_options', []);
    $apiUrl = $opts['api_url']        ?? 'http://localhost:3000';
    $auto   = !empty($opts['auto_compress']);
    $quality    = (int) ($opts['quality'] ?? 80);
    $format     = $opts['format']         ?? 'webp';
    $width      = (int) ($opts['max_width'] ?? 1600);
    $types      = (array) ($opts['enabled_types'] ?? ['image', 'pdf', 'audio', 'video']);
    $timeout    = (int) ($opts['request_timeout'] ?? 120);
    $cacheEnabled = !empty($opts['cache_enabled']);
    $cacheLifespan = (int) ($opts['cache_lifespan'] ?? DAY_IN_SECONDS);
    $cacheExcludedUrls = (string) ($opts['cache_excluded_urls'] ?? "/wp-admin/*\n/wp-login.php*");
    $cacheExcludedCookies = (string) ($opts['cache_excluded_cookies'] ?? "wordpress_logged_in_\nwp-postpass_\nwoocommerce_items_in_cart");
    $cacheIgnoredQueryArgs = (string) ($opts['cache_ignored_query_args'] ?? "utm_source\nutm_medium\nutm_campaign\nutm_content\nutm_term\nfbclid\ngclid");
    $optimizeExcludedAssets = (string) ($opts['optimize_excluded_assets'] ?? '');
    ?>

    <!-- Tab Navigation -->
    <div class="imgpress-tabs-nav" role="tablist">
        <button class="imgpress-tab-button active" data-tab="compression" role="tab" aria-controls="compression" aria-selected="true">
            <span class="dashicons dashicons-image-filter"></span>
            <span><?php esc_html_e('Compression', 'imgpress-wp'); ?></span>
        </button>
        <button class="imgpress-tab-button" data-tab="r2" role="tab" aria-controls="r2" aria-selected="false">
            <span class="dashicons dashicons-cloud"></span>
            <span><?php esc_html_e('R2 Storage', 'imgpress-wp'); ?></span>
        </button>
        <button class="imgpress-tab-button" data-tab="files" role="tab" aria-controls="files" aria-selected="false">
            <span class="dashicons dashicons-media-document"></span>
            <span><?php esc_html_e('File Types', 'imgpress-wp'); ?></span>
        </button>
        <button class="imgpress-tab-button" data-tab="cache" role="tab" aria-controls="cache" aria-selected="false">
            <span class="dashicons dashicons-performance"></span>
            <span><?php esc_html_e('Cache', 'imgpress-wp'); ?></span>
        </button>
        <button class="imgpress-tab-button" data-tab="assets" role="tab" aria-controls="assets" aria-selected="false">
            <span class="dashicons dashicons-editor-code"></span>
            <span><?php esc_html_e('CSS / JS', 'imgpress-wp'); ?></span>
        </button>
    </div>

    <form method="post" action="options.php" class="imgpress-form">
        <?php settings_fields('imgpress_wp'); ?>

        <!-- Tab 1: Compression Settings -->
        <div class="imgpress-tab-content active" id="compression" role="tabpanel">
            <div class="imgpress-card">
                <h2 class="imgpress-card-title">
                    <span class="dashicons dashicons-admin-network"></span>
                    <?php esc_html_e('ImgPress API', 'imgpress-wp'); ?>
                </h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="ip_api_url"><?php esc_html_e('API URL', 'imgpress-wp'); ?></label>
                        </th>
                        <td>
                            <input
                                type="url"
                                id="ip_api_url"
                                name="imgpress_wp_options[api_url]"
                                value="<?php echo esc_attr($apiUrl); ?>"
                                class="regular-text"
                                placeholder="http://localhost:3000"
                            />
                            <button type="button" class="button" id="ip-test-conn" style="margin-left:6px">
                                <?php esc_html_e('Test Connection', 'imgpress-wp'); ?>
                            </button>
                            <span id="ip-conn-result" style="margin-left:8px;font-size:13px"></span>
                            <p class="description">
                                <?php esc_html_e('Base URL of ImgPress API (no trailing slash).', 'imgpress-wp'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ip_timeout"><?php esc_html_e('Request Timeout', 'imgpress-wp'); ?></label>
                        </th>
                        <td>
                            <input
                                type="number"
                                id="ip_timeout"
                                name="imgpress_wp_options[request_timeout]"
                                value="<?php echo esc_attr($timeout); ?>"
                                class="small-text"
                                min="10"
                                max="600"
                            />
                            <span class="description" style="display:inline;margin-left:4px">
                                <?php esc_html_e('seconds', 'imgpress-wp'); ?>
                            </span>
                            <strong id="ip-cache-lifespan-human" style="display:inline-block;margin-left:8px"></strong>
                            <p class="description">
                                <?php esc_html_e('Example: 3600 = 1 hour, 86400 = 1 day, 604800 = 7 days.', 'imgpress-wp'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="imgpress-card">
                <h2 class="imgpress-card-title">
                    <span class="dashicons dashicons-image-filter"></span>
                    <?php esc_html_e('Compression Settings', 'imgpress-wp'); ?>
                </h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Auto-compress', 'imgpress-wp'); ?></th>
                        <td>
                            <label for="ip_auto_compress">
                                <input
                                    type="checkbox"
                                    id="ip_auto_compress"
                                    name="imgpress_wp_options[auto_compress]"
                                    value="1"
                                    <?php checked($auto); ?>
                                />
                                <?php esc_html_e('Compress files automatically on upload', 'imgpress-wp'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('Files will be compressed before being added to the media library.', 'imgpress-wp'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ip_quality"><?php esc_html_e('Quality', 'imgpress-wp'); ?></label>
                        </th>
                        <td>
                            <div style="display:flex;align-items:center;gap:12px">
                                <input
                                    type="range"
                                    id="ip_quality"
                                    name="imgpress_wp_options[quality]"
                                    value="<?php echo esc_attr($quality); ?>"
                                    min="1"
                                    max="100"
                                    style="width:200px"
                                />
                                <strong id="ip-quality-val" style="min-width:40px"><?php echo esc_html($quality); ?></strong>
                            </div>
                            <p class="description">
                                <?php esc_html_e('1 (smallest) — 100 (best quality). Default: 80', 'imgpress-wp'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ip_format"><?php esc_html_e('Output Format', 'imgpress-wp'); ?></label>
                        </th>
                        <td>
                            <select id="ip_format" name="imgpress_wp_options[format]" class="imgpress-select">
                                <?php foreach (['webp' => 'WebP (modern)', 'avif' => 'AVIF (smallest)', 'jpeg' => 'JPEG (compatible)', 'auto' => 'Auto (keep original)'] as $val => $label): ?>
                                    <option value="<?php echo esc_attr($val); ?>" <?php selected($format, $val); ?>>
                                        <?php echo esc_html($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                <?php esc_html_e('WebP offers the best balance of compression and compatibility.', 'imgpress-wp'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ip_width"><?php esc_html_e('Max Width', 'imgpress-wp'); ?></label>
                        </th>
                        <td>
                            <input
                                type="number"
                                id="ip_width"
                                name="imgpress_wp_options[max_width]"
                                value="<?php echo esc_attr($width); ?>"
                                class="small-text"
                                min="100"
                                max="20000"
                            />
                            <span class="description" style="display:inline;margin-left:4px">
                                <?php esc_html_e('px (images only)', 'imgpress-wp'); ?>
                            </span>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Tab 2: R2 Storage -->
        <div class="imgpress-tab-content" id="r2" role="tabpanel" hidden>
            <div class="imgpress-card">
                <h2 class="imgpress-card-title">
                    <span class="dashicons dashicons-cloud"></span>
                    <?php esc_html_e('R2 Configuration', 'imgpress-wp'); ?>
                </h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Enable R2', 'imgpress-wp'); ?></th>
                        <td>
                            <label for="r2_enabled" class="imgpress-toggle">
                                <input
                                    type="checkbox"
                                    id="r2_enabled"
                                    name="imgpress_wp_options[r2_enabled]"
                                    value="1"
                                    <?php checked(!empty($opts['r2_enabled'])); ?>
                                />
                                <span></span>
                            </label>
                            <p class="description">
                                <?php esc_html_e('Enable Cloudflare R2 media storage.', 'imgpress-wp'); ?>
                                <a href="<?php echo esc_url(admin_url('upload.php?page=imgpress-r2-bulk')); ?>" target="_blank">
                                    <?php esc_html_e('View R2 Setup Guide →', 'imgpress-wp'); ?>
                                </a>
                            </p>
                        </td>
                    </tr>
                </table>

                <h3 class="imgpress-section-title"><?php esc_html_e('Credentials', 'imgpress-wp'); ?></h3>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="r2_account_id"><?php esc_html_e('Account ID', 'imgpress-wp'); ?></label>
                        </th>
                        <td>
                            <input
                                type="text"
                                id="r2_account_id"
                                name="imgpress_wp_options[r2_account_id]"
                                value="<?php echo esc_attr($opts['r2_account_id'] ?? ''); ?>"
                                class="regular-text"
                                placeholder="abc123def456..."
                            />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="r2_access_key"><?php esc_html_e('Access Key ID', 'imgpress-wp'); ?></label>
                        </th>
                        <td>
                            <input
                                type="text"
                                id="r2_access_key"
                                name="imgpress_wp_options[r2_access_key]"
                                value="<?php echo esc_attr($opts['r2_access_key'] ?? ''); ?>"
                                class="regular-text"
                            />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="r2_secret_key"><?php esc_html_e('Secret Access Key', 'imgpress-wp'); ?></label>
                        </th>
                        <td>
                            <input
                                type="password"
                                id="r2_secret_key"
                                name="imgpress_wp_options[r2_secret_key]"
                                value="<?php echo !empty($opts['r2_secret_key']) ? '********' : ''; ?>"
                                class="regular-text"
                                placeholder="<?php echo !empty($opts['r2_secret_key']) ? '(stored)' : ''; ?>"
                            />
                            <p class="description">
                                <?php esc_html_e('Leave blank to keep current value.', 'imgpress-wp'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="r2_bucket"><?php esc_html_e('Bucket Name', 'imgpress-wp'); ?></label>
                        </th>
                        <td>
                            <input
                                type="text"
                                id="r2_bucket"
                                name="imgpress_wp_options[r2_bucket]"
                                value="<?php echo esc_attr($opts['r2_bucket'] ?? ''); ?>"
                                class="regular-text"
                                placeholder="my-media-bucket"
                            />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="r2_custom_domain"><?php esc_html_e('Public URL / Custom Domain', 'imgpress-wp'); ?></label>
                        </th>
                        <td>
                            <input
                                type="text"
                                id="r2_custom_domain"
                                name="imgpress_wp_options[r2_custom_domain]"
                                value="<?php echo esc_attr($opts['r2_custom_domain'] ?? ''); ?>"
                                class="regular-text"
                                placeholder="pub-xxxxxxxx.r2.dev or media.example.com"
                            />
                            <p class="description">
                                <?php esc_html_e('Required for clickable R2 links and URL rewriting. Use your bucket public r2.dev domain or a Cloudflare custom domain. Host only, no scheme.', 'imgpress-wp'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"></th>
                        <td>
                            <button type="button" class="button button-primary" id="r2-test-conn">
                                <span class="dashicons dashicons-yes-alt"></span>
                                <?php esc_html_e('Test R2 Connection', 'imgpress-wp'); ?>
                            </button>
                            <span id="r2-conn-result" style="margin-left:8px;font-size:13px"></span>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="imgpress-card">
                <h2 class="imgpress-card-title">
                    <span class="dashicons dashicons-upload"></span>
                    <?php esc_html_e('Auto-Push Settings', 'imgpress-wp'); ?>
                </h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <td style="padding:12px 0">
                            <label class="imgpress-checkbox">
                                <input
                                    type="checkbox"
                                    name="imgpress_wp_options[r2_push_on_compress]"
                                    value="1"
                                    <?php checked(!empty($opts['r2_push_on_compress'])); ?>
                                />
                                <span class="checkbox-label">
                                    <strong><?php esc_html_e('Push converted files to R2', 'imgpress-wp'); ?></strong>
                                    <span class="description">Auto-upload after ImgPress successfully converts/compresses the file.</span>
                                </span>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:12px 0;border-top:1px solid #ddd">
                            <label class="imgpress-checkbox">
                                <input
                                    type="checkbox"
                                    name="imgpress_wp_options[r2_push_on_upload]"
                                    value="1"
                                    <?php checked(!empty($opts['r2_push_on_upload'])); ?>
                                />
                                <span class="checkbox-label">
                                    <strong><?php esc_html_e('Push all files on upload', 'imgpress-wp'); ?></strong>
                                    <span class="description">Auto-upload any file (compressed or not)</span>
                                </span>
                            </label>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="imgpress-card">
                <h2 class="imgpress-card-title" style="color:#d63638">
                    <span class="dashicons dashicons-warning"></span>
                    <?php esc_html_e('Dangerous Settings', 'imgpress-wp'); ?>
                </h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <td style="padding:12px 0">
                            <label class="imgpress-checkbox imgpress-danger">
                                <input
                                    type="checkbox"
                                    name="imgpress_wp_options[r2_delete_local]"
                                    value="1"
                                    <?php checked(!empty($opts['r2_delete_local'])); ?>
                                />
                                <span class="checkbox-label">
                                    <strong><?php esc_html_e('Delete local files after uploading to R2', 'imgpress-wp'); ?></strong>
                                    <span class="description" style="color:#d63638">
                                        ⚠️ <?php esc_html_e('Files will be permanently deleted from your server. Enable only if you have backups.', 'imgpress-wp'); ?>
                                    </span>
                                </span>
                            </label>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="imgpress-card">
                <h2 class="imgpress-card-title">
                    <span class="dashicons dashicons-admin-links"></span>
                    <?php esc_html_e('URL Rewriting', 'imgpress-wp'); ?>
                </h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <td style="padding:12px 0">
                            <label class="imgpress-checkbox">
                                <input
                                    type="checkbox"
                                    name="imgpress_wp_options[r2_rewrite_content]"
                                    value="1"
                                    <?php checked(!empty($opts['r2_rewrite_content'])); ?>
                                />
                                <span class="checkbox-label">
                                    <strong><?php esc_html_e('Rewrite URLs in post content', 'imgpress-wp'); ?></strong>
                                    <span class="description">Auto-replace hardcoded image URLs with R2 URLs (slight perf cost)</span>
                                </span>
                            </label>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Tab 3: File Types -->
        <div class="imgpress-tab-content" id="files" role="tabpanel" hidden>
            <div class="imgpress-card">
                <h2 class="imgpress-card-title">
                    <span class="dashicons dashicons-media-document"></span>
                    <?php esc_html_e('Compression Support', 'imgpress-wp'); ?>
                </h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <td style="padding:12px 0">
                            <?php
                            $typeLabels = [
                                'image' => ['label' => __('Images', 'imgpress-wp'), 'sub' => 'JPEG · PNG · WebP · HEIC · AVIF · GIF'],
                                'pdf'   => ['label' => __('PDFs', 'imgpress-wp'),   'sub' => 'Compress via Ghostscript'],
                                'audio' => ['label' => __('Audio', 'imgpress-wp'),  'sub' => 'MP3 · WAV · FLAC → M4A'],
                                'video' => ['label' => __('Video', 'imgpress-wp'),  'sub' => 'MP4 · MOV · AVI → MP4'],
                            ];
                            foreach ($typeLabels as $val => $info): ?>
                            <label class="imgpress-checkbox" style="margin-bottom:16px">
                                <input
                                    type="checkbox"
                                    name="imgpress_wp_options[enabled_types][]"
                                    value="<?php echo esc_attr($val); ?>"
                                    <?php checked(in_array($val, $types, true)); ?>
                                />
                                <span class="checkbox-label">
                                    <strong><?php echo esc_html($info['label']); ?></strong>
                                    <span class="description"><?php echo esc_html($info['sub']); ?></span>
                                </span>
                            </label>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Tab 4: Cache -->
        <div class="imgpress-tab-content" id="cache" role="tabpanel" hidden>
            <div class="imgpress-card">
                <h2 class="imgpress-card-title">
                    <span class="dashicons dashicons-performance"></span>
                    <?php esc_html_e('Page Cache', 'imgpress-wp'); ?>
                </h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Enable Cache', 'imgpress-wp'); ?></th>
                        <td>
                            <label for="ip_cache_enabled" class="imgpress-toggle">
                                <input
                                    type="checkbox"
                                    id="ip_cache_enabled"
                                    name="imgpress_wp_options[cache_enabled]"
                                    value="1"
                                    <?php checked($cacheEnabled); ?>
                                />
                                <span></span>
                            </label>
                            <p class="description">
                                <?php esc_html_e('Opt-in page caching. Keep this off when another page cache plugin owns caching for the site.', 'imgpress-wp'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ip_cache_lifespan"><?php esc_html_e('Cache Lifespan', 'imgpress-wp'); ?></label>
                        </th>
                        <td>
                            <input
                                type="number"
                                id="ip_cache_lifespan"
                                name="imgpress_wp_options[cache_lifespan]"
                                value="<?php echo esc_attr($cacheLifespan); ?>"
                                class="regular-text"
                                min="<?php echo esc_attr(MINUTE_IN_SECONDS); ?>"
                                max="<?php echo esc_attr(MONTH_IN_SECONDS); ?>"
                            />
                            <span class="description" style="display:inline;margin-left:4px">
                                <?php esc_html_e('seconds', 'imgpress-wp'); ?>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('advanced-cache.php', 'imgpress-wp'); ?></th>
                        <td>
                            <label class="imgpress-checkbox">
                                <input
                                    type="checkbox"
                                    name="imgpress_wp_options[cache_advanced_dropin]"
                                    value="1"
                                    <?php checked(!empty($opts['cache_advanced_dropin'])); ?>
                                />
                                <span class="checkbox-label">
                                    <strong><?php esc_html_e('Install ImgPress advanced cache drop-in', 'imgpress-wp'); ?></strong>
                                    <span class="description"><?php esc_html_e('Only ImgPress-owned drop-ins are overwritten or removed.', 'imgpress-wp'); ?></span>
                                </span>
                            </label>
                            <p class="description">
                                <?php echo esc_html(sprintf(__('Status: %s', 'imgpress-wp'), ImgPress\Cache_Dropin::isInstalled() ? __('installed', 'imgpress-wp') : __('not installed', 'imgpress-wp'))); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Background Tasks', 'imgpress-wp'); ?></th>
                        <td>
                            <label class="imgpress-checkbox">
                                <input
                                    type="checkbox"
                                    name="imgpress_wp_options[cache_preload]"
                                    value="1"
                                    <?php checked(!empty($opts['cache_preload'])); ?>
                                />
                                <span class="checkbox-label">
                                    <strong><?php esc_html_e('Preload cache after purge', 'imgpress-wp'); ?></strong>
                                    <span class="description"><?php esc_html_e('Uses Action Scheduler when available and falls back to WP-Cron.', 'imgpress-wp'); ?></span>
                                </span>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Logged-in Users', 'imgpress-wp'); ?></th>
                        <td>
                            <label class="imgpress-checkbox">
                                <input
                                    type="checkbox"
                                    name="imgpress_wp_options[cache_logged_in]"
                                    value="1"
                                    <?php checked(!empty($opts['cache_logged_in'])); ?>
                                />
                                <span class="checkbox-label">
                                    <strong><?php esc_html_e('Cache logged-in requests', 'imgpress-wp'); ?></strong>
                                    <span class="description"><?php esc_html_e('Leave disabled for membership, account, cart, and dashboard-heavy sites.', 'imgpress-wp'); ?></span>
                                </span>
                            </label>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="imgpress-card">
                <h2 class="imgpress-card-title">
                    <span class="dashicons dashicons-filter"></span>
                    <?php esc_html_e('Cache Rules', 'imgpress-wp'); ?>
                </h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="ip_cache_excluded_urls"><?php esc_html_e('Excluded URLs', 'imgpress-wp'); ?></label>
                        </th>
                        <td>
                            <textarea
                                id="ip_cache_excluded_urls"
                                name="imgpress_wp_options[cache_excluded_urls]"
                                rows="5"
                                class="large-text code"
                            ><?php echo esc_textarea($cacheExcludedUrls); ?></textarea>
                            <p class="description"><?php esc_html_e('One path or wildcard pattern per line.', 'imgpress-wp'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ip_cache_excluded_cookies"><?php esc_html_e('Excluded Cookies', 'imgpress-wp'); ?></label>
                        </th>
                        <td>
                            <textarea
                                id="ip_cache_excluded_cookies"
                                name="imgpress_wp_options[cache_excluded_cookies]"
                                rows="5"
                                class="large-text code"
                            ><?php echo esc_textarea($cacheExcludedCookies); ?></textarea>
                            <p class="description"><?php esc_html_e('One cookie name or partial cookie name per line.', 'imgpress-wp'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ip_cache_ignored_query_args"><?php esc_html_e('Ignored Query Args', 'imgpress-wp'); ?></label>
                        </th>
                        <td>
                            <textarea
                                id="ip_cache_ignored_query_args"
                                name="imgpress_wp_options[cache_ignored_query_args]"
                                rows="5"
                                class="large-text code"
                            ><?php echo esc_textarea($cacheIgnoredQueryArgs); ?></textarea>
                            <p class="description"><?php esc_html_e('Tracking query strings listed here share the same cache file.', 'imgpress-wp'); ?></p>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Tab 5: CSS / JS -->
        <div class="imgpress-tab-content" id="assets" role="tabpanel" hidden>
            <div class="imgpress-card">
                <h2 class="imgpress-card-title">
                    <span class="dashicons dashicons-editor-code"></span>
                    <?php esc_html_e('CSS / JS Optimization', 'imgpress-wp'); ?>
                </h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <td style="padding:12px 0">
                            <label class="imgpress-checkbox">
                                <input
                                    type="checkbox"
                                    name="imgpress_wp_options[optimize_css_minify]"
                                    value="1"
                                    <?php checked(!empty($opts['optimize_css_minify'])); ?>
                                />
                                <span class="checkbox-label">
                                    <strong><?php esc_html_e('Minify CSS files', 'imgpress-wp'); ?></strong>
                                    <span class="description"><?php esc_html_e('Creates optimized CSS copies in wp-content/cache/imgpress/assets.', 'imgpress-wp'); ?></span>
                                </span>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:12px 0;border-top:1px solid #ddd">
                            <label class="imgpress-checkbox">
                                <input
                                    type="checkbox"
                                    name="imgpress_wp_options[optimize_js_minify]"
                                    value="1"
                                    <?php checked(!empty($opts['optimize_js_minify'])); ?>
                                />
                                <span class="checkbox-label">
                                    <strong><?php esc_html_e('Minify JavaScript files', 'imgpress-wp'); ?></strong>
                                    <span class="description"><?php esc_html_e('Uses the same Matthias Mullie minifier package used by FlyingPress.', 'imgpress-wp'); ?></span>
                                </span>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:12px 0;border-top:1px solid #ddd">
                            <label class="imgpress-checkbox">
                                <input
                                    type="checkbox"
                                    name="imgpress_wp_options[optimize_html_minify]"
                                    value="1"
                                    <?php checked(!empty($opts['optimize_html_minify'])); ?>
                                />
                                <span class="checkbox-label">
                                    <strong><?php esc_html_e('Minify HTML output', 'imgpress-wp'); ?></strong>
                                    <span class="description"><?php esc_html_e('Removes extra whitespace and regular HTML comments from frontend responses.', 'imgpress-wp'); ?></span>
                                </span>
                            </label>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="imgpress-card">
                <h2 class="imgpress-card-title">
                    <span class="dashicons dashicons-filter"></span>
                    <?php esc_html_e('Asset Exclusions', 'imgpress-wp'); ?>
                </h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="ip_optimize_excluded_assets"><?php esc_html_e('Excluded CSS / JS', 'imgpress-wp'); ?></label>
                        </th>
                        <td>
                            <textarea
                                id="ip_optimize_excluded_assets"
                                name="imgpress_wp_options[optimize_excluded_assets]"
                                rows="6"
                                class="large-text code"
                            ><?php echo esc_textarea($optimizeExcludedAssets); ?></textarea>
                            <p class="description"><?php esc_html_e('One URL fragment, filename, handle keyword, or folder path per line.', 'imgpress-wp'); ?></p>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="imgpress-actions">
            <div class="imgpress-save">
                <?php submit_button(__('Save Changes', 'imgpress-wp'), 'primary', 'submit', false); ?>
            </div>
            <div class="imgpress-links">
                <a href="<?php echo esc_url(admin_url('upload.php?page=imgpress-bulk')); ?>" class="button">
                    <span class="dashicons dashicons-format-gallery"></span>
                    <?php esc_html_e('Bulk Compress', 'imgpress-wp'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('upload.php?page=imgpress-r2-bulk')); ?>" class="button">
                    <span class="dashicons dashicons-cloud-upload"></span>
                    <?php esc_html_e('Bulk Offload to R2', 'imgpress-wp'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=imgpress')); ?>" class="button">
                    <span class="dashicons dashicons-performance"></span>
                    <?php esc_html_e('Dashboard', 'imgpress-wp'); ?>
                </a>
                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=imgpress_purge_asset_cache'), 'imgpress_purge_asset_cache')); ?>" class="button">
                    <span class="dashicons dashicons-trash"></span>
                    <?php esc_html_e('Purge Asset Cache', 'imgpress-wp'); ?>
                </a>
            </div>
        </div>
    </form>
</div>
