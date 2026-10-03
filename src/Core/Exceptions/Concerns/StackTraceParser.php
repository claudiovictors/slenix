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
     * @param CodeInspector $inspector Reused for path shortening, so both
     *                                 the source header and the call stack
     *                                 display paths the same way.
     */
    public function __construct(
        private readonly CodeInspector $inspector = new CodeInspector(),
    ) {}
    
     /**
     * Parses the trace of a Throwable into structured frames.
     *
     * Each frame contains:
     *   - file       (string) Absolute path, or '[internal function]'.
     *   - short_file (string) Display-friendly path.
     *   - line       (int)    Line number, 0 if unavailable.
     *   - class      (string) Class name, or '' for plain functions.
     *   - function   (string) Function / method name ('{closure}' if unknown).
     *   - is_vendor  (bool)   Frame lives inside /vendor/.
     *   - is_app     (bool)   Frame is user-land code (not vendor, not internal).
     *
     * @param  \Throwable $exception The exception whose trace is parsed.
     * @return array<int, array{file: string, short_file: string, line: int, class: string, function: string, is_vendor: bool, is_app: bool}>
     */
    public function parse(\Throwable $exception): array
    {
        $frames = [];
 
        foreach ($exception->getTrace() as $frame) {
            $file     = $frame['file']     ?? '[internal function]';
            $function = $frame['function'] ?? '{closure}';
 
            $isVendor = str_contains(str_replace('\\', '/', $file), '/vendor/');
            $isApp    = !$isVendor && $file !== '[internal function]';
 
            $frames[] = [
                'file'       => $file,
                'short_file' => $this->shorten($file),
                'line'       => $frame['line']  ?? 0,
                'class'      => $frame['class'] ?? '',
                'function'   => $function,
                'is_vendor'  => $isVendor,
                'is_app'     => $isApp,
            ];
        }
 
        return $frames;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Shortens an absolute path for compact display.
     *
     * Delegates to {@see CodeInspector::shortenPath()} so there is a single
     * implementation of the "relative to project root" logic.
     *
     * @param  string $path Absolute path, or '[internal function]'.
     * @return string       Shortened path, or the placeholder unchanged.
     */
    private function shorten(string $path): string
    {
        return $path === '[internal function]'
            ? $path
            : $this->inspector->shortenPath($path);
    }
}