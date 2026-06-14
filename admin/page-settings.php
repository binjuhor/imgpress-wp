<?php defined('ABSPATH') || exit; ?>
<div class="wrap ip-wrap">
    <div class="ip-header">
        <svg class="ip-logo" viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg">
            <rect width="40" height="40" rx="8" fill="#00ff88" fill-opacity="0.12"/>
            <path d="M12 28L20 12L28 28" stroke="#00ff88" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
            <path d="M15 22H25" stroke="#00ff88" stroke-width="2.5" stroke-linecap="round"/>
        </svg>
        <div>
            <h1 class="ip-title">ImgPress</h1>
            <p class="ip-subtitle">Media compression via the imgpress API</p>
        </div>
    </div>

    <?php settings_errors('imgpress_wp_options'); ?>

    <form method="post" action="options.php" class="ip-form">
        <?php settings_fields('imgpress_wp'); ?>

        <?php
        $opts       = get_option('imgpress_wp_options', []);
        $apiUrl     = $opts['api_url']         ?? 'http://localhost:3000';
        $licenseKey = $opts['license_key']     ?? '';
        $auto       = !empty($opts['auto_compress']);
        $quality    = (int) ($opts['quality']  ?? 80);
        $format     = $opts['format']          ?? 'webp';
        $width      = (int) ($opts['max_width'] ?? 1600);
        $types      = (array) ($opts['enabled_types'] ?? ['image','pdf','audio','video']);
        $timeout    = (int) ($opts['request_timeout'] ?? 120);
        ?>

        <div class="ip-card">
            <h2 class="ip-card__title">Connection</h2>

            <div class="ip-field">
                <label class="ip-label" for="ip_api_url">API URL</label>
                <div class="ip-input-group">
                    <input
                        type="url"
                        id="ip_api_url"
                        name="imgpress_wp_options[api_url]"
                        value="<?php echo esc_attr($apiUrl); ?>"
                        class="ip-input"
                        placeholder="http://localhost:3000"
                    />
                    <button type="button" class="ip-btn ip-btn--ghost" id="ip-test-conn">
                        Test Connection
                    </button>
                </div>
                <span class="ip-help">No trailing slash. Use the internal Docker hostname if WordPress and imgpress share a network.</span>
                <span id="ip-conn-result" class="ip-conn-result"></span>
            </div>

            <div class="ip-field">
                <label class="ip-label" for="ip_license_key">License Key</label>
                <input
                    type="password"
                    id="ip_license_key"
                    name="imgpress_wp_options[license_key]"
                    value="<?php echo esc_attr($licenseKey); ?>"
                    class="ip-input"
                    placeholder="Leave empty if your server has no API_KEYS configured"
                    autocomplete="off"
                />
                <span class="ip-help">Sent as <code>X-API-Key</code> with every request. Must match a key in <code>API_KEYS</code> on your imgpress server.</span>
            </div>

            <div class="ip-field">
                <label class="ip-label" for="ip_timeout">Request Timeout</label>
                <input
                    type="number"
                    id="ip_timeout"
                    name="imgpress_wp_options[request_timeout]"
                    value="<?php echo esc_attr($timeout); ?>"
                    class="ip-input ip-input--sm"
                    min="10"
                    max="600"
                /> <span class="ip-unit">seconds</span>
            </div>
        </div>

        <div class="ip-card">
            <h2 class="ip-card__title">Compression</h2>

            <div class="ip-field">
                <label class="ip-label ip-label--toggle">
                    <input
                        type="checkbox"
                        name="imgpress_wp_options[auto_compress]"
                        value="1"
                        <?php checked($auto); ?>
                        class="ip-toggle-input"
                    />
                    <span class="ip-toggle"></span>
                    Auto-compress on upload
                </label>
            </div>

            <div class="ip-field">
                <label class="ip-label" for="ip_quality">Quality</label>
                <div class="ip-slider-wrap">
                    <input
                        type="range"
                        id="ip_quality"
                        name="imgpress_wp_options[quality]"
                        value="<?php echo esc_attr($quality); ?>"
                        min="1"
                        max="100"
                        class="ip-slider"
                    />
                    <span class="ip-slider-val" id="ip-quality-val"><?php echo esc_html($quality); ?></span>
                </div>
            </div>

            <div class="ip-field">
                <label class="ip-label" for="ip_format">Output Format</label>
                <select id="ip_format" name="imgpress_wp_options[format]" class="ip-select">
                    <?php foreach (['webp' => 'WebP', 'avif' => 'AVIF', 'jpeg' => 'JPEG', 'auto' => 'Auto (keep original)'] as $val => $label): ?>
                        <option value="<?php echo esc_attr($val); ?>" <?php selected($format, $val); ?>>
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="ip-field">
                <label class="ip-label" for="ip_width">Max Width</label>
                <input
                    type="number"
                    id="ip_width"
                    name="imgpress_wp_options[max_width]"
                    value="<?php echo esc_attr($width); ?>"
                    class="ip-input ip-input--sm"
                    min="100"
                    max="20000"
                /> <span class="ip-unit">px (images only)</span>
            </div>
        </div>

        <div class="ip-card">
            <h2 class="ip-card__title">File Types</h2>
            <div class="ip-types-grid">
                <?php
                $typeLabels = [
                    'image' => ['label' => 'Images', 'sub' => 'JPEG · PNG · WebP · HEIC · AVIF · GIF'],
                    'pdf'   => ['label' => 'PDFs',   'sub' => 'Compressed via Ghostscript'],
                    'audio' => ['label' => 'Audio',  'sub' => 'MP3 · WAV · FLAC → M4A'],
                    'video' => ['label' => 'Video',  'sub' => 'MP4 · MOV · AVI → MP4'],
                ];
                foreach ($typeLabels as $val => $info):
                ?>
                <label class="ip-type-card <?php echo in_array($val, $types, true) ? 'is-checked' : ''; ?>">
                    <input
                        type="checkbox"
                        name="imgpress_wp_options[enabled_types][]"
                        value="<?php echo esc_attr($val); ?>"
                        <?php checked(in_array($val, $types, true)); ?>
                    />
                    <span class="ip-type-label"><?php echo esc_html($info['label']); ?></span>
                    <span class="ip-type-sub"><?php echo esc_html($info['sub']); ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="ip-actions">
            <?php submit_button('Save Changes', 'primary', 'submit', false, ['class' => 'ip-btn ip-btn--primary']); ?>
            <a href="<?php echo esc_url(admin_url('upload.php?page=imgpress-bulk')); ?>" class="ip-btn ip-btn--ghost">
                Bulk Compress →
            </a>
        </div>
    </form>
