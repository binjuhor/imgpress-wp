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
                        failed++;
                        $('#ip-r2-failed-count').text(failed);
                        $('#ip-r2-results-tbody').append(
                            '<tr class="ip-row--error">' +
                            '<td class="ip-file">' + $('<div>').text(name).html() + '</td>' +
                            '<td colspan="2"><span class="ip-err">No URL returned</span></td>' +
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

})(jQuery);
