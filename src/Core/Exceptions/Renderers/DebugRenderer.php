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
        $exClass   = get_class($exception);
        $message   = $this->esc($exception->getMessage());
        $file      = $exception->getFile();
        $line      = $exception->getLine();
        $shortFile = $this->esc($this->inspector->shortenPath($file));

        $parts     = explode('\\', $exClass);
        $shortName = $this->esc(array_pop($parts));
        $exClassE  = $this->esc($exClass);

        $sourceRows = $this->inspector->buildRows($file, $line);

        $frames = $this->tracer->parse($exception);
        //[$framesHtml, $collapsedCount] = $this->buildFramesHtml($frames);

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

<div class="err-overlay">
  <div class="err-card">

    <div class="err-topbar">
        <div class="err-nav">
            <button class="err-nav-btn" disabled>←</button>
            <button class="err-nav-btn" disabled>→</button>
            <span class="err-nav-label">1 of 1 error</span>
        </div>
        <div class="err-topbar-right">
            <button class="err-icon-btn err-theme-toggle" title="Toggle theme">
                <svg class="err-theme-icon" viewBox="0 0 24 24"></svg>
            </button>
            <button class="err-icon-btn err-close" title="Close">✕</button>
        </div>
    </div>

    <div class="err-body">
        <h1 class="err-title">Unhandled Runtime Error</h1>
        <p class="err-message">{$exClassE}: {$message}</p>

        <h2 class="err-section-title">Source</h2>
        <div class="err-source">
            <div class="err-source-file">
                <span>{$shortFile}:{$line}</span>
            </div>
            <div class="err-code">{$sourceRows}</div>
        </div>

        
    </div>

  </div>
</div>

{$js}
</body>
</html>
HTML;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Builds the call stack list. App frames render normally; vendor/framework
     * frames are marked as collapsible and hidden by default (toggle in JS).
     *
     * @return array{0: string, 1: int} [html, collapsedCount]
     */
    private function buildFramesHtml(array $frames): array
    {
        if (empty($frames)) {
            return ['<p class="frame-loc">No stack trace available.</p>', 0];
        }

        $html = '';
        $collapsed = 0;

        foreach ($frames as $frame) {
            $isApp = $frame['is_app'];
            $rowCls = 'frame-row' . ($isApp ? '' : ' frame-vendor is-collapsed');

            if (!$isApp) {
                $collapsed++;
            }

            $call = $frame['class']
                ? $this->esc($frame['class']) . '::' . $this->esc($frame['function'])
                : $this->esc($frame['function']);

            $loc = $frame['line']
                ? $this->esc($frame['short_file']) . ':' . $frame['line']
                : $this->esc($frame['short_file']);

            $html .= <<<FRAME
<div class="{$rowCls}">
    <div class="frame-fn">{$call}</div>
    <div class="frame-loc">{$loc}</div>
</div>
FRAME;
        }

        return [$html, $collapsed];
    }

    private function collapsedToggle(int $count): string
    {
        if ($count === 0) {
            return '';
        }

        return '<button class="err-collapsed-toggle">Show collapsed frames</button>';
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