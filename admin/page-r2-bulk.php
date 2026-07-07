<?php defined('ABSPATH') || exit; ?>
<div class="wrap">
	<h1><?php esc_html_e('ImgPress R2 Offload', 'imgpress-wp'); ?></h1>
	<p class="description"><?php esc_html_e('Upload your entire media library to Cloudflare R2.', 'imgpress-wp'); ?></p>

	<?php if (!$GLOBALS['imgpress_settings']->isR2Configured()) : ?>
		<div class="notice notice-warning">
			<p>
				<?php
				echo wp_kses_post(
					sprintf(
						__('R2 is not configured. Please visit <a href="%s">ImgPress → Settings</a> to configure your R2 credentials.', 'imgpress-wp'),
                    esc_url(menu_page_url('imgpress-settings', false))
					)
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<div class="card" style="max-width:800px;margin-top:16px;padding:20px 24px">
		<table class="widefat" style="margin-bottom:20px;table-layout:fixed">
			<thead>
				<tr>
					<th><?php esc_html_e('Pending files', 'imgpress-wp'); ?></th>
					<th><?php esc_html_e('Uploaded this run', 'imgpress-wp'); ?></th>
					<th><?php esc_html_e('Failed', 'imgpress-wp'); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td id="ip-r2-pending-count">—</td>
					<td id="ip-r2-done-count">0</td>
					<td id="ip-r2-failed-count">0</td>
				</tr>
			</tbody>
		</table>

		<div id="ip-r2-progress-wrap" style="display:none;margin-bottom:16px">
			<div style="background:#dcdcde;border-radius:3px;height:8px;overflow:hidden">
				<div id="ip-r2-progress-bar" style="height:100%;background:#2271b1;width:0%;transition:width .3s ease"></div>
			</div>
			<p class="description" id="ip-r2-progress-label" style="margin-top:6px">
				<?php esc_html_e('Starting…', 'imgpress-wp'); ?>
			</p>
		</div>

		<p>
			<button id="ip-r2-bulk-btn" class="button button-primary" disabled>
				<?php esc_html_e('Start Bulk Offload', 'imgpress-wp'); ?>
			</button>
			<span id="ip-r2-bulk-status" style="margin-left:12px;color:#646970;font-size:13px"></span>
		</p>
	</div>

	<div id="ip-r2-results-card" style="display:none;margin-top:24px">
		<h2><?php esc_html_e('Results', 'imgpress-wp'); ?></h2>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e('File', 'imgpress-wp'); ?></th>
					<th style="width:100px"><?php esc_html_e('Status', 'imgpress-wp'); ?></th>
					<th><?php esc_html_e('URL', 'imgpress-wp'); ?></th>
				</tr>
			</thead>
			<tbody id="ip-r2-results-tbody">
			</tbody>
		</table>
	</div>
</div>
