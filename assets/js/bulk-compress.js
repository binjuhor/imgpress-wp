(function ($) {
    'use strict';

    // ── Bulk Compress page ────────────────────────────────────────────────────

    if ($('#ip-bulk-btn').length) {
        var ids        = [];
        var done       = 0;
        var totalSaved = 0;
        var ratios     = [];
        var running    = false;
		var reconvertIds = [];
		var activeButton = '#ip-bulk-btn';

        $.post(ImgPressAdmin.ajaxUrl, {
            action:      'imgpress_bulk_get_ids',
            _ajax_nonce: ImgPressAdmin.nonce,
        }, function (res) {
            if (!res.success) { return; }
            ids = res.data.ids;
            $('#ip-uncompressed-count').text(ids.length);
            if (ids.length > 0) {
                $('#ip-bulk-btn').prop('disabled', false);
            } else {
                $('#ip-bulk-status').text('All media is already compressed.');
            }
        });

		$.post(ImgPressAdmin.ajaxUrl, {
			action: 'imgpress_bulk_get_reconvert_ids', _ajax_nonce: ImgPressAdmin.nonce,
		}, function (res) {
			if (!res.success) { return; }
			reconvertIds = res.data.ids;
			$('#ip-reconvert-btn').prop('disabled', reconvertIds.length === 0);
			$('#ip-reconvert-status').text(reconvertIds.length
				? reconvertIds.length + ' image(s) can be converted to ' + res.data.format.toUpperCase() + '.'
				: 'All optimized images already use the selected format.');
		});

        $('#ip-bulk-btn').on('click', function () {
            if (running) { return; }
			activeButton = '#ip-bulk-btn';
            running    = true;
            done       = 0;
            totalSaved = 0;
            ratios     = [];

            $(this).prop('disabled', true).text('Running…');
            $('#ip-progress-wrap').show();
            $('#ip-results-card').show();
            $('#ip-results-tbody').empty();

            processNext();
        });

		$('#ip-reconvert-btn').on('click', function () {
			if (running || !reconvertIds.length) { return; }
			ids = reconvertIds.slice();
			activeButton = '#ip-reconvert-btn';
			running = true; done = 0; totalSaved = 0; ratios = [];
			$(this).prop('disabled', true).text('Re-converting…');
			$('#ip-progress-wrap, #ip-results-card').show();
			$('#ip-results-tbody').empty();
			processNext();
		});

        function processNext() {
            if (done >= ids.length) {
                finish();
                return;
            }

            var id  = ids[done];
            var pct = Math.round((done / ids.length) * 100);

            $('#ip-progress-bar').css('width', pct + '%');
            $('#ip-progress-label').text('Processing ' + (done + 1) + ' of ' + ids.length + '…');

            $.post(ImgPressAdmin.ajaxUrl, {
                action:      'imgpress_bulk_compress',
                _ajax_nonce: ImgPressAdmin.nonce,
                id:          id,
                reconvert:   activeButton === '#ip-reconvert-btn' ? 1 : 0,
            }, function (res) {
                done++;
                $('#ip-done-count').text(done);

                if (res.success) {
                    var s    = res.data.stats;
                    var name = res.data.name;
                    var saved = s.originalSize - s.compressedSize;
                    totalSaved += saved;
                    ratios.push(s.ratio);

                    var tier = s.ratio >= 60 ? 'high' : (s.ratio >= 30 ? 'mid' : 'low');
                    $('#ip-results-tbody').append(
                        '<tr>' +
                        '<td class="ip-file">' + $('<div>').text(name).html() + '</td>' +
                        '<td>' + formatBytes(s.originalSize) + '</td>' +
                        '<td>' + formatBytes(s.compressedSize) + '</td>' +
                        '<td><span class="ip-badge ip-badge--' + tier + '">−' + s.ratio.toFixed(1) + '%</span></td>' +
                        '</tr>'
                    );

                    $('#ip-saved-total').text(formatBytes(totalSaved));
                    var avg = ratios.reduce(function (a, b) { return a + b; }, 0) / ratios.length;
                    $('#ip-avg-ratio').text(avg.toFixed(1) + '%');
                } else {
                    var errName = (res.data && res.data.name) ? res.data.name : '#' + id;
                    $('#ip-results-tbody').append(
                        '<tr class="ip-row--error">' +
                        '<td class="ip-file">' + $('<div>').text(errName).html() + '</td>' +
                        '<td colspan="3"><span class="ip-err">Failed</span></td>' +
                        '</tr>'
                    );
                }

                processNext();
            }).fail(function () {
                done++;
                processNext();
            });
        }

        function finish() {
            running = false;
            $('#ip-progress-bar').css('width', '100%');
            $('#ip-progress-label').text('Done — ' + done + ' files processed.');
			$(activeButton).prop('disabled', false).text(activeButton === '#ip-reconvert-btn' ? 'Re-convert Again' : 'Run Again');
            $('#ip-bulk-status').text('');
        }
    }

    function formatBytes(b) {
        if (b >= 1048576) return (b / 1048576).toFixed(1) + ' MB';
        return (b / 1024).toFixed(1) + ' KB';
    }

})(jQuery);
