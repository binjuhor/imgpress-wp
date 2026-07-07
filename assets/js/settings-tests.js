(function ($) {
    'use strict';

    // Test ImgPress connection
    $('#ip-test-conn').on('click', function () {
        var $btn    = $(this);
        var $result = $('#ip-conn-result');

        $btn.prop('disabled', true).text(ImgPressAdmin.i18n.testing);
        $result.css('color', '').text('');

        $.post(ajaxurl, {
            action:      'imgpress_test_connection',
            _ajax_nonce: ImgPressAdmin.nonce.testConnection,
        }, function (res) {
            if (res.success) {
                $result.css('color', '#00a32a').text('✓ ' + responseMessage(res.data, ImgPressAdmin.i18n.connected));
            } else {
                $result.css('color', '#d63638').text('✗ ' + responseMessage(res.data, ImgPressAdmin.i18n.failed));
            }
        }).fail(function () {
            $result.css('color', '#d63638').text('✗ ' + ImgPressAdmin.i18n.requestFailed);
        }).always(function () {
            $btn.prop('disabled', false).text(ImgPressAdmin.i18n.testConnection);
        });
    });

    // Test R2 connection
    $('#r2-test-conn').on('click', function () {
        var $btn    = $(this);
        var $result = $('#r2-conn-result');

        $btn.prop('disabled', true).text(ImgPressAdmin.i18n.testing);
        $result.css('color', '').text('');

        var data = $('form').serializeArray();
        data.push({
            name: 'action',
            value: 'imgpress_test_r2',
        });
        data.push({
            name: '_ajax_nonce',
            value: ImgPressAdmin.nonce.testR2,
        });

        $.post(ajaxurl, data, function (res) {
            if (res.success) {
                if (res.data && typeof res.data.publicDomain === 'string') {
                    $('#r2_custom_domain').val(res.data.publicDomain);
                }
                $result.css('color', '#00a32a').text('✓ ' + responseMessage(res.data, ImgPressAdmin.i18n.connected));
            } else {
                $result.css('color', '#d63638').text('✗ ' + responseMessage(res.data, ImgPressAdmin.i18n.failed));
            }
        }).fail(function () {
            $result.css('color', '#d63638').text('✗ ' + ImgPressAdmin.i18n.requestFailed);
        }).always(function () {
            $btn.prop('disabled', false).text(ImgPressAdmin.i18n.testR2);
        });
    });

    // Run database cleanup
    $('#ip-db-cleanup-run').on('click', function () {
        var $btn = $(this);
        var $result = $('#ip-db-cleanup-result');
        var originalText = $btn.text();

        $btn.prop('disabled', true).text(ImgPressAdmin.i18n.testing);
        $result.css('color', '').text('');

        $.post(ajaxurl, {
            action: 'imgpress_db_cleanup_run',
            _ajax_nonce: ImgPressAdmin.nonce.dbCleanup,
        }, function (res) {
            if (res.success) {
                $result.css('color', '#00a32a').text('✓ ' + responseMessage(res.data, 'Cleanup completed'));
                loadCleanupCounts();
            } else {
                $result.css('color', '#d63638').text('✗ ' + responseMessage(res.data, ImgPressAdmin.i18n.failed));
            }
        }).fail(function () {
            $result.css('color', '#d63638').text('✗ ' + ImgPressAdmin.i18n.requestFailed);
        }).always(function () {
            $btn.prop('disabled', false).text(originalText);
        });
    });

    function loadCleanupCounts() {
        var $badges = $('[data-cleanup-count]');
        if (!$badges.length) {
            return;
        }

        $.post(ajaxurl, {
            action: 'imgpress_db_cleanup_counts',
            _ajax_nonce: ImgPressAdmin.nonce.dbCleanupCounts,
        }, function (res) {
            if (!res.success || !res.data || !res.data.counts) {
                return;
            }

            var counts = res.data.counts;
            $badges.each(function () {
                var $badge = $(this);
                var key = $badge.data('cleanup-count');
                var count = parseInt(counts[key], 10) || 0;
                $badge.text(count);
                $badge.toggleClass('is-empty', count === 0);
            });

            var $total = $('#ip-db-cleanup-total');
            if ($total.length) {
                $total.text(res.data.total || 0);
            }
        });
    }

    $(loadCleanupCounts);

    function responseMessage(data, fallback) {
        if (typeof data === 'string' && data.length) {
            return data;
        }

        if (data && typeof data.message === 'string' && data.message.length) {
            return data.message;
        }

        return fallback;
    }

})(jQuery);
