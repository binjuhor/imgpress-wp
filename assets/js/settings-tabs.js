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

    function formatSeconds(seconds) {
        seconds = parseInt(seconds, 10) || 0;

        var units = [
            { label: 'day', seconds: 86400 },
            { label: 'hour', seconds: 3600 },
            { label: 'minute', seconds: 60 }
        ];

        for (var i = 0; i < units.length; i++) {
            if (seconds >= units[i].seconds && seconds % units[i].seconds === 0) {
                var value = seconds / units[i].seconds;
                return value + ' ' + units[i].label + (value === 1 ? '' : 's');
            }
        }

        return seconds + ' seconds';
    }

    function updateCacheLifespan() {
        var $input = $('#ip_cache_lifespan');
        var $target = $('#ip-cache-lifespan-human');

        if (!$input.length || !$target.length) {
            return;
        }

        $target.text('(' + formatSeconds($input.val()) + ')');
    }

    $('#ip_cache_lifespan').on('input change', updateCacheLifespan);
    updateCacheLifespan();

})(jQuery);
