(function ($) {
    'use strict';

    function compactButton(classes, label, id) {
        return '<button type="button" class="button ip-compact-btn ' + classes + '" data-id="' + id + '"' +
            ' aria-label="' + label + '" title="' + label + '">' + label + '</button>';
    }

    function setButtonBusy($button, label) {
        $button
            .data('originalLabel', $button.attr('aria-label'))
            .data('originalHtml', $button.html())
            .prop('disabled', true)
            .attr('aria-busy', 'true')
            .attr({ 'aria-label': label, title: label })
            .addClass('is-busy')
            .text('…');
    }

    function restoreButton($button) {
        $button
            .prop('disabled', false)
            .removeAttr('aria-busy')
            .attr({
                'aria-label': $button.data('originalLabel'),
                title: $button.data('originalLabel')
            })
            .removeClass('is-busy')
            .html($button.data('originalHtml'));
    }

    // ── Media Library: single compress button ─────────────────────────────────

    $(document).on('click', '.ip-compress-btn', function () {
        var $btn    = $(this);
        var $result = $btn.siblings('.ip-compress-result');
        var id      = $btn.data('id');

        setButtonBusy($btn, 'Compressing…');

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
                    '</span>' +
                    (s.canRestore ? compactButton('ip-restore-btn', 'Restore original', id) + '<span class="ip-restore-result" role="status" aria-live="polite"></span>' : '')
                );
                var $nextControl = $result.find('.ip-restore-btn');
                if ($nextControl.length) {
                    $nextControl.trigger('focus');
                } else {
                    $result.attr('tabindex', '-1').trigger('focus');
                }
            } else {
                restoreButton($btn);
                $result.html('<span class="ip-err">Failed</span>');
            }
        }).fail(function () {
            restoreButton($btn);
            $result.html('<span class="ip-err">Request failed</span>');
        });
    });

    // ── Media Library: restore original button ───────────────────────────────

    $(document).on('click', '.ip-restore-btn', function () {
        var $btn    = $(this);
        var $result = $btn.siblings('.ip-restore-result');
        var id      = $btn.data('id');

        if (!confirm('Restore the original media file?\n\nThe optimized file will be replaced locally.')) {
            return;
        }

        setButtonBusy($btn, 'Restoring…');
        $result.text('');

        $.post(ImgPressAdmin.ajaxUrl, {
            action:      'imgpress_restore_original',
            _ajax_nonce: ImgPressAdmin.nonce,
            id:          id,
        }, function (res) {
            if (res.success) {
                $result.html('<span class="ip-ok">Restored</span>');
                $result.attr('tabindex', '-1').trigger('focus');
                window.setTimeout(function () { window.location.reload(); }, 500);
            } else {
                restoreButton($btn);
                $result.html('<span class="ip-err">Restore failed</span>');
            }
        }).fail(function () {
            restoreButton($btn);
            $result.html('<span class="ip-err">Request failed</span>');
        });
    });

    // ── Media Library: R2 push button ────────────────────────────────────────

    $(document).on('click', '.ip-r2-push-btn', function () {
        var $btn    = $(this);
        var $result = $btn.siblings('.ip-r2-result');
        var $block  = $btn.closest('.ip-r2-block');
        var id      = $btn.data('id');

        setButtonBusy($btn, 'Uploading…');

        $.post(ImgPressAdmin.ajaxUrl, {
            action:      'imgpress_r2_push',
            _ajax_nonce: ImgPressAdmin.r2Nonce,
            id:          id,
        }, function (res) {
            if (res.success) {
                var s = res.data;
                if (s.url) {
                    var domain = new URL(s.url).hostname;
                    $block.html(
                        '<span class="ip-badge ip-r2-badge">R2 ✓</span>' +
                        '<a href="' + $('<div>').text(s.url).html() + '" target="_blank" class="ip-r2-link">' +
                            $('<div>').text(domain).html() +
                        '</a>' +
                        compactButton('ip-r2-btn ip-r2-remove-btn', 'Remove R2', id) +
                        '<span class="ip-r2-result" role="status" aria-live="polite"></span>'
                    );
                } else {
                    $block.html(
                        '<span class="ip-badge ip-r2-badge">R2 ✓</span>' +
                        '<span class="ip-r2-link">No public URL</span>' +
                        compactButton('ip-r2-btn ip-r2-remove-btn', 'Remove R2', id) +
                        '<span class="ip-r2-result" role="status" aria-live="polite"></span>'
                    );
                }
                $block.find('.ip-r2-remove-btn').trigger('focus');
            } else {
                restoreButton($btn);
                $result.html('<span class="ip-err">Upload failed</span>');
            }
        }).fail(function () {
            restoreButton($btn);
            $result.html('<span class="ip-err">Request failed</span>');
        });
    });

    // ── Media Library: R2 remove button ──────────────────────────────────────

    $(document).on('click', '.ip-r2-remove-btn', function () {
        var $btn    = $(this);
        var $block  = $btn.closest('.ip-r2-block');
        var $result = $block.find('.ip-r2-result');
        var id      = $btn.data('id');

        if (!confirm('Remove this file from R2?\n\nLocal file will be kept.')) {
            return;
        }

        setButtonBusy($btn, 'Removing…');

        $.post(ImgPressAdmin.ajaxUrl, {
            action:      'imgpress_r2_remove',
            _ajax_nonce: ImgPressAdmin.r2Nonce,
            id:          id,
        }, function (res) {
            if (res.success) {
                $block.html(
                    compactButton('ip-r2-btn ip-r2-push-btn', 'Offload R2', id) +
                    '<span class="ip-r2-result" role="status" aria-live="polite"></span>'
                );
                $block.find('.ip-r2-push-btn').trigger('focus');
            } else {
                restoreButton($btn);
                $result.html('<span class="ip-err">Remove failed</span>');
            }
        }).fail(function () {
            restoreButton($btn);
            $result.html('<span class="ip-err">Request failed</span>');
        });
    });

    // ── Media Library: selected-item bulk actions ─────────────────────────────

    $('#posts-filter').on('submit', function (event) {
        var $form = $(this);
        var operation = $('#bulk-action-selector-top').val();
        if (operation === '-1') {
            operation = $('#bulk-action-selector-bottom').val();
        }

        if (['imgpress_compress', 'imgpress_restore_original', 'imgpress_r2_offload'].indexOf(operation) === -1) {
            return;
        }

        if ($form.data('imgpressBulkProcessing')) {
            event.preventDefault();
            event.stopImmediatePropagation();
            return;
        }

        var ids = $('input[name="media[]"]:checked').map(function () {
            return parseInt(this.value, 10);
        }).get().filter(Boolean);

        if (!ids.length) {
            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();
        $form.data('imgpressBulkProcessing', true);

        var counts = { succeeded: 0, skipped: 0, failed: 0 };
        var $applyButtons = $('#doaction, #doaction2').prop('disabled', true);
        $('#bulk-action-selector-top, #bulk-action-selector-bottom, input[name="media[]"]').prop('disabled', true);
        var index = 0;

        function processNext() {
            if (index >= ids.length) {
                var url = new URL(window.location.href);
                url.searchParams.set('imgpress_bulk_action', operation);
                url.searchParams.set('imgpress_succeeded', counts.succeeded);
                url.searchParams.set('imgpress_skipped', counts.skipped);
                url.searchParams.set('imgpress_failed', counts.failed);
                url.searchParams.delete('imgpress_js_required');
                window.location.href = url.toString();
                return;
            }

            $.post(ImgPressAdmin.ajaxUrl, {
                action: 'imgpress_media_bulk_item',
                _ajax_nonce: ImgPressAdmin.bulkNonce,
                operation: operation,
                id: ids[index]
            }).done(function (response) {
                var result = response.success && response.data ? response.data.result : 'failed';
                if (!Object.prototype.hasOwnProperty.call(counts, result)) {
                    result = 'failed';
                }
                counts[result]++;
            }).fail(function () {
                counts.failed++;
            }).always(function () {
                index++;
                $applyButtons.val('Processing ' + index + '/' + ids.length + '…');
                processNext();
            });
        }

        processNext();
    });

    function formatBytes(b) {
        if (b >= 1048576) return (b / 1048576).toFixed(1) + ' MB';
        return (b / 1024).toFixed(1) + ' KB';
    }

})(jQuery);
