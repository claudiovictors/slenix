<?php

/*
|--------------------------------------------------------------------------
| CodeInspector — Slenix Framework
|--------------------------------------------------------------------------
|
| Responsible for extracting source code snippets around an error line
| and applying lightweight syntax highlighting for the debug error page.
|
| Kept separate so it can be unit-tested and swapped independently.
|
*/

declare(strict_types=1);

namespace Slenix\Core\Exceptions\Concerns;

class CodeInspector
{
    /**
     * PHP keywords that receive keyword highlighting.
     *
     * @var string[]
     */
    private static array $keywords = [
        'function', 'return', 'if', 'else', 'elseif', 'foreach', 'while',
        'for', 'do', 'switch', 'case', 'break', 'continue', 'try', 'catch',
        'finally', 'throw', 'new', 'class', 'interface', 'trait', 'extends',
        'implements', 'namespace', 'use', 'public', 'private', 'protected',
        'static', 'abstract', 'final', 'readonly', 'match', 'fn', 'echo',
        'print', 'require', 'include', 'require_once', 'include_once',
        'true', 'false', 'null', 'self', 'parent', 'declare', 'default', 'void',
    ];

    /**
     * Extracts lines from a source file centred on $errorLine.
     *
     * @param  string $file      Absolute path to the PHP source file.
     * @param  int    $errorLine 1-based line number of the error.
     * @param  int    $context   Lines to show before and after the error line.
     * @return array{lines: array<array{number:int,code:string,is_error:bool}>, start_line:int}
     */
    public function extract(string $file, int $errorLine, int $context = 7): array
    {
        if (!is_readable($file)) {
            return ['lines' => [], 'start_line' => 0];
        }

        $allLines  = file($file, FILE_IGNORE_NEW_LINES) ?: [];
        $startLine = max(1, $errorLine - $context);
        $endLine   = min(count($allLines), $errorLine + $context);
        $snippet   = [];

        for ($i = $startLine - 1; $i < $endLine; $i++) {
            if (!isset($allLines[$i])) {
                continue;
            }

            $snippet[] = [
                'number'   => $i + 1,
                'code'     => $allLines[$i],
                'is_error' => ($i + 1 === $errorLine),
            ];
        }

        return ['lines' => $snippet, 'start_line' => $startLine];
    }

    /**
     * Applies regex-based PHP syntax highlighting to a single line of code.
     * Returns HTML-safe string with <span> colour tags.
     *
     * @param  string $raw Raw source line (not yet HTML-escaped).
     * @return string
     */
    public function highlight(string $raw): string
    {
        $code = htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Comments
        $code = preg_replace(
            '/(\/\/[^\n]*)/',
            '<span class="hl-comment">$1</span>',
            $code
        ) ?? $code;

        // Strings (double and single quoted, HTML-entity-aware)
        $code = preg_replace(
            '/(&quot;(?:[^&]|&(?!quot;))*&quot;|&#039;(?:[^&]|&(?!#039;))*&#039;)/U',
            '<span class="hl-string">$1</span>',
            $code
        ) ?? $code;

        // Keywords
        $kw = implode('|', self::$keywords);
        $code = preg_replace(
            '/\b(' . $kw . ')\b(?![^<]*<\/span>)/',
            '<span class="hl-kw">$1</span>',
            $code
        ) ?? $code;

        // Variables
        $code = preg_replace(
            '/(\$[a-zA-Z_]\w*)(?![^<]*<\/span>)/',
            '<span class="hl-var">$1</span>',
            $code
        ) ?? $code;

        // Function calls
        $code = preg_replace(
            '/\b([a-zA-Z_]\w*)\s*(?=\()(?![^<]*<\/span>)/',
            '<span class="hl-fn">$1</span>',
            $code
        ) ?? $code;

        // Numeric literals
        $code = preg_replace(
            '/\b(\d+\.?\d*)\b(?![^<]*<\/span>)/',
            '<span class="hl-num">$1</span>',
            $code
        ) ?? $code;

        return $code;
    }

    /**
     * Builds HTML rows for the code viewer panel.
     *
     * @param  string $file    Source file path.
     * @param  int    $line    Error line number.
     * @param  int    $context Lines of context.
     * @return string          Ready-to-embed HTML.
     */
    public function buildRows(string $file, int $line, int $context = 7): string
    {
        $snippet = $this->extract($file, $line, $context);
        $html    = '';

        foreach ($snippet['lines'] as $ln) {
            $isErr   = $ln['is_error'];
            $rowCls  = $isErr ? ' row-error' : '';
            $arrow   = $isErr
                ? '<span class="col-arrow"><svg width="10" height="10" viewBox="0 0 10 10" fill="none"><path d="M2 1L7 5L2 9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>'
                : '<span class="col-arrow"></span>';

            $code    = $this->highlight($ln['code']);
            $num     = $ln['number'];

            $html .= "<div class=\"code-row{$rowCls}\">"
                . $arrow
                . "<span class=\"col-ln\">{$num}</span>"
                . "<span class=\"col-code\">{$code}</span>"
                . "</div>";
        }

        return $html;
    }

    /**
     * Shortens an absolute file path for compact display.
     * Shows up to the last 4 path segments relative to ROOT_PATH.
     *
     * @param  string $path Absolute filesystem path.
     * @return string
     */
    public function shortenPath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $root = defined('ROOT_PATH')
            ? rtrim(str_replace('\\', '/', ROOT_PATH), '/') . '/'
            : rtrim(str_replace('\\', '/', dirname(__DIR__, 4)), '/') . '/';

        if (str_starts_with($path, $root)) {
            return ltrim(substr($path, strlen($root)), '/');
        }

        $parts = explode('/', $path);

        return implode('/', array_slice($parts, -4));
    }
}