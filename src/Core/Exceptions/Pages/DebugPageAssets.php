<?php

declare(strict_types=1);

namespace Slenix\Core\Exceptions\Pages;

class DebugPageAssets
{
    public static function css(): string
    {
        return <<<'CSS'
<style>
@import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap');
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --bg-body:      #0a0a0c;
    --bg-card:      #18181b;
    --bg-code:      #111113;
    --border-color: #27272a;
    --text-main:    #ffffff;
    --text-muted:   #a1a1aa;
    --accent:       #ff2d55;
    --accent-bg:    rgba(255,45,85,.1);
    --accent-border:rgba(255,45,85,.3);
    --mono: ui-monospace,"Cascadia Code","Fira Code","JetBrains Mono",Menlo,monospace;
    --sans: "Poppins", sans-serif;
}

[data-theme="light"] {
    --bg-body:      #f4f4f5;
    --bg-card:      #ffffff;
    --bg-code:      #0a0a0c;
    --border-color: #e4e4e7;
    --text-main:    #18181b;
    --text-muted:   #71717a;
    --accent:       #ff2d55;
    --accent-bg:    #fff0f3;
    --accent-border:#ffd2dd;
}

html, body {
    background: var(--bg-body);
    font-family: var(--sans);
    font-size: 14px;
    color: var(--text-main);
    transition: background .2s ease;
}

.err-overlay {
    min-height: 100vh;
    display: flex;
    align-items: flex-start;
    justify-content: center;
    padding: 3rem 1rem;
}

.err-card {
    width: 100%;
    max-width: 860px;
    border-radius: 12px;
    overflow: hidden;
}

/* ── Top bar ─────────────────────────────────────── */
.err-topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: .85rem 1.25rem;
    border-bottom: 1px solid var(--border-color);
}

.err-nav { display: flex; align-items: center; gap: .5rem; }

.err-nav-btn {
    width: 28px; height: 28px;
    border-radius: 6px;
    border: 1px solid var(--accent-border);
    background: var(--accent-bg);
    color: var(--accent);
    font-size: .85rem;
    cursor: not-allowed;
    opacity: .6;
}

.err-nav-label {
    font-size: .8rem;
    color: var(--text-muted);
    margin-left: .35rem;
}

.err-topbar-right { display: flex; align-items: center; gap: .5rem; }

.err-icon-btn {
    width: 28px; height: 28px;
    display: flex; align-items: center; justify-content: center;
    background: none;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    color: var(--text-muted);
    cursor: pointer;
    transition: color .15s, border-color .15s;
}
.err-icon-btn:hover { color: var(--text-main); border-color: var(--text-muted); }
.err-icon-btn svg { width: 15px; height: 15px; fill: currentColor; }

/* ── Body ────────────────────────────────────────── */
.err-body { padding: 1.5rem 1.75rem 2rem; }

.err-title {
    font-size: 1.25rem;
    font-weight: 700;
    letter-spacing: -.02em;
    margin-bottom: .85rem;
}

.err-message {
    font-family: var(--sans);
    font-size: .82rem;
    font-weight: 500;
    color: var(--accent);
    background: var(--accent-bg);
    border: 1px solid var(--accent-border);
    border-left: 3px solid var(--accent);
    border-radius: 6px;
    padding: .75rem .9rem;
    margin-bottom: 1.75rem;
    word-break: break-word;
    line-height: 1.55;
}

.err-section-title {
    font-size: 1rem;
    font-weight: 600;
    margin: 1.5rem 0 .75rem;
}
.err-section-title:first-of-type { margin-top: 0; }

/* Source */
.err-source {
    background: var(--bg-code);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    overflow: hidden;
}

.err-source-file {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: .6rem .9rem;
    font-family: var(--mono);
    font-size: .75rem;
    color: #b8b8b8;
    border-bottom: 1px solid rgba(255,255,255,.08);
}

.err-code { padding: .3rem 0; overflow-x: auto; }

.code-row {
    display: flex;
    align-items: stretch;
    font-family: var(--mono);
    font-size: .8rem;
    line-height: 1.85;
}

.code-row.row-error { background: var(--accent-bg); }

.col-arrow {
    width: 22px;
    flex-shrink: 0;
    text-align: center;
    color: var(--accent);
    font-weight: 700;
}

.col-ln {
    width: 40px;
    flex-shrink: 0;
    text-align: right;
    padding-right: 1rem;
    color: #5a5a5a;
    user-select: none;
    font-size: .75rem;
}

.row-error .col-ln { color: var(--accent); }

