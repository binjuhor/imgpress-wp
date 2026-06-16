(function ($) {
    'use strict';

    function formatBytes(b) {
        if (b >= 1048576) return (b / 1048576).toFixed(1) + ' MB';
        return (b / 1024).toFixed(1) + ' KB';
    }

    // ── Media Library: single compress button ─────────────────────────────────

    $(document).on('click', '.ip-compress-btn', function () {
        var $btn    = $(this);
        var $result = $btn.siblings('.ip-compress-result');
        var id      = $btn.data('id');

        $btn.prop('disabled', true).text('Compressing…');

        $.post(ImgPressAdmin.ajaxUrl, {
            action:      'imgpress_compress_single',
            _ajax_nonce: ImgPressAdmin.nonce,
            id:          id,
        }, function (res) {
            if (res.success) {
                var s    = res.data;
                var tier = s.ratio >= 60 ? 'high' : (s.ratio >= 30 ? 'mid' : 'low');
                $btn.remove();
                $result.html(
                    '<span class="ip-badge ip-badge--' + tier + '">−' + s.ratio.toFixed(1) + '%</span>' +
                    '<span class="ip-sizes">' +
                        formatBytes(s.originalSize) + ' → ' + formatBytes(s.compressedSize) +
                    '</span>'
                );
            } else {
                $btn.prop('disabled', false).text('Compress');
                $result.html('<span class="ip-err">Failed</span>');
            }
        }).fail(function () {
            $btn.prop('disabled', false).text('Compress');
            $result.html('<span class="ip-err">Request failed</span>');
        });
    });

    // ── Media Library: R2 push button ────────────────────────────────────────

    $(document).on('click', '.ip-r2-push-btn', function () {
        var $btn    = $(this);
        var $result = $btn.siblings('.ip-r2-result');
        var id      = $btn.data('id');

        $btn.prop('disabled', true).text('Uploading…');

        $.post(ImgPressAdmin.ajaxUrl, {
            action:      'imgpress_r2_push',
            _ajax_nonce: ImgPressAdmin.r2Nonce,
            id:          id,
        }, function (res) {
            if (res.success) {
                var s = res.data;
                if (s.url) {
                    var domain = new URL(s.url).hostname;
                    $btn.remove();
                    $result.html(
                        '<span class="ip-badge ip-r2-badge">R2 ✓</span>' +
                        '<a href="' + $('<div>').text(s.url).html() + '" target="_blank" class="ip-r2-link">' +
                            $('<div>').text(domain).html() +
                        '</a>' +
                        '<button class="button ip-r2-btn ip-r2-remove-btn" data-id="' + id + '">Remove</button>'
                    );
                } else {
                    $btn.prop('disabled', false).text('Push to R2');
                    $result.html('<span class="ip-err">No URL returned</span>');
                }
            } else {
                $btn.prop('disabled', false).text('Push to R2');
                $result.html('<span class="ip-err">Upload failed</span>');
            }
        }).fail(function () {
            $btn.prop('disabled', false).text('Push to R2');
            $result.html('<span class="ip-err">Request failed</span>');
        });
    });

    // ── Media Library: R2 remove button ──────────────────────────────────────

    $(document).on('click', '.ip-r2-remove-btn', function () {
        var $btn    = $(this);
        var $result = $btn.parent().find('.ip-r2-result');
        var id      = $btn.data('id');

        if (!confirm('Remove this file from R2?\n\nLocal file will be kept.')) {
            return;
        }

        $btn.prop('disabled', true).text('Removing…');

        $.post(ImgPressAdmin.ajaxUrl, {
            action:      'imgpress_r2_remove',
            _ajax_nonce: ImgPressAdmin.r2Nonce,
            id:          id,
        }, function (res) {
            if (res.success) {
                $btn.remove();
                $result.html('<button class="button ip-r2-btn ip-r2-push-btn" data-id="' + id + '">Push to R2</button>');
            } else {
                $btn.prop('disabled', false).text('Remove');
                $result.html('<span class="ip-err">Remove failed</span>');
            }
        }).fail(function () {
            $btn.prop('disabled', false).text('Remove');
            $result.html('<span class="ip-err">Request failed</span>');
        });
    });

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

    // ── Bulk Compress page ────────────────────────────────────────────────────

    if ($('#ip-bulk-btn').length) {
        var ids        = [];
        var done       = 0;
        var totalSaved = 0;
        var ratios     = [];
        var running    = false;

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

        $('#ip-bulk-btn').on('click', function () {
            if (running) { return; }
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
            $('#ip-bulk-btn').prop('disabled', false).text('Run Again');
            $('#ip-bulk-status').text('');
        }
    }

})(jQuery);
