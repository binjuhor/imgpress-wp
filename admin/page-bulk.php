<?php defined('ABSPATH') || exit; ?>
<div class="wrap ip-wrap">
    <div class="ip-header">
        <svg class="ip-logo" viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg">
            <rect width="40" height="40" rx="8" fill="#00ff88" fill-opacity="0.12"/>
            <path d="M12 28L20 12L28 28" stroke="#00ff88" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
            <path d="M15 22H25" stroke="#00ff88" stroke-width="2.5" stroke-linecap="round"/>
        </svg>
        <div>
            <h1 class="ip-title">Bulk Compress</h1>
            <p class="ip-subtitle">Compress all unoptimized media in your library</p>
        </div>
    </div>

    <div class="ip-card" id="ip-bulk-start-card">
        <div class="ip-stats-row">
            <div class="ip-stat">
                <span class="ip-stat__val" id="ip-uncompressed-count">—</span>
                <span class="ip-stat__label">Uncompressed files</span>
            </div>
            <div class="ip-stat">
                <span class="ip-stat__val" id="ip-done-count">0</span>
                <span class="ip-stat__label">Compressed this run</span>
            </div>
            <div class="ip-stat">
                <span class="ip-stat__val ip-stat__val--accent" id="ip-saved-total">0 KB</span>
                <span class="ip-stat__label">Total saved</span>
            </div>
            <div class="ip-stat">
                <span class="ip-stat__val ip-stat__val--accent" id="ip-avg-ratio">—</span>
                <span class="ip-stat__label">Avg reduction</span>
            </div>
        </div>

        <div class="ip-progress-wrap" id="ip-progress-wrap" style="display:none">
            <div class="ip-progress">
                <div class="ip-progress__bar" id="ip-progress-bar" style="width:0%"></div>
            </div>
            <span class="ip-progress__label" id="ip-progress-label">Starting…</span>
        </div>

        <div class="ip-actions">
            <button id="ip-bulk-btn" class="ip-btn ip-btn--primary" disabled>
                Start Bulk Compress
            </button>
            <span class="ip-bulk-status" id="ip-bulk-status"></span>
        </div>
    </div>

    <div class="ip-card" id="ip-results-card" style="display:none">
        <h2 class="ip-card__title">Results</h2>
        <table class="ip-table">
            <thead>
                <tr>
                    <th>File</th>
                    <th>Before</th>
                    <th>After</th>
                    <th>Saved</th>
                </tr>
            </thead>
            <tbody id="ip-results-tbody"></tbody>
        </table>
    </div>
</div>

<script>
(function($) {
    var ids       = [];
    var done      = 0;
    var totalSaved = 0;
    var ratios    = [];
    var running   = false;

    function formatBytes(b) {
        if (b >= 1048576) return (b / 1048576).toFixed(1) + ' MB';
        return (b / 1024).toFixed(1) + ' KB';
    }

    // Fetch uncompressed IDs on load
    $.post(ImgPressAdmin.ajaxUrl, {
        action: 'imgpress_bulk_get_ids',
        _ajax_nonce: ImgPressAdmin.nonce
    }, function(res) {
        if (!res.success) return;
        ids = res.data.ids;
        $('#ip-uncompressed-count').text(ids.length);
        if (ids.length > 0) {
            $('#ip-bulk-btn').prop('disabled', false);
        } else {
            $('#ip-bulk-status').text('All media is already compressed.');
        }
    });

    $('#ip-bulk-btn').on('click', function() {
        if (running) return;
        running = true;
        done    = 0;
        totalSaved = 0;
        ratios  = [];

        $(this).prop('disabled', true).text('Running…');
        $('#ip-progress-wrap').show();
        $('#ip-results-card').show();
        $('#ip-results-tbody').empty();

        processNext();
    });

    function processNext() {
        if (done >= ids.length) {
            finish();
            return;
        }

        var id    = ids[done];
        var pct   = Math.round((done / ids.length) * 100);

        $('#ip-progress-bar').css('width', pct + '%');
        $('#ip-progress-label').text('Processing ' + (done + 1) + ' of ' + ids.length + '…');

        $.post(ImgPressAdmin.ajaxUrl, {
            action: 'imgpress_bulk_compress',
            _ajax_nonce: ImgPressAdmin.nonce,
            id: id
        }, function(res) {
            done++;
            $('#ip-done-count').text(done);

            if (res.success) {
                var s    = res.data.stats;
                var name = res.data.name;
                var saved = s.originalSize - s.compressedSize;
                totalSaved += saved;
                ratios.push(s.ratio);

                var tier = s.ratio >= 60 ? 'high' : (s.ratio >= 30 ? 'mid' : 'low');
                $('#ip-results-tbody').append(
                    '<tr>' +
                    '<td class="ip-file">' + $('<div>').text(name).html() + '</td>' +
                    '<td>' + formatBytes(s.originalSize) + '</td>' +
                    '<td>' + formatBytes(s.compressedSize) + '</td>' +
                    '<td><span class="ip-badge ip-badge--' + tier + '">−' + s.ratio.toFixed(1) + '%</span></td>' +
                    '</tr>'
                );

                $('#ip-saved-total').text(formatBytes(totalSaved));
                var avg = ratios.reduce(function(a, b) { return a + b; }, 0) / ratios.length;
                $('#ip-avg-ratio').text(avg.toFixed(1) + '%');
            } else {
                var name = (res.data && res.data.name) ? res.data.name : '#' + id;
                $('#ip-results-tbody').append(
                    '<tr class="ip-row--error">' +
                    '<td class="ip-file">' + $('<div>').text(name).html() + '</td>' +
                    '<td colspan="3"><span class="ip-err">Failed</span></td>' +
                    '</tr>'
                );
            }

            processNext();
        }).fail(function() {
            done++;
            processNext();
        });
    }

    function finish() {
        running = false;
        $('#ip-progress-bar').css('width', '100%');
        $('#ip-progress-label').text('Done — ' + done + ' files processed.');
        $('#ip-bulk-btn').prop('disabled', false).text('Run Again');
        $('#ip-bulk-status').text('');
    }
})(jQuery);
</script>
