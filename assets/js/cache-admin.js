(function ($) {
    'use strict';

    $(document).ready(function () {
        $('#imgpress-clear-cache-btn').on('click', function () {
            var $btn = $(this);
            var $stats = $('#imgpress-cache-stats');

            $btn.prop('disabled', true).text('Clearing...');
            $stats.text('');

            $.post(ImgPressCacheAdmin.ajaxUrl, {
                action: 'imgpress_cache_clear',
                _ajax_nonce: ImgPressCacheAdmin.nonce,
            }, function (res) {
                if (res.success) {
                    $stats.text('✓ Cache cleared');
                    loadCacheStats();
                } else {
                    $stats.css('color', '#d63638').text('✗ Failed to clear cache');
                }
            }).fail(function () {
                $stats.css('color', '#d63638').text('✗ Request failed');
            }).always(function () {
                $btn.prop('disabled', false).text('Clear All Cache');
            });
        });

        loadCacheStats();
    });

    function loadCacheStats() {
        $.post(ImgPressCacheAdmin.ajaxUrl, {
            action: 'imgpress_cache_stats',
            _ajax_nonce: ImgPressCacheAdmin.nonce,
        }, function (res) {
            if (res.success) {
                var stats = res.data;
                $('#imgpress-cache-stats').text(
                    'Files: ' + stats.file_count + ' | Size: ' + stats.size_human
                );
            }
        });
    }

})(jQuery);
