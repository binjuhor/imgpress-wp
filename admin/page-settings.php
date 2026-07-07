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
    ?>

    <!-- Tab Navigation -->
    <div class="imgpress-tabs-nav">
        <button class="imgpress-tab-button active" data-tab="compression">
            <span class="dashicons dashicons-image-filter"></span>
            <span><?php esc_html_e('Compression', 'imgpress-wp'); ?></span>
        </button>
        <button class="imgpress-tab-button" data-tab="r2">
            <span class="dashicons dashicons-cloud"></span>
            <span><?php esc_html_e('R2 Storage', 'imgpress-wp'); ?></span>
        </button>
        <button class="imgpress-tab-button" data-tab="files">
            <span class="dashicons dashicons-media-document"></span>
            <span><?php esc_html_e('File Types', 'imgpress-wp'); ?></span>
        </button>
    </div>

    <form method="post" action="options.php" class="imgpress-form">
        <?php settings_fields('imgpress_wp'); ?>

        <!-- Tab 1: Compression Settings -->
        <div class="imgpress-tab-content active" id="compression">
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
        <div class="imgpress-tab-content" id="r2">
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
                            <strong><?php esc_html_e('Converted files auto-upload to R2 when R2 is enabled.', 'imgpress-wp'); ?></strong>
                            <span class="description">This runs after ImgPress successfully converts/compresses the file.</span>
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
        <div class="imgpress-tab-content" id="files">
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
            </div>
        </div>
    </form>
</div>
