<?php

declare(strict_types=1);

namespace Slenix\Core\Exceptions\Pages;

class DebugPageAssets
{
    public static function css(): string
    {
        return <<<'CSS'
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --bg:         #1C1C1E;
    --bg2:        #252528;
    --bg3:        #2C2C2E;
    --border:     #3A3A3C;
    --border2:    #48484A;
    --red:        #FF453A;
    --red-bg:     rgba(255,69,58,.15);
    --red-line:   rgba(255,69,58,.35);
    --pink:       #FF2D55;
    --green:      #32D74B;
    --blue:       #0A84FF;
    --yellow:     #FFD60A;
    --orange:     #FF9F0A;
    --text:       #F5F5F7;
    --text2:      #AEAEB2;
    --muted:      #636366;
    --dim:        #48484A;
    --mono:       ui-monospace,"Cascadia Code","Fira Code","JetBrains Mono",Menlo,monospace;
    --sans:       -apple-system,BlinkMacSystemFont,"Inter","Segoe UI",sans-serif;
    --r:          8px;
    --r-sm:       5px;
    /* syntax */
    --s-kw:    #FC5FA3;
    --s-fn:    #67B7A4;
    --s-var:   #41A1C0;
    --s-str:   #FC6A5D;
    --s-num:   #9B8FDB;
    --s-cmt:   #6C7986;
    --s-type:  #5DD8FF;
}

html, body {
    background: var(--bg);
    color: var(--text);
    font-family: var(--sans);
    font-size: 14px;
    line-height: 1.5;
    min-height: 100vh;
}

/* ── Top bar ─────────────────────────────────────── */
.ign-topbar {
    height: 44px;
    background: var(--bg2);
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    padding: 0 1.25rem;
    gap: .6rem;
    position: sticky;
    top: 0;
    z-index: 50;
}

.ign-topbar-icon {
    width: 18px; height: 18px;
    background: var(--red);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 11px; font-weight: 700; color: #fff;
    flex-shrink: 0;
}

.ign-topbar-title {
    font-size: .82rem;
    font-weight: 600;
    color: var(--text);
}

.ign-topbar-copy {
    margin-left: auto;
    background: var(--bg3);
    border: 1px solid var(--border);
    color: var(--text2);
    font-size: .75rem;
    padding: .3rem .75rem;
    border-radius: var(--r-sm);
    cursor: pointer;
    display: flex; align-items: center; gap: .35rem;
    font-family: var(--sans);
    transition: color .15s, border-color .15s;
}
.ign-topbar-copy:hover { color: var(--text); border-color: var(--border2); }

/* ── Layout ──────────────────────────────────────── */
.ign-shell {
    display: grid;
    grid-template-columns: 1fr 380px;
    min-height: calc(100vh - 44px);
}

/* ── Left panel ──────────────────────────────────── */
.ign-left {
    padding: 2rem 2.25rem;
    border-right: 1px solid var(--border);
    overflow-y: auto;
}

/* Error heading */
.ign-error-type {
    font-size: .75rem;
    font-weight: 600;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: .08em;
    margin-bottom: .35rem;
}

.ign-error-title {
    font-size: 1.75rem;
    font-weight: 700;
    color: var(--text);
    letter-spacing: -.025em;
    line-height: 1.2;
    margin-bottom: .5rem;
}

.ign-error-message {
    font-size: .875rem;
    color: var(--text2);
    line-height: 1.6;
    margin-bottom: 1.5rem;
    word-break: break-word;
}

/* Meta badges row */
.ign-meta-row {
    display: flex;
    align-items: center;
    gap: .4rem;
    flex-wrap: wrap;
    margin-bottom: 1.75rem;
    padding-bottom: 1.75rem;
    border-bottom: 1px solid var(--border);
}

.ign-badge {
    display: inline-flex;
    align-items: center;
    gap: .25rem;
    font-size: .72rem;
    font-family: var(--mono);
    background: var(--bg3);
    border: 1px solid var(--border);
    border-radius: var(--r-sm);
    padding: .2rem .55rem;
    color: var(--text2);
    white-space: nowrap;
}

.ign-badge strong { color: var(--text); font-weight: 600; }

.ign-badge-red {
    background: var(--red-bg);
    border-color: var(--red-line);
    color: var(--red);
    font-weight: 600;
}

.ign-badge-code {
    background: var(--red-bg);
    border-color: var(--red-line);
    color: var(--red);
}

/* Request URL bar */
.ign-request-bar {
    display: flex;
    align-items: center;
    gap: .6rem;
    background: var(--bg2);
    border: 1px solid var(--border);
    border-radius: var(--r);
    padding: .55rem .75rem;
    margin-bottom: 2rem;
}

.ign-method {
    font-size: .72rem;
    font-weight: 700;
    font-family: var(--mono);
    background: var(--red);
    color: #fff;
    border-radius: var(--r-sm);
    padding: .15rem .5rem;
    flex-shrink: 0;
}

.ign-method-get  { background: #0A84FF; }
.ign-method-post { background: var(--green); color: #000; }
.ign-method-put, .ign-method-patch { background: var(--orange); color: #000; }
.ign-method-delete { background: var(--red); }

.ign-url {
    font-family: var(--mono);
    font-size: .8rem;
    color: var(--text2);
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.ign-url-copy {
    background: none;
    border: 1px solid var(--border);
    border-radius: var(--r-sm);
    color: var(--muted);
    width: 26px; height: 26px;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; flex-shrink: 0;
    transition: color .15s, border-color .15s;
}
.ign-url-copy:hover { color: var(--text2); border-color: var(--border2); }

/* Section label */
.ign-section-lbl {
    font-size: .78rem;
    font-weight: 600;
    color: var(--text);
    margin-bottom: .75rem;
    margin-top: 2rem;
}
.ign-section-lbl:first-of-type { margin-top: 0; }

/* Overview table */
.ign-overview {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 2rem;
}

.ign-overview tr {
    border-bottom: 1px dotted var(--border);
}

.ign-overview td {
    padding: .6rem .25rem;
    font-size: .78rem;
    vertical-align: middle;
}

.ign-overview td:first-child {
    font-size: .7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: var(--muted);
    width: 120px;
    padding-right: 1rem;
}

.ign-overview td:last-child { text-align: right; }

.ign-ov-badge {
    display: inline-flex;
    align-items: center;
    gap: .3rem;
    font-size: .73rem;
    font-weight: 700;
    font-family: var(--mono);
    padding: .2rem .55rem;
    border-radius: var(--r-sm);
}

.ign-ov-500 { background: var(--red-bg); border: 1px solid var(--red-line); color: var(--red); }
.ign-ov-get { background: rgba(10,132,255,.15); border: 1px solid rgba(10,132,255,.3); color: var(--blue); }
.ign-ov-post { background: rgba(50,215,75,.12); border: 1px solid rgba(50,215,75,.3); color: var(--green); }

/* Exception trace / source */
.ign-trace-card {
    background: var(--bg2);
    border: 1px solid var(--border);
    border-radius: var(--r);
    overflow: hidden;
}

.ign-trace-header {
    display: flex;
    align-items: center;
    gap: .5rem;
    padding: .65rem 1rem;
    border-bottom: 1px solid var(--border);
    background: var(--bg3);
}

.ign-trace-icon {
    width: 16px; height: 16px;
    background: var(--orange);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 9px; font-weight: 700; color: #fff;
    flex-shrink: 0;
}

.ign-trace-label {
    font-size: .78rem;
    font-weight: 600;
    color: var(--text);
}

/* Frame file header */
.ign-frame-file {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: .5rem .9rem;
    border-bottom: 1px solid var(--border);
    background: var(--bg2);
}

.ign-frame-file span:first-child {
    font-family: var(--mono);
    font-size: .73rem;
    color: var(--text2);
}

.ign-frame-file span:last-child {
    font-family: var(--mono);
    font-size: .73rem;
    color: var(--muted);
    background: var(--bg3);
    border: 1px solid var(--border);
    border-radius: 3px;
    padding: 1px 6px;
}

.ign-frame-close {
    background: none;
    border: 1px solid var(--border);
    color: var(--muted);
    width: 20px; height: 20px;
    border-radius: 3px;
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 11px;
    margin-left: .5rem;
}

/* Code viewer */
.ign-code-body {
    overflow-x: auto;
    padding: .3rem 0;
}

.code-row {
    display: flex;
    align-items: stretch;
    font-family: var(--mono);
    font-size: .78rem;
    line-height: 1.9;
    padding: 0;
}

.code-row.row-error {
    background: var(--red-bg);
}

.col-arrow {
    width: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--red);
    font-weight: 700;
    font-size: .75rem;
    flex-shrink: 0;
}

.col-ln {
    width: 42px;
    text-align: right;
    padding-right: 1.1rem;
    color: var(--muted);
    user-select: none;
    flex-shrink: 0;
    font-size: .73rem;
}

.row-error .col-ln { color: var(--red); }

.col-code {
    flex: 1;
    padding-right: 1.5rem;
    white-space: pre;
}

/* Syntax highlight */
.hl-kw     { color: var(--s-kw); }
.hl-fn     { color: var(--s-fn); }
.hl-var    { color: var(--s-var); }
.hl-string { color: var(--s-str); }
.hl-num    { color: var(--s-num); }
.hl-comment{ color: var(--s-cmt); font-style: italic; }
.hl-type   { color: var(--s-type); }

/* ── Right panel ─────────────────────────────────── */
.ign-right {
    background: var(--bg2);
    overflow-y: auto;
    border-left: 1px solid var(--border);
}

/* Tabs */
.ign-tabs {
    display: flex;
    border-bottom: 1px solid var(--border);
    padding: 0 1rem;
    background: var(--bg2);
    position: sticky;
    top: 0;
    z-index: 10;
}

.ign-tab {
    background: none;
    border: none;
    border-bottom: 2px solid transparent;
    color: var(--muted);
    font-size: .78rem;
    font-weight: 500;
    padding: .7rem .5rem .65rem;
    margin-right: .75rem;
    cursor: pointer;
    font-family: var(--sans);
    transition: color .15s, border-color .15s;
    white-space: nowrap;
}

.ign-tab:hover { color: var(--text2); }
.ign-tab.active { color: var(--text); border-bottom-color: var(--text); }

.ign-tab-panel { display: none; padding: 1rem; }
.ign-tab-panel.active { display: block; }

/* Stack frames list */
.frame-list { display: flex; flex-direction: column; gap: 1px; }

.frame-item {
    padding: .6rem .65rem;
    border-radius: var(--r-sm);
    cursor: pointer;
    border: 1px solid transparent;
    transition: background .1s, border-color .1s;
    position: relative;
}

.frame-item:hover,
.frame-item.active {
    background: var(--bg3);
    border-color: var(--border);
}

.frame-item::before {
    content: '';
    position: absolute;
    left: 0; top: 50%;
    transform: translateY(-50%);
    width: 3px; height: 0;
    background: var(--blue);
    border-radius: 0 2px 2px 0;
    transition: height .15s;
}

.frame-item.active::before { height: 60%; }

.frame-dot {
    width: 7px; height: 7px;
    border-radius: 50%;
    background: var(--muted);
    display: inline-block;
    margin-right: .4rem;
    flex-shrink: 0;
    vertical-align: middle;
    position: relative;
    top: -1px;
}

.frame-app .frame-dot { background: var(--blue); }

.frame-fn-name {
    font-family: var(--mono);
    font-size: .73rem;
    color: var(--text);
    display: inline;
    word-break: break-all;
}

.frame-vendor .frame-fn-name { color: var(--muted); }

.frame-file-loc {
    font-family: var(--mono);
    font-size: .68rem;
    color: var(--muted);
    margin-top: 2px;
    padding-left: 1.1rem;
}

.frame-line-no { color: var(--blue); }

/* Context table */
.ctx-group { margin-bottom: 1.25rem; }

.ctx-group-title {
    font-size: .68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .09em;
    color: var(--muted);
    padding: .25rem 0 .5rem;
    border-bottom: 1px solid var(--border);
    margin-bottom: .4rem;
}

.ctx-row {
    display: flex;
    gap: .75rem;
    padding: .3rem 0;
    border-bottom: 1px solid var(--border);
    font-size: .73rem;
}

.ctx-key {
    font-family: var(--mono);
    color: var(--text2);
    min-width: 110px;
    max-width: 110px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    flex-shrink: 0;
}

.ctx-val {
    font-family: var(--mono);
    color: var(--muted);
    word-break: break-all;
    flex: 1;
}

.ctx-empty {
    font-size: .73rem;
    color: var(--dim);
    font-style: italic;
    padding: .25rem 0;
}

/* Scrollbar */
::-webkit-scrollbar { width: 5px; height: 5px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--border2); border-radius: 3px; }

/* Mobile */
@media (max-width: 860px) {
    .ign-shell { grid-template-columns: 1fr; }
    .ign-right { border-left: none; border-top: 1px solid var(--border); }
}
</style>
CSS;
    }

    public static function js(): string
    {
        return <<<'JS'
<script>
(function () {
    // Tab switching (right panel)
    document.querySelectorAll('.ign-tab').forEach(tab => {
        tab.addEventListener('click', () => {
            const panel = tab.dataset.panel;
            tab.closest('.ign-right').querySelectorAll('.ign-tab').forEach(t => t.classList.remove('active'));
            tab.closest('.ign-right').querySelectorAll('.ign-tab-panel').forEach(p => p.classList.remove('active'));
            tab.classList.add('active');
            document.getElementById(panel)?.classList.add('active');
        });
    });

    // Copy URL button
    document.querySelectorAll('.ign-url-copy').forEach(btn => {
        btn.addEventListener('click', () => {
            const url = btn.closest('.ign-request-bar')?.querySelector('.ign-url')?.textContent ?? '';
            navigator.clipboard?.writeText(url);
        });
    });

    // Copy as Markdown (top bar)
    document.querySelector('.ign-topbar-copy')?.addEventListener('click', () => {
        const title  = document.querySelector('.ign-error-title')?.textContent ?? '';
        const msg    = document.querySelector('.ign-error-message')?.textContent ?? '';
        const code   = Array.from(document.querySelectorAll('.col-code')).map(el => el.textContent).join('\n');
        const md = `## ${title}\n\n> ${msg}\n\n\`\`\`php\n${code.trim()}\n\`\`\``;
        navigator.clipboard?.writeText(md).then(() => {
            const btn = document.querySelector('.ign-topbar-copy');
            if (btn) { btn.textContent = '✓ Copied!'; setTimeout(() => btn.textContent = '⎘ Copy as Markdown', 1600); }
        });
    });
})();
</script>
JS;
    }
}