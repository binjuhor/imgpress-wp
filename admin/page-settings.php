<?php defined('ABSPATH') || exit; ?>
<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

    <?php settings_errors('imgpress_wp_options'); ?>

    <form method="post" action="options.php">
        <?php settings_fields('imgpress_wp'); ?>

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

        <h2 class="title"><?php esc_html_e('Connection', 'imgpress-wp'); ?></h2>
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
                        <?php esc_html_e('No trailing slash. Use the internal Docker hostname if WordPress and imgpress share a network.', 'imgpress-wp'); ?>
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

        <h2 class="title"><?php esc_html_e('Compression', 'imgpress-wp'); ?></h2>
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
                        <?php esc_html_e('Compress automatically on upload', 'imgpress-wp'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="ip_quality"><?php esc_html_e('Quality', 'imgpress-wp'); ?></label>
                </th>
                <td>
                    <input
                        type="range"
                        id="ip_quality"
                        name="imgpress_wp_options[quality]"
                        value="<?php echo esc_attr($quality); ?>"
                        min="1"
                        max="100"
                        style="width:200px;vertical-align:middle"
                    />
                    <strong id="ip-quality-val" style="margin-left:8px"><?php echo esc_html($quality); ?></strong>
                    <p class="description">
                        <?php esc_html_e('1 (smallest file) — 100 (best quality). Default: 80.', 'imgpress-wp'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="ip_format"><?php esc_html_e('Output Format', 'imgpress-wp'); ?></label>
                </th>
                <td>
                    <select id="ip_format" name="imgpress_wp_options[format]">
                        <?php foreach (['webp' => 'WebP', 'avif' => 'AVIF', 'jpeg' => 'JPEG', 'auto' => 'Auto (keep original)'] as $val => $label): ?>
                            <option value="<?php echo esc_attr($val); ?>" <?php selected($format, $val); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
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
                        <?php esc_html_e('px — images only', 'imgpress-wp'); ?>
                    </span>
                </td>
            </tr>
        </table>

        <h2 class="title"><?php esc_html_e('Cloudflare R2 Storage', 'imgpress-wp'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('Enable R2', 'imgpress-wp'); ?></th>
                <td>
                    <label for="r2_enabled">
                        <input
                            type="checkbox"
                            id="r2_enabled"
                            name="imgpress_wp_options[r2_enabled]"
                            value="1"
                            <?php checked(!empty($opts['r2_enabled'])); ?>
                        />
                        <?php esc_html_e('Enable Cloudflare R2 storage', 'imgpress-wp'); ?>
                    </label>
                </td>
            </tr>
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
                        placeholder="abc123def456"
                    />
                    <p class="description">
                        <?php esc_html_e('Found in Cloudflare R2 dashboard under Account ID.', 'imgpress-wp'); ?>
                    </p>
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
                        placeholder="xxxxx"
                    />
                    <p class="description">
                        <?php esc_html_e('S3 Access Key ID from R2 API token.', 'imgpress-wp'); ?>
                    </p>
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
                        <?php esc_html_e('S3 Secret Access Key from R2 API token. Leave blank to keep current value.', 'imgpress-wp'); ?>
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
                        placeholder="media-bucket"
                    />
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="r2_custom_domain"><?php esc_html_e('Custom Domain', 'imgpress-wp'); ?></label>
                </th>
                <td>
                    <input
                        type="text"
                        id="r2_custom_domain"
                        name="imgpress_wp_options[r2_custom_domain]"
                        value="<?php echo esc_attr($opts['r2_custom_domain'] ?? ''); ?>"
                        class="regular-text"
                        placeholder="media.example.com"
                    />
                    <p class="description">
                        <?php esc_html_e('Custom domain for public access (host only, no scheme). Leave empty to use default R2 domain.', 'imgpress-wp'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Auto-push Settings', 'imgpress-wp'); ?></th>
                <td>
                    <label style="display:block;margin-bottom:8px">
                        <input
                            type="checkbox"
                            name="imgpress_wp_options[r2_push_on_compress]"
                            value="1"
                            <?php checked(!empty($opts['r2_push_on_compress'])); ?>
                        />
                        <?php esc_html_e('Auto-push compressed files to R2', 'imgpress-wp'); ?>
                    </label>
                    <label style="display:block;margin-bottom:8px">
                        <input
                            type="checkbox"
                            name="imgpress_wp_options[r2_push_on_upload]"
                            value="1"
                            <?php checked(!empty($opts['r2_push_on_upload'])); ?>
                        />
                        <?php esc_html_e('Auto-push uncompressed files on upload', 'imgpress-wp'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Dangerous Settings', 'imgpress-wp'); ?></th>
                <td>
                    <label>
                        <input
                            type="checkbox"
                            name="imgpress_wp_options[r2_delete_local]"
                            value="1"
                            <?php checked(!empty($opts['r2_delete_local'])); ?>
                        />
                        <strong><?php esc_html_e('Delete local files after uploading to R2', 'imgpress-wp'); ?></strong>
                    </label>
                    <p class="description" style="color:#d63638">
                        <?php esc_html_e('Warning: Files will be permanently deleted from your server after sync. Enable only if you have reliable backups.', 'imgpress-wp'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('URL Rewriting', 'imgpress-wp'); ?></th>
                <td>
                    <label>
                        <input
                            type="checkbox"
                            name="imgpress_wp_options[r2_rewrite_content]"
                            value="1"
                            <?php checked(!empty($opts['r2_rewrite_content'])); ?>
                        />
                        <?php esc_html_e('Rewrite hardcoded URLs in post content', 'imgpress-wp'); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e('Automatically replace image URLs in post content with R2 URLs. Slight performance overhead.', 'imgpress-wp'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Test Connection', 'imgpress-wp'); ?></th>
                <td>
                    <button type="button" class="button" id="r2-test-conn">
                        <?php esc_html_e('Test R2 Connection', 'imgpress-wp'); ?>
                    </button>
                    <span id="r2-conn-result" style="margin-left:8px;font-size:13px"></span>
                </td>
            </tr>
        </table>

        <h2 class="title"><?php esc_html_e('File Types', 'imgpress-wp'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('Enable for', 'imgpress-wp'); ?></th>
                <td>
                    <?php
                    $typeLabels = [
                        'image' => ['label' => __('Images', 'imgpress-wp'), 'sub' => 'JPEG · PNG · WebP · HEIC · AVIF · GIF'],
                        'pdf'   => ['label' => __('PDFs', 'imgpress-wp'),   'sub' => 'Compressed via Ghostscript'],
                        'audio' => ['label' => __('Audio', 'imgpress-wp'),  'sub' => 'MP3 · WAV · FLAC → M4A'],
                        'video' => ['label' => __('Video', 'imgpress-wp'),  'sub' => 'MP4 · MOV · AVI → MP4'],
                    ];
                    foreach ($typeLabels as $val => $info): ?>
                    <label style="display:block;margin-bottom:10px">
                        <input
                            type="checkbox"
                            name="imgpress_wp_options[enabled_types][]"
                            value="<?php echo esc_attr($val); ?>"
                            <?php checked(in_array($val, $types, true)); ?>
                        />
                        <strong><?php echo esc_html($info['label']); ?></strong>
                        &mdash;
                        <span class="description" style="display:inline">
                            <?php echo esc_html($info['sub']); ?>
                        </span>
                    </label>
                    <?php endforeach; ?>
                </td>
            </tr>
        </table>

        <p class="submit">
            <?php submit_button(__('Save Changes', 'imgpress-wp'), 'primary', 'submit', false); ?>
            <a href="<?php echo esc_url(admin_url('upload.php?page=imgpress-bulk')); ?>" class="button" style="margin-left:8px">
                <?php esc_html_e('Bulk Compress →', 'imgpress-wp'); ?>
            </a>
            <a href="<?php echo esc_url(admin_url('upload.php?page=imgpress-r2-bulk')); ?>" class="button" style="margin-left:8px">
                <?php esc_html_e('Bulk Offload to R2 →', 'imgpress-wp'); ?>
            </a>
        </p>
    </form>
</div>

<script>
(function ($) {
    $('#ip_quality').on('input', function () {
        $('#ip-quality-val').text(this.value);
    });

    $('#ip-test-conn').on('click', function () {
        var $btn    = $(this);
        var $result = $('#ip-conn-result');

        $btn.prop('disabled', true).text('<?php echo esc_js(__('Testing…', 'imgpress-wp')); ?>');
        $result.css('color', '').text('');

        $.post(ajaxurl, {
            action:      'imgpress_test_connection',
            _ajax_nonce: '<?php echo wp_create_nonce('imgpress_test_connection'); ?>',
        }, function (res) {
            if (res.success) {
                $result.css('color', '#00a32a').text('✓ <?php echo esc_js(__('Connected', 'imgpress-wp')); ?>');
            } else {
                $result.css('color', '#d63638').text('✗ ' + (res.data || '<?php echo esc_js(__('Failed', 'imgpress-wp')); ?>'));
            }
        }).fail(function () {
            $result.css('color', '#d63638').text('✗ <?php echo esc_js(__('Request failed', 'imgpress-wp')); ?>');
        }).always(function () {
            $btn.prop('disabled', false).text('<?php echo esc_js(__('Test Connection', 'imgpress-wp')); ?>');
        });
    });

    $('#r2-test-conn').on('click', function () {
        var $btn    = $(this);
        var $result = $('#r2-conn-result');

        $btn.prop('disabled', true).text('<?php echo esc_js(__('Testing…', 'imgpress-wp')); ?>');
        $result.css('color', '').text('');

        $.post(ajaxurl, {
            action:      'imgpress_test_r2',
            _ajax_nonce: '<?php echo wp_create_nonce('imgpress_test_r2'); ?>',
        }, function (res) {
            if (res.success) {
                $result.css('color', '#00a32a').text('✓ ' + (res.data || '<?php echo esc_js(__('Connected', 'imgpress-wp')); ?>'));
            } else {
                $result.css('color', '#d63638').text('✗ ' + (res.data || '<?php echo esc_js(__('Failed', 'imgpress-wp')); ?>'));
            }
        }).fail(function () {
            $result.css('color', '#d63638').text('✗ <?php echo esc_js(__('Request failed', 'imgpress-wp')); ?>');
        }).always(function () {
            $btn.prop('disabled', false).text('<?php echo esc_js(__('Test R2 Connection', 'imgpress-wp')); ?>');
        });
    });
})(jQuery);
</script>
