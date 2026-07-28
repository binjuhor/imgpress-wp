(function ($) {
    'use strict';

    // ── R2 Bulk Offload page ──────────────────────────────────────────────────

    if ($('#ip-r2-bulk-btn').length) {
        var ids       = [];
        var done      = 0;
        var failed    = 0;
        var running   = false;

        $.post(ImgPressAdmin.ajaxUrl, {
            action:      'imgpress_r2_bulk_get_ids',
            _ajax_nonce: ImgPressAdmin.nonce,
        }, function (res) {
            if (!res.success) { return; }
            ids = res.data.ids;
            $('#ip-r2-pending-count').text(ids.length);
            if (ids.length > 0) {
                $('#ip-r2-bulk-btn').prop('disabled', false);
            } else {
                $('#ip-r2-bulk-status').text('All media is already offloaded to R2.');
            }
        });

        $('#ip-r2-bulk-btn').on('click', function () {
            if (running) { return; }
            running = true;
            done    = 0;
            failed  = 0;

            $(this).prop('disabled', true).text('Running…');
            $('#ip-r2-progress-wrap').show();
            $('#ip-r2-results-card').show();
            $('#ip-r2-results-tbody').empty();

            processNextR2();
        });

        function processNextR2() {
            if (done + failed >= ids.length) {
                finishR2();
                return;
            }

            var idx = done + failed;
            var id  = ids[idx];
            var pct = Math.round((idx / ids.length) * 100);

            $('#ip-r2-progress-bar').css('width', pct + '%');
            $('#ip-r2-progress-label').text('Processing ' + (idx + 1) + ' of ' + ids.length + '…');

            $.post(ImgPressAdmin.ajaxUrl, {
                action:      'imgpress_r2_bulk_push',
                _ajax_nonce: ImgPressAdmin.nonce,
                id:          id,
            }, function (res) {
                if (res.success) {
                    var s    = res.data;
                    var name = s.name;
                    var url  = s.url || '';

                    done++;
                    $('#ip-r2-done-count').text(done);

                    if (url) {
                        var domain = new URL(url).hostname;
                        $('#ip-r2-results-tbody').append(
                            '<tr>' +
                            '<td class="ip-file">' + $('<div>').text(name).html() + '</td>' +
                            '<td><span class="ip-badge ip-r2-badge">✓</span></td>' +
                            '<td><a href="' + $('<div>').text(url).html() + '" target="_blank" class="ip-r2-link">' +
                                $('<div>').text(domain).html() +
                            '</a></td>' +
                            '</tr>'
                        );
                    } else {
                        $('#ip-r2-results-tbody').append(
                            '<tr>' +
                            '<td class="ip-file">' + $('<div>').text(name).html() + '</td>' +
                            '<td><span class="ip-badge ip-r2-badge">✓</span></td>' +
                            '<td><span class="ip-r2-link">No public URL</span></td>' +
                            '</tr>'
                        );
                    }
                } else {
                    var errName = (res.data && res.data.name) ? res.data.name : '#' + id;
                    failed++;
                    $('#ip-r2-failed-count').text(failed);
                    $('#ip-r2-results-tbody').append(
                        '<tr class="ip-row--error">' +
                        '<td class="ip-file">' + $('<div>').text(errName).html() + '</td>' +
                        '<td colspan="2"><span class="ip-err">Upload failed</span></td>' +
                        '</tr>'
                    );
                }

                processNextR2();
            }).fail(function () {
                failed++;
                $('#ip-r2-failed-count').text(failed);
                processNextR2();
            });
        }

        function finishR2() {
            running = false;
            $('#ip-r2-progress-bar').css('width', '100%');
            $('#ip-r2-progress-label').text('Done — ' + done + ' uploaded, ' + failed + ' failed.');
            $('#ip-r2-bulk-btn').prop('disabled', false).text('Run Again');
            $('#ip-r2-bulk-status').text('');
        }
    }

	// ── Bulk local-file management ───────────────────────────────────────────
	if ($('#ip-r2-download-btn').length) {
		var uploadedIds = [];
		$.post(ImgPressAdmin.ajaxUrl, {
			action: 'imgpress_r2_bulk_get_uploaded_ids', _ajax_nonce: ImgPressAdmin.nonce,
		}, function (res) {
			if (!res.success) { return; }
			uploadedIds = res.data.ids;
			$('#ip-r2-download-btn, #ip-r2-delete-local-btn').prop('disabled', !uploadedIds.length);
			$('#ip-r2-file-status').text(uploadedIds.length + ' offloaded attachment(s) available.');
		});

		$('#ip-r2-download-btn').on('click', function () {
			runFileAction('imgpress_r2_bulk_download', $(this), 'Downloading');
		});
		$('#ip-r2-delete-local-btn').on('click', function () {
			if (!window.confirm('Delete local files for every attachment that has a verified R2 copy?')) { return; }
			runFileAction('imgpress_r2_bulk_delete_local', $(this), 'Deleting local files');
		});

		function runFileAction(action, $button, label) {
			var index = 0, ok = 0, failedCount = 0;
			$button.prop('disabled', true);
			function next() {
				if (index >= uploadedIds.length) {
					$('#ip-r2-file-status').text('Done — ' + ok + ' succeeded, ' + failedCount + ' failed.');
					$button.prop('disabled', false);
					return;
				}
				$('#ip-r2-file-status').text(label + ' ' + (index + 1) + ' of ' + uploadedIds.length + '…');
				$.post(ImgPressAdmin.ajaxUrl, {
					action: action, _ajax_nonce: ImgPressAdmin.nonce, id: uploadedIds[index],
				}, function (res) {
					res.success ? ok++ : failedCount++; index++; next();
				}).fail(function () { failedCount++; index++; next(); });
			}
			next();
		}
	}

	// ── Orphaned R2 object cleanup ────────────────────────────────────────────
	if ($('#ip-r2-scan-orphans-btn').length) {
		var orphanObjects = [];
		var scannedObjects = 0;
		var scanPages = 0;
		var seenScanTokens = {};
		var maxScanPages = 100;

		$('#ip-r2-scan-orphans-btn').on('click', function () {
			orphanObjects = [];
			scannedObjects = 0;
			scanPages = 0;
			seenScanTokens = {};
			$('#ip-r2-orphan-results').empty();
			$('#ip-r2-orphan-table-wrap').hide();
			$('#ip-r2-delete-orphans-btn').prop('disabled', true);
			$('#ip-r2-select-all-orphans').prop('checked', false);
			$(this).prop('disabled', true);
			scanOrphanPage('');
		});

		function scanOrphanPage(token) {
			if (scanPages >= maxScanPages || (token && seenScanTokens[token])) {
				finishOrphanScan('Scan stopped safely because R2 returned too many pages or repeated a page token. No objects were deleted.');
				return;
			}
			if (token) { seenScanTokens[token] = true; }
			scanPages++;
			$('#ip-r2-orphan-status').text('Scanning… ' + scannedObjects + ' objects checked.');
			$.post(ImgPressAdmin.ajaxUrl, {
				action: 'imgpress_r2_scan_orphans',
				_ajax_nonce: ImgPressAdmin.cleanupNonce,
				continuation_token: token
			}, function (response) {
				if (!response.success) {
					finishOrphanScan('Scan failed.');
					return;
				}

				scannedObjects += response.data.scanned || 0;
				(response.data.objects || []).forEach(appendOrphan);
				if (response.data.nextToken) {
					scanOrphanPage(response.data.nextToken);
					return;
				}

				finishOrphanScan(
					orphanObjects.length
						? orphanObjects.length + ' unreferenced object(s) found. Review and select objects to delete.'
						: 'No orphaned objects found in ' + scannedObjects + ' object(s).'
				);
			}).fail(function () {
				finishOrphanScan('Scan request failed.');
			});
		}

		function appendOrphan(object) {
			var index = orphanObjects.length;
			orphanObjects.push(object);
			var modified = object.lastModified ? new Date(object.lastModified).toLocaleString() : '—';
			$('#ip-r2-orphan-results').append(
				'<tr data-orphan-index="' + index + '">' +
				'<th class="check-column"><input type="checkbox" class="ip-r2-orphan-check" value="' + index + '" /></th>' +
				'<td><code>' + $('<div>').text(object.key).html() + '</code></td>' +
				'<td>' + formatObjectBytes(object.size || 0) + '</td>' +
				'<td>' + $('<div>').text(modified).html() + '</td>' +
				'</tr>'
			);
		}

		function finishOrphanScan(message) {
			$('#ip-r2-scan-orphans-btn').prop('disabled', false);
			$('#ip-r2-orphan-status').text(message);
			$('#ip-r2-orphan-table-wrap').toggle(orphanObjects.length > 0);
		}

		$(document).on('change', '#ip-r2-select-all-orphans', function () {
			$('.ip-r2-orphan-check').prop('checked', this.checked).trigger('change');
		});

		$(document).on('change', '.ip-r2-orphan-check', function () {
			$('#ip-r2-delete-orphans-btn').prop('disabled', $('.ip-r2-orphan-check:checked').length === 0);
		});

		$('#ip-r2-delete-orphans-btn').on('click', function () {
			var selected = $('.ip-r2-orphan-check:checked').map(function () {
				return parseInt(this.value, 10);
			}).get();
			if (!selected.length || !window.confirm('Permanently delete ' + selected.length + ' selected object(s) from R2? This cannot be undone.')) {
				return;
			}

			$(this).prop('disabled', true);
			$('#ip-r2-scan-orphans-btn, .ip-r2-orphan-check, #ip-r2-select-all-orphans').prop('disabled', true);
			deleteSelectedOrphan(selected, 0, 0, 0);
		});

		function deleteSelectedOrphan(selected, position, deleted, failed) {
			if (position >= selected.length) {
				$('#ip-r2-orphan-status').text('Done — ' + deleted + ' deleted, ' + failed + ' failed.');
				$('#ip-r2-scan-orphans-btn').prop('disabled', false);
				$('#ip-r2-select-all-orphans').prop('disabled', false).prop('checked', false);
				$('.ip-r2-orphan-check').prop('disabled', false);
				return;
			}

			var index = selected[position];
			var object = orphanObjects[index];
			$('#ip-r2-orphan-status').text('Deleting ' + (position + 1) + ' of ' + selected.length + '…');
			$.post(ImgPressAdmin.ajaxUrl, {
				action: 'imgpress_r2_delete_orphan',
				_ajax_nonce: ImgPressAdmin.cleanupNonce,
				key: object.key
			}, function (response) {
				if (response.success) {
					deleted++;
					$('#ip-r2-orphan-results tr[data-orphan-index="' + index + '"]').remove();
				} else {
					failed++;
				}
				deleteSelectedOrphan(selected, position + 1, deleted, failed);
			}).fail(function () {
				deleteSelectedOrphan(selected, position + 1, deleted, failed + 1);
			});
		}

		function formatObjectBytes(bytes) {
			if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(1) + ' GB';
			if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
			if (bytes >= 1024) return (bytes / 1024).toFixed(1) + ' KB';
			return bytes + ' B';
		}
	}

})(jQuery);
