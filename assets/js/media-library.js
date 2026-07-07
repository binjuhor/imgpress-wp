(function ($) {
    'use strict';

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
                    $btn.remove();
                    $result.html(
                        '<span class="ip-badge ip-r2-badge">R2 ✓</span>' +
                        '<span class="ip-r2-link">No public URL</span>' +
                        '<button class="button ip-r2-btn ip-r2-remove-btn" data-id="' + id + '">Remove</button>'
                    );
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

    function formatBytes(b) {
        if (b >= 1048576) return (b / 1048576).toFixed(1) + ' MB';
        return (b / 1024).toFixed(1) + ' KB';
    }

})(jQuery);