</div>

<script>
(function($) {
    // Quality slider live value
    $('#ip_quality').on('input', function() {
        $('#ip-quality-val').text(this.value);
    });

    // Type card toggle visual
    $('.ip-type-card input').on('change', function() {
        $(this).closest('.ip-type-card').toggleClass('is-checked', this.checked);
    });

    // Test connection
    $('#ip-test-conn').on('click', function() {
        const $btn    = $(this);
        const $result = $('#ip-conn-result');
        const url     = $('#ip_api_url').val();

        $btn.prop('disabled', true).text('Testing…');
        $result.removeClass('ip-conn-result--ok ip-conn-result--err').text('');

        $.post(ajaxurl, {
            action: 'imgpress_test_connection',
            _ajax_nonce: '<?php echo wp_create_nonce("imgpress_test_connection"); ?>'
        }, function(res) {
            if (res.success) {
                $result.addClass('ip-conn-result--ok').text('✓ Connected');
            } else {
                $result.addClass('ip-conn-result--err').text('✗ ' + (res.data || 'Failed'));
            }
        }).fail(function() {
            $result.addClass('ip-conn-result--err').text('✗ Request failed');
        }).always(function() {
            $btn.prop('disabled', false).text('Test Connection');
        });
    });
})(jQuery);
</script>
