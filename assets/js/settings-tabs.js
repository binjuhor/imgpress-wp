(function ($) {
    'use strict';

    function activateTab(tab) {
        var $tabBtn = $('.imgpress-tab-button[data-tab="' + tab + '"]');
        var $tabContent = $('#' + tab);

        if (!$tabBtn.length || !$tabContent.length) {
            tab = 'compression';
            $tabBtn = $('.imgpress-tab-button[data-tab="' + tab + '"]');
            $tabContent = $('#' + tab);
        }

        $('.imgpress-tab-button')
            .removeClass('active')
            .attr('aria-selected', 'false');
        $('.imgpress-tab-content')
            .removeClass('active')
            .attr('hidden', true);

        $tabBtn
            .addClass('active')
            .attr('aria-selected', 'true');
        $tabContent
            .addClass('active')
            .removeAttr('hidden');

        localStorage.setItem('imgpress_active_tab', tab);
    }

    // Tab switching for settings page
    $('.imgpress-tab-button').on('click', function (e) {
        e.preventDefault();
        var tab = $(this).data('tab');

        activateTab(tab);
    });

    // Restore active tab on page load
    $(document).ready(function () {
        var activeTab = localStorage.getItem('imgpress_active_tab') || 'compression';

        activateTab(activeTab);
    });

    // Quality slider value display
    $('#ip_quality').on('input', function () {
        $('#ip-quality-val').text(this.value);
    });

})(jQuery);
