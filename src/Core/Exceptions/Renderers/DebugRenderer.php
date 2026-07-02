<?php

declare(strict_types=1);

namespace Slenix\Core\Exceptions\Renderers;

use Slenix\Core\Exceptions\Contracts\ExceptionRenderer;
use Slenix\Core\Exceptions\Concerns\CodeInspector;
use Slenix\Core\Exceptions\Concerns\StackTraceParser;
use Slenix\Core\Exceptions\Concerns\ExceptionContext;
use Slenix\Core\Exceptions\Pages\DebugPageAssets;

class DebugRenderer implements ExceptionRenderer
{
    public function __construct(
        private readonly CodeInspector    $inspector = new CodeInspector(),
        private readonly StackTraceParser $tracer    = new StackTraceParser(),
        private readonly ExceptionContext $context   = new ExceptionContext(),
    ) {}

    public function canRender(\Throwable $exception): bool
    {
        return true;
    }

    public function render(\Throwable $exception): string
    {
        $appName   = $this->esc($this->appName());
        $appVer    = $this->esc($this->appVersion());
        $phpVer    = $this->esc(PHP_VERSION);
        $exClass   = get_class($exception);
        $message   = $this->esc($exception->getMessage());
        $file      = $exception->getFile();
        $line      = $exception->getLine();
        $shortFile = $this->esc($this->inspector->shortenPath($file));

        // Short class name for title
        $parts     = explode('\\', $exClass);
        $shortName = $this->esc(array_pop($parts));
        $exClassE  = $this->esc($exClass);

        // HTTP method + URL
        $method    = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $methodCls = 'ign-method ign-method-' . strtolower($method);
        $url       = ($_SERVER['REQUEST_SCHEME'] ?? 'http')
            . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
            . ($_SERVER['REQUEST_URI'] ?? '/');
        $urlE      = $this->esc($url);

        // Status code badge class
        $statusCode = $exception instanceof \Slenix\Core\Exceptions\SlenixException
            ? $exception->getStatusCode() : 500;

        // Source code
        $sourceRows = $this->inspector->buildRows($file, $line);

        // Stack frames
        $frames     = $this->tracer->parse($exception);
        $framesHtml = $this->buildFramesHtml($frames);

        // Context
        $contextHtml = $this->buildContextHtml($this->context->collect());

        // Date
        $date = date('Y/m/d H:i:s.') . substr(microtime(), 2, 3) . ' UTC';

        // Method badge class for overview
        $ovMethodCls = $method === 'GET' ? 'ign-ov-badge ign-ov-get' : 'ign-ov-badge ign-ov-post';

        $css = DebugPageAssets::css();
        $js  = DebugPageAssets::js();

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="shortcut icon" href="/logo.svg" type="image/x-icon">
    <title>{$shortName} — {$appName}</title>
    {$css}
</head>
<body>

<!-- ── Top bar ─────────────────────────────────────────────── -->
<div class="ign-topbar">
    <div class="ign-topbar-icon">!</div>
    <span class="ign-topbar-title">Internal Server Error</span>
    <button class="ign-topbar-copy">⎘ Copy as Markdown</button>
</div>

<div class="ign-shell">

<!-- ══════════════════════════════════════════════════════════
     LEFT PANEL
════════════════════════════════════════════════════════════ -->
<div class="ign-left">

    <!-- Error heading -->
    <div class="ign-error-type">Error</div>
    <h1 class="ign-error-title">{$shortName}</h1>
    <p class="ign-error-message">{$message}</p>

    <!-- Meta badges -->
    <div class="ign-meta-row">
        <span class="ign-badge">{$appName} <strong>{$appVer}</strong></span>
        <span class="ign-badge">PHP <strong>{$phpVer}</strong></span>
        <span class="ign-badge ign-badge-red">▲ UNHANDLED</span>
        <span class="ign-badge ign-badge-code">CODE {$statusCode}</span>
    </div>

    <!-- Request URL bar -->
    <div class="ign-request-bar">
        <span class="{$methodCls}">{$method}</span>
        <span class="ign-url">{$urlE}</span>
        <button class="ign-url-copy" title="Copy URL">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
            </svg>
        </button>
    </div>

    <!-- Overview -->
    <div class="ign-section-lbl">Overview</div>
    <table class="ign-overview">
        <tr>
            <td>DATE</td>
            <td><span style="font-family:var(--mono);font-size:.78rem;color:var(--text2)">{$date}</span></td>
        </tr>
        <tr>
            <td>STATUS CODE</td>
            <td><span class="ign-ov-badge ign-ov-500">▲ {$statusCode}</span></td>
        </tr>
        <tr>
            <td>METHOD</td>
            <td><span class="{$ovMethodCls}">⊕ {$method}</span></td>
        </tr>
        <tr>
            <td>EXCEPTION</td>
            <td><span style="font-family:var(--mono);font-size:.73rem;color:var(--text2)">{$exClassE}</span></td>
        </tr>
    </table>

    <!-- Exception trace / source -->
    <div class="ign-trace-card">
        <div class="ign-trace-header">
            <div class="ign-trace-icon">▲</div>
            <span class="ign-trace-label">Exception trace</span>
        </div>

        <div class="ign-frame-file">
            <span>{$shortFile}</span>
            <div style="display:flex;align-items:center;gap:.4rem">
                <span>{$shortFile}:{$line}</span>
                <button class="ign-frame-close">✕</button>
            </div>
        </div>

        <div class="ign-code-body">{$sourceRows}</div>
    </div>

</div><!-- /ign-left -->

<!-- ══════════════════════════════════════════════════════════
     RIGHT PANEL
════════════════════════════════════════════════════════════ -->
<div class="ign-right">
    <div class="ign-tabs">
        <button class="ign-tab active" data-panel="ign-panel-trace">Stack trace</button>
        <button class="ign-tab" data-panel="ign-panel-request">Request</button>
        <button class="ign-tab" data-panel="ign-panel-context">Context</button>
    </div>

    <div id="ign-panel-trace" class="ign-tab-panel active">
        <div class="frame-list">{$framesHtml}</div>
    </div>

    <div id="ign-panel-request" class="ign-tab-panel">
        {$contextHtml}
    </div>

    <div id="ign-panel-context" class="ign-tab-panel">
        <div class="ctx-group">
            <div class="ctx-group-title">PHP</div>
            <div class="ctx-row"><span class="ctx-key">version</span><span class="ctx-val">{$phpVer}</span></div>
            <div class="ctx-row"><span class="ctx-key">sapi</span><span class="ctx-val"><?= PHP_SAPI ?></span></div>
            <div class="ctx-row"><span class="ctx-key">os</span><span class="ctx-val"><?= PHP_OS_FAMILY ?></span></div>
            <div class="ctx-row"><span class="ctx-key">memory_limit</span><span class="ctx-val"><?= ini_get('memory_limit') ?></span></div>
            <div class="ctx-row"><span class="ctx-key">max_execution_time</span><span class="ctx-val"><?= ini_get('max_execution_time') ?>s</span></div>
        </div>
        <div class="ctx-group">
            <div class="ctx-group-title">Application</div>
            <div class="ctx-row"><span class="ctx-key">name</span><span class="ctx-val">{$appName}</span></div>
            <div class="ctx-row"><span class="ctx-key">version</span><span class="ctx-val">{$appVer}</span></div>
            <div class="ctx-row"><span class="ctx-key">debug</span><span class="ctx-val">true</span></div>
            <div class="ctx-row"><span class="ctx-key">timezone</span><span class="ctx-val"><?= date_default_timezone_get() ?></span></div>
        </div>
    </div>
</div><!-- /ign-right -->

</div><!-- /ign-shell -->

{$js}
</body>
</html>
HTML;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function buildFramesHtml(array $frames): string
    {
        if (empty($frames)) {
            return '<p class="ctx-empty">No stack trace available.</p>';
        }

        $html = '';

        foreach ($frames as $frame) {
            $isApp    = $frame['is_app'];
            $cls      = 'frame-item' . ($isApp ? ' frame-app' : ' frame-vendor');

            $call = $frame['class']
                ? $this->esc($frame['class']) . '<span style="color:var(--muted)">::</span>' . $this->esc($frame['function'])
                : $this->esc($frame['function']);

            $args = $frame['args']
                ? '<span style="color:var(--muted)">(' . $this->esc($frame['args']) . ')</span>'
                : '<span style="color:var(--muted)">()</span>';

            $loc = $frame['line']
                ? $this->esc($frame['short_file']) . '<span class="frame-line-no">:' . $frame['line'] . '</span>'
                : '<span style="color:var(--dim)">' . $this->esc($frame['short_file']) . '</span>';

            $html .= <<<FRAME
<div class="{$cls}">
    <div style="display:flex;align-items:flex-start;gap:.3rem">
        <span class="frame-dot"></span>
        <span class="frame-fn-name">{$call}{$args}</span>
    </div>
    <div class="frame-file-loc">{$loc}</div>
</div>
FRAME;
        }

        return $html;
    }

    private function buildContextHtml(array $groups): string
    {
        $html = '';

        foreach ($groups as $groupName => $rows) {
            $html .= '<div class="ctx-group">';
            $html .= '<div class="ctx-group-title">' . $this->esc($groupName) . '</div>';

            if (empty($rows)) {
                $html .= '<div class="ctx-empty">Empty</div>';
            } else {
                foreach ($rows as $key => $value) {
                    $html .= '<div class="ctx-row">'
                        . '<span class="ctx-key">' . $this->esc($key) . '</span>'
                        . '<span class="ctx-val">' . $this->esc($value) . '</span>'
                        . '</div>';
                }
            }

            $html .= '</div>';
        }

        return $html;
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function appName(): string
    {
        if (function_exists('env')) return (string) env('APP_NAME', 'Slenix');
        return $_ENV['APP_NAME'] ?? $_SERVER['APP_NAME'] ?? 'Slenix';
    }

    private function appVersion(): string
    {
        if (function_exists('env')) return (string) env('APP_VERSION', '3.0');
        return $_ENV['APP_VERSION'] ?? '3.0';
    }
}