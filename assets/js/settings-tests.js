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
                $result.css('color', '#00a32a').text('✓ ' + ImgPressAdmin.i18n.connected);
            } else {
                $result.css('color', '#d63638').text('✗ ' + (res.data || ImgPressAdmin.i18n.failed));
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
                var message = (res.data && res.data.message) ? res.data.message : ImgPressAdmin.i18n.connected;
                if (res.data && typeof res.data.publicDomain === 'string') {
                    $('#r2_custom_domain').val(res.data.publicDomain);
                }
                $result.css('color', '#00a32a').text('✓ ' + message);
            } else {
                $result.css('color', '#d63638').text('✗ ' + (res.data || ImgPressAdmin.i18n.failed));
            }
        }).fail(function () {
            $result.css('color', '#d63638').text('✗ ' + ImgPressAdmin.i18n.requestFailed);
        }).always(function () {
            $btn.prop('disabled', false).text(ImgPressAdmin.i18n.testR2);
        });
    });

})(jQuery);
