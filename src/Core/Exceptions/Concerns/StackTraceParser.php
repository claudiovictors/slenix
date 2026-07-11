<?php

/*
|--------------------------------------------------------------------------
| StackTraceParser — Slenix Framework
|--------------------------------------------------------------------------
|
| Parses a Throwable's stack trace into structured frames, ready to be
| rendered by the debug error page. Also collapses vendor frames to reduce
| noise, mirroring the way Ignition and Whoops handle long traces.
|
*/

declare(strict_types=1);

namespace Slenix\Core\Exceptions\Concerns;

class StackTraceParser
{
    /**
     * Parses the trace of a Throwable into a structured array.
     *
     * Each frame contains:
     *   - file       (string)  Absolute path (or '[internal function]')
     *   - short_file (string)  Display-friendly path
     *   - line       (int)     Line number (0 if unavailable)
     *   - class      (string)  Class name or empty string
     *   - function   (string)  Function/method name
     *   - args       (string)  Comma-separated arg type list
     *   - is_vendor  (bool)    Whether the frame lives inside /vendor/
     *   - is_app     (bool)    Whether the frame is user-land code
     *
     * @param  \Throwable $exception
     * @return array<int, array<string, mixed>>
     */
    public function parse(\Throwable $exception): array
    {
        $trace  = $exception->getTrace();
        $frames = [];

        foreach ($trace as $frame) {
            $file     = $frame['file']     ?? '[internal function]';
            $line     = $frame['line']     ?? 0;
            $class    = $frame['class']    ?? '';
            $function = $frame['function'] ?? '{closure}';
            $args     = $frame['args']     ?? [];

            $isVendor = str_contains(str_replace('\\', '/', $file), '/vendor/');
            $isApp    = !$isVendor && $file !== '[internal function]';

            $frames[] = [
                'file'       => $file,
                'short_file' => $this->shorten($file),
                'line'       => $line,
                'class'      => $class,
                'function'   => $function,
                'args'       => $this->formatArgs($args),
                'is_vendor'  => $isVendor,
                'is_app'     => $isApp,
            ];
        }

        return $frames;
    }

    /**
     * Builds an HTML list of stack frames for the debug error page.
     *
     * Vendor frames are collapsed and shown at reduced opacity.
     * App frames are highlighted and shown expanded.
     *
     * @param  array<int, array<string, mixed>> $frames Output of parse().
     * @return string
     */
    public function buildHtml(array $frames): string
    {
        if (empty($frames)) {
            return '<p class="muted" style="padding:1rem">No stack trace available.</p>';
        }

        $html = '';

        foreach ($frames as $i => $frame) {
            $vendorCls = $frame['is_vendor'] ? ' frame-vendor' : '';
            $appCls    = $frame['is_app']    ? ' frame-app'    : '';
            $cls       = "frame{$vendorCls}{$appCls}";

            $call = $frame['class']
                ? htmlspecialchars($frame['class'] . '::' . $frame['function'], ENT_QUOTES, 'UTF-8')
                : htmlspecialchars($frame['function'], ENT_QUOTES, 'UTF-8');

            $location = $frame['line']
                ? htmlspecialchars($frame['short_file'], ENT_QUOTES, 'UTF-8')
                  . '<span class="frame-line">:' . $frame['line'] . '</span>'
                : '<span class="muted">' . htmlspecialchars($frame['short_file'], ENT_QUOTES, 'UTF-8') . '</span>';

            $args = $frame['args']
                ? '<span class="frame-args">(' . htmlspecialchars($frame['args'], ENT_QUOTES, 'UTF-8') . ')</span>'
                : '<span class="muted">()</span>';

            $html .= <<<HTML
            <div class="{$cls}" data-frame="{$i}">
                <div class="frame-fn">{$call}{$args}</div>
                <div class="frame-loc">{$location}</div>
            </div>
            HTML;
        }

        return $html;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Shortens an absolute path for compact display.
     */
    private function shorten(string $path): string
    {
        if ($path === '[internal function]') {
            return $path;
        }

        $path = str_replace('\\', '/', $path);
        $root = defined('ROOT_PATH')
            ? rtrim(str_replace('\\', '/', ROOT_PATH), '/') . '/'
            : rtrim(str_replace('\\', '/', dirname(__DIR__, 4)), '/') . '/';

        if (str_starts_with($path, $root)) {
            return ltrim(substr($path, strlen($root)), '/');
        }

        // Collapse vendor paths: keep only vendor/package/path
        if (preg_match('#/vendor/([^/]+/[^/]+/.+)$#', $path, $m)) {
            return 'vendor/' . $m[1];
        }

        $parts = explode('/', $path);

        return implode('/', array_slice($parts, -4));
    }

    /**
     * Converts raw frame args to a human-readable type list.
     *
     * @param  array<mixed> $args
     */
    private function formatArgs(array $args): string
    {
        $types = array_map(function (mixed $arg): string {
            return match (true) {
                is_null($arg)    => 'null',
                is_bool($arg)    => $arg ? 'true' : 'false',
                is_int($arg)     => (string) $arg,
                is_float($arg)   => (string) $arg,
                is_string($arg)  => '"' . (strlen($arg) > 20 ? substr($arg, 0, 20) . '…' : $arg) . '"',
                is_array($arg)   => 'array(' . count($arg) . ')',
                is_object($arg)  => get_class($arg),
                default          => gettype($arg),
            };
        }, $args);

        return implode(', ', $types);
    }
}