.col-code { flex: 1; padding-right: 1.5rem; white-space: pre; color: #e2e8f0; }

.hl-kw      { color: #ff79c6; }
.hl-fn      { color: #67b7a4; }
.hl-var     { color: #41a1c0; }
.hl-string  { color: #f1fa8c; }
.hl-num     { color: #bd93f9; }
.hl-comment { color: #6c7986; font-style: italic; }
.hl-type    { color: #5dd8ff; }

/* Call stack */
.err-stack { display: flex; flex-direction: column; }

.frame-row {
    padding: .65rem 0;
    border-bottom: 1px solid var(--border-color);
}
.frame-row:last-child { border-bottom: none; }

.frame-fn {
    font-family: var(--mono);
    font-size: .82rem;
    font-weight: 600;
    color: var(--text-main);
}

.frame-vendor .frame-fn { color: var(--text-muted); font-weight: 400; }

.frame-loc {
    font-family: var(--mono);
    font-size: .74rem;
    color: var(--text-muted);
    margin-top: .2rem;
}

.err-collapsed-toggle {
    background: none;
    border: none;
    color: var(--accent);
    font-size: .8rem;
    font-weight: 500;
    cursor: pointer;
    padding: .75rem 0 0;
    font-family: var(--sans);
}
.err-collapsed-toggle:hover { text-decoration: underline; }

.frame-row.is-collapsed { display: none; }
.frame-row.is-collapsed.show { display: block; }
</style>
CSS;
    }

    public static function js(): string
    {
        return <<<'JS'
<script>
(function () {
    var STORAGE_KEY = 'slenix_debug_theme';
    var root = document.documentElement;
    var moon = '<path d="M12 3a9 9 0 1 0 9 9c0-.46-.04-.92-.1-1.36a5.389 5.389 0 0 1-4.4 2.26 5.403 5.403 0 0 1-5.3-5.3c0-1.97 1.06-3.69 2.64-4.64C13.41 3.05 12.71 3 12 3z"/>';
    var sun = '<path d="M12 7c-2.76 0-5 2.24-5 5s2.24 5 5 5 5-2.24 5-5-2.24-5-5-5zM2 13h2c.55 0 1-.45 1-1s-.45-1-1-1H2c-.55 0-1 .45-1 1s.45 1 1 1zm18 0h2c.55 0 1-.45 1-1s-.45-1-1-1h-2c-.55 0-1 .45-1 1s.45 1 1 1zM11 2v2c0 .55.45 1 1 1s1-.45 1-1V2c0-.55-.45-1-1-1s-1 .45-1 1zm0 18v2c0 .55.45 1 1 1s1-.45 1-1v-2c0-.55-.45-1-1-1s-1 .45-1 1zM5.99 4.58a.996.996 0 0 0-1.41 0 .996.996 0 0 0 0 1.41l1.06 1.06c.39.39 1.03.39 1.41 0s.39-1.03 0-1.41L5.99 4.58zm12.37 12.37a.996.996 0 0 0-1.41 0 .996.996 0 0 0 0 1.41l1.06 1.06c.39.39 1.03.39 1.41 0s.39-1.03 0-1.41l-1.06-1.06zm-12.37 1.41a.996.996 0 0 0 1.41 0c.39-.39.39-1.03 0-1.41l-1.06-1.06a.996.996 0 0 0-1.41 0 .996.996 0 0 0 0 1.41l1.06 1.06zM18.36 5.64a.996.996 0 0 0 1.41-1.41l-1.06-1.06a.996.996 0 0 0-1.41 1.41l1.06 1.06z"/>';

    function getMode() {
        var saved = localStorage.getItem(STORAGE_KEY);
        if (saved === 'light' || saved === 'dark') return saved;
        return window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
    }

    var mode = getMode();
    root.setAttribute('data-theme', mode);

    var icon = document.querySelector('.err-theme-icon');
    function paintIcon() {
        if (!icon) return;
        icon.innerHTML = root.getAttribute('data-theme') === 'light' ? moon : sun;
    }
    paintIcon();

    document.querySelector('.err-theme-toggle')?.addEventListener('click', function () {
        var next = root.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
        root.setAttribute('data-theme', next);
        localStorage.setItem(STORAGE_KEY, next);
        paintIcon();
    });

    document.querySelector('.err-close')?.addEventListener('click', function () {
        document.querySelector('.err-overlay')?.remove();
    });

    var toggle = document.querySelector('.err-collapsed-toggle');
    toggle?.addEventListener('click', function () {
        var hidden = document.querySelectorAll('.frame-row.is-collapsed');
        var willShow = !hidden[0]?.classList.contains('show');
        hidden.forEach(function (row) { row.classList.toggle('show', willShow); });
        toggle.textContent = willShow ? 'Hide collapsed frames' : 'Show collapsed frames';
    });
})();
</script>
JS;
    }
}