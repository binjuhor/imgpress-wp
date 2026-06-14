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

    function formatBytes(b) {
        if (b >= 1048576) return (b / 1048576).toFixed(1) + ' MB';
        return (b / 1024).toFixed(1) + ' KB';
    }

})(jQuery);
