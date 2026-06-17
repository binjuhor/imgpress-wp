(function ($) {
    'use strict';

    var TabCarousel = {
        init: function () {
            this.$container = $('.imgpress-tabs-nav');
            this.$buttons = $('.imgpress-tab-button');
            this.tabWidth = 0;
            this.visibleTabs = 2;

            this.setupCarousel();
            this.bindEvents();
            this.restoreActiveTab();
        },

        setupCarousel: function () {
            // Create carousel wrapper if needed
            if (!this.$container.find('.imgpress-tabs-wrapper').length) {
                var $wrapper = $('<div class="imgpress-tabs-wrapper"></div>');
                this.$buttons.each(function () {
                    $wrapper.append($(this));
                });
                this.$container.append($wrapper);

                // Add navigation arrows
                this.$container.prepend('<button class="imgpress-tabs-prev imgpress-tabs-arrow" aria-label="Previous tabs"><span class="dashicons dashicons-arrow-left-alt"></span></button>');
                this.$container.append('<button class="imgpress-tabs-next imgpress-tabs-arrow" aria-label="Next tabs"><span class="dashicons dashicons-arrow-right-alt"></span></button>');
            }

            this.$wrapper = this.$container.find('.imgpress-tabs-wrapper');
            this.$buttons = this.$wrapper.find('.imgpress-tab-button');
            this.updateCarousel();
        },

        bindEvents: function () {
            var self = this;

            // Tab button click
            this.$buttons.on('click', function (e) {
                e.preventDefault();
                var tab = $(this).data('tab');
                self.selectTab(tab, $(this));
            });

            // Arrow navigation
            this.$container.find('.imgpress-tabs-prev').on('click', function (e) {
                e.preventDefault();
                self.scrollPrev();
            });

            this.$container.find('.imgpress-tabs-next').on('click', function (e) {
                e.preventDefault();
                self.scrollNext();
            });

            // Save preference on form submit
            $('.imgpress-form').on('submit', function () {
                var activeTab = $('.imgpress-tab-button.active').data('tab');
                localStorage.setItem('imgpress_active_tab', activeTab);
            });
        },

        selectTab: function (tabName, $btn) {
            // Remove active from all tabs
            this.$buttons.removeClass('active');
            $('.imgpress-tab-content').removeClass('active').hide();

            // Add active to selected
            $btn.addClass('active');
            $('#' + tabName).addClass('active').show();

            // Ensure selected tab is visible in carousel
            this.ensureTabVisible($btn);

            // Save preference
            localStorage.setItem('imgpress_active_tab', tabName);
        },

        ensureTabVisible: function ($btn) {
            var btnOffset = $btn.position().left;
            var wrapperScroll = this.$wrapper.scrollLeft();
            var wrapperWidth = this.$wrapper.width();
            var btnWidth = $btn.outerWidth();

            // If button is out of view on the left
            if (btnOffset < 0) {
                this.$wrapper.scrollLeft(wrapperScroll + btnOffset - 10);
            }
            // If button is out of view on the right
            else if (btnOffset + btnWidth > wrapperWidth) {
                this.$wrapper.scrollLeft(wrapperScroll + (btnOffset + btnWidth - wrapperWidth + 10));
            }

            this.updateCarousel();
        },

        scrollPrev: function () {
            var currentScroll = this.$wrapper.scrollLeft();
            this.$wrapper.animate({ scrollLeft: Math.max(0, currentScroll - 150) }, 300);
            this.updateCarousel();
        },

        scrollNext: function () {
            var maxScroll = this.$wrapper[0].scrollWidth - this.$wrapper.width();
            var currentScroll = this.$wrapper.scrollLeft();
            this.$wrapper.animate({ scrollLeft: Math.min(maxScroll, currentScroll + 150) }, 300);
            this.updateCarousel();
        },

        updateCarousel: function () {
            var scroll = this.$wrapper.scrollLeft();
            var maxScroll = this.$wrapper[0].scrollWidth - this.$wrapper.width();

            // Toggle arrow visibility
            this.$container.find('.imgpress-tabs-prev').toggleClass('disabled', scroll <= 0);
            this.$container.find('.imgpress-tabs-next').toggleClass('disabled', scroll >= maxScroll - 5);
        },

        restoreActiveTab: function () {
            var activeTab = localStorage.getItem('imgpress_active_tab') || 'compression';
            var $tabBtn = this.$buttons.filter('[data-tab="' + activeTab + '"]');
            var $tabContent = $('#' + activeTab);

            if ($tabBtn.length && $tabContent.length) {
                this.selectTab(activeTab, $tabBtn);
            }
        }
    };

    // Initialize on page load
    $(document).ready(function () {
        TabCarousel.init();

        // Quality slider value display
        $('#ip_quality').on('input', function () {
            $('#ip-quality-val').text(this.value);
        });
    });

})(jQuery);
