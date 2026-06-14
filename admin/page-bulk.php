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
