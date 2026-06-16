(function ($) {
    'use strict';

    // Tab switching for settings page
    $('.imgpress-tab-button').on('click', function (e) {
        e.preventDefault();
        var tab = $(this).data('tab');

        $('.imgpress-tab-button').removeClass('active');
        $('.imgpress-tab-content').removeClass('active');

        $(this).addClass('active');
        $('#' + tab).addClass('active');

        // Save tab preference
        localStorage.setItem('imgpress_active_tab', tab);
    });

    // Restore active tab on page load
    $(document).ready(function () {
        var activeTab = localStorage.getItem('imgpress_active_tab') || 'compression';
        var $tabBtn = $('[data-tab="' + activeTab + '"]');

        if ($tabBtn.length) {
            $tabBtn.addClass('active');
            $('#' + activeTab).addClass('active');
        }
    });

    // Quality slider value display
    $('#ip_quality').on('input', function () {
        $('#ip-quality-val').text(this.value);
    });

})(jQuery);
