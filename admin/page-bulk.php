<?php defined('ABSPATH') || exit; ?>
<div class="wrap">
    <h1><?php esc_html_e('ImgPress Bulk Compress', 'imgpress-wp'); ?></h1>
    <p class="description"><?php esc_html_e('Compress all unoptimized media in your library.', 'imgpress-wp'); ?></p>

    <div class="card" style="max-width:800px;margin-top:16px;padding:20px 24px">
        <table class="widefat" style="margin-bottom:20px;table-layout:fixed">
            <thead>
                <tr>
                    <th><?php esc_html_e('Uncompressed files', 'imgpress-wp'); ?></th>
                    <th><?php esc_html_e('Compressed this run', 'imgpress-wp'); ?></th>
                    <th><?php esc_html_e('Total saved', 'imgpress-wp'); ?></th>
                    <th><?php esc_html_e('Avg reduction', 'imgpress-wp'); ?></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td id="ip-uncompressed-count">—</td>
                    <td id="ip-done-count">0</td>
                    <td id="ip-saved-total">0 KB</td>
                    <td id="ip-avg-ratio">—</td>
                </tr>
            </tbody>
        </table>

        <div id="ip-progress-wrap" style="display:none;margin-bottom:16px">
            <div style="background:#dcdcde;border-radius:3px;height:8px;overflow:hidden">
                <div id="ip-progress-bar" style="height:100%;background:#2271b1;width:0%;transition:width .3s ease"></div>
            </div>
            <p class="description" id="ip-progress-label" style="margin-top:6px">
                <?php esc_html_e('Starting…', 'imgpress-wp'); ?>
            </p>
        </div>

        <p>
            <button id="ip-bulk-btn" class="button button-primary" disabled>
                <?php esc_html_e('Start Bulk Compress', 'imgpress-wp'); ?>
            </button>
            <span id="ip-bulk-status" style="margin-left:12px;color:#646970;font-size:13px"></span>
        </p>
		<hr style="margin:20px 0">
		<p class="description"><?php esc_html_e('Re-convert previously optimized images whose format does not match the current Output Format setting. Existing R2 objects are replaced only after the new files upload successfully.', 'imgpress-wp'); ?></p>
		<p>
			<button id="ip-reconvert-btn" class="button" disabled><?php esc_html_e('Re-convert optimized images', 'imgpress-wp'); ?></button>
			<span id="ip-reconvert-status" style="margin-left:12px;color:#646970;font-size:13px"></span>
		</p>
    </div>

    <div id="ip-results-card" style="display:none;margin-top:24px">
        <h2><?php esc_html_e('Results', 'imgpress-wp'); ?></h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('File', 'imgpress-wp'); ?></th>
                    <th style="width:100px"><?php esc_html_e('Before', 'imgpress-wp'); ?></th>
                    <th style="width:100px"><?php esc_html_e('After', 'imgpress-wp'); ?></th>
                    <th style="width:120px"><?php esc_html_e('Saved', 'imgpress-wp'); ?></th>
                </tr>
            </thead>
            <tbody id="ip-results-tbody">
            </tbody>
        </table>
    </div>
</div>
