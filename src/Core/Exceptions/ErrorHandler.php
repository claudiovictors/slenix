<?php

/*
|--------------------------------------------------------------------------
| ErrorHandler Class — Slenix Framework
|--------------------------------------------------------------------------
|
| This class manages centralized error and exception handling for the framework.
| It uses the Request object to detect context (API vs. Browser) and the 
| Response object to format and send the appropriate error response.
|
| The debug error page layout is inspired by Next.js's dark mode overlay.
|
*/

declare(strict_types=1);

namespace Slenix\Core\Exceptions;

use Slenix\Http\Request;
use Slenix\Http\Response;
use Slenix\Supports\Logging\Log;

class ErrorHandler
{
    /** @var bool|null Internal cache for debug mode status (lazy loaded). */
    private ?bool $debugCache = null;

    /** @var array<int, string> Mapping of PHP error levels to human-readable strings. */
    private static array $errorTypes = [
        E_ERROR             => 'Fatal Error',
        E_WARNING           => 'Warning',
        E_PARSE             => 'Parse Error',
        E_NOTICE            => 'Notice',
        E_CORE_ERROR        => 'Core Error',
        E_CORE_WARNING      => 'Core Warning',
        E_COMPILE_ERROR     => 'Compile Error',
        E_COMPILE_WARNING   => 'Compile Warning',
        E_USER_ERROR        => 'User Error',
        E_USER_WARNING      => 'User Warning',
        E_USER_NOTICE       => 'User Notice',
        E_STRICT            => 'Strict Standards',
        E_RECOVERABLE_ERROR => 'Recoverable Error',
        E_DEPRECATED        => 'Deprecated',
        E_USER_DEPRECATED   => 'User Deprecated',
    ];

    /**
     * ErrorHandler constructor.
     * Note: APP_DEBUG is not read here as .env might not be loaded yet.
     */
    public function __construct()
    {
    }

    /**
     * Lazy-resolves the APP_DEBUG status.
     * * @return bool
     */
    private function isDebug(): bool
    {
        if ($this->debugCache !== null) {
            return $this->debugCache;
        }

        if (function_exists('env')) {
            $val = env('APP_DEBUG', false);
        } else {
            // Robust fallback if helper is unavailable
            $val = $_ENV['APP_DEBUG']
                ?? $_SERVER['APP_DEBUG']
                ?? getenv('APP_DEBUG')
                ?? false;
        }

        $this->debugCache = filter_var($val, FILTER_VALIDATE_BOOLEAN);

        return $this->debugCache;
    }

    /**
     * Converts PHP errors into ErrorExceptions for uniform handling.
     * * @param int $severity
     * @param string $message
     * @param string $file
     * @param int $line
     * @return bool
     * @throws \ErrorException
     */
    public function handleError(int $severity, string $message, string $file, int $line): bool
    {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        throw new \ErrorException($message, 0, $severity, $file, $line);
    }

    /**
     * Handles uncaught exceptions and sends the appropriate response.
     * * @param \Throwable $exception
     * @return void
     */
    public function handleException(\Throwable $exception): void
    {
        $statusCode = $this->resolveStatusCode($exception);
        $errorData = $this->buildErrorData($exception, $statusCode);

        $this->logException($exception);

        $request = new Request();
        $response = new Response();

        $response->withoutCache();

        if ($this->isApiRequest($request)) {
            $response->status($statusCode)->json($errorData);
        } else {
            $response->status($statusCode)->html(
                $this->renderErrorPage($exception)
            );
        }
    }

    /**
     * Handles critical environment configuration errors.
     * * @param \Exception $exception
     * @return null
     */
    public function handleEnvError(\Exception $exception)
    {
        $response = new Response();
        $response->status(500)->json([
            'error'   => 'Configuration Error',
            'message' => $exception->getMessage(),
        ]);

        exit(1);
    }

    /**
     * Determines if the request context is API-based.
     * * @param Request $request
     * @return bool
     */
    private function isApiRequest(Request $request): bool
    {
        return $request->isJson()
            || $request->expectsJson()
            || $request->isAjax()
            || str_starts_with($request->uri(), '/api/');
    }

    /**
     * Resolves HTTP status code based on exception type.
     * * @param \Throwable $exception
     * @return int
     */
    private function resolveStatusCode(\Throwable $exception): int
    {
        return match (true) {
            $exception instanceof \InvalidArgumentException => 400,
            $exception instanceof \RuntimeException         => 500,
            default                                          => 500,
        };
    }

    /**
     * Builds the error data array for JSON responses.
     * * @param \Throwable $exception
     * @param int $statusCode
     * @return array
     */
    private function buildErrorData(\Throwable $exception, int $statusCode): array
    {
        $base = [
            'success'     => false,
            'error'       => true,
            'status_code' => $statusCode,
            'message'     => $this->isDebug()
                ? $exception->getMessage()
                : 'An unexpected error occurred.',
        ];

        if ($this->isDebug()) {
            $base['debug'] = [
                'exception' => get_class($exception),
                'file'      => $exception->getFile(),
                'line'      => $exception->getLine(),
                'trace'     => $exception->getTraceAsString(),
            ];
        }

        return $base;
    }

    /**
     * Logs the exception details to the system log.
     * * @param \Throwable $exception
     * @return void
     */
    private function logException(\Throwable $exception): void
    {
        Log::error(sprintf(
            "[%s] [%s] %s in %s:%d\n%s",
            date('Y-m-d H:i:s'),
            get_class($exception),
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            $exception->getTraceAsString()
        ));
    }

    /**
     * Extracts a code snippet around the error line.
     * * @param string $file
     * @param int $errorLine
     * @param int $context Number of lines to show before/after.
     * @return array
     */
    private function getCodeSnippet(string $file, int $errorLine, int $context = 6): array
    {
        if (!is_readable($file)) {
            return ['lines' => [], 'start_line' => 0];
        }

        $allLines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
        $startLine = max(1, $errorLine - $context);
        $endLine = min(count($allLines), $errorLine + $context);
        $snippet = [];

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
     * Applies PHP syntax highlighting via regex.
     * * @param string $raw
     * @return string
     */
    private function highlight(string $raw): string
    {
        $code = htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Comments
        $code = preg_replace('/(\/\/[^\n]*)/', '<span class="hl-comment">$1</span>', $code);

        // Strings
        $code = preg_replace(
            '/(&quot;(?:[^&]|&(?!quot;))*&quot;|&#039;(?:[^&]|&(?!#039;))*&#039;)/U',
            '<span class="hl-string">$1</span>',
            $code
        );

        // Keywords
        $keywords = [
            'function', 'return', 'if', 'else', 'elseif', 'foreach', 'while', 'for', 'do', 
            'switch', 'case', 'break', 'continue', 'try', 'catch', 'finally', 'throw', 
            'new', 'class', 'interface', 'trait', 'extends', 'implements', 'namespace', 
            'use', 'public', 'private', 'protected', 'static', 'abstract', 'final', 
            'readonly', 'match', 'fn', 'echo', 'print', 'require', 'include', 
            'require_once', 'include_once', 'true', 'false', 'null', 'self', 'parent', 
            'declare', 'default', 'void',
        ];
        $code = preg_replace(
            '/\b(' . implode('|', $keywords) . ')\b(?![^<]*<\/span>)/',
            '<span class="hl-kw">$1</span>',
            $code
        );

        // Variables, Functions, and Numbers
        $code = preg_replace('/(\$[a-zA-Z_]\w*)(?![^<]*<\/span>)/', '<span class="hl-var">$1</span>', $code);
        $code = preg_replace('/\b([a-zA-Z_]\w*)\s*(?=\()(?![^<]*<\/span>)/', '<span class="hl-fn">$1</span>', $code);
        $code = preg_replace('/\b(\d+\.?\d*)\b(?![^<]*<\/span>)/', '<span class="hl-num">$1</span>', $code);

        return $code;
    }

    /**
     * Shortens a file path for cleaner display.
     * * @param string $path
     * @return string
     */
    private function shortenPath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $root = defined('ROOT_PATH')
            ? rtrim(str_replace('\\', '/', ROOT_PATH), '/') . '/'
            : rtrim(str_replace('\\', '/', dirname(__DIR__, 3)), '/') . '/';

        if (str_starts_with($path, $root)) {
            return ltrim(substr($path, strlen($root)), '/');
        }

        $parts = explode('/', $path);
        return implode('/', array_slice($parts, -3));
    }

    /**
     * Builds HTML rows for the code snippet view.
     * * @param string $file
     * @param int $line
     * @param int $context
     * @return string
     */
    private function buildCodeRows(string $file, int $line, int $context = 5): string
    {
        $snippet = $this->getCodeSnippet($file, $line, $context);
        $html = '';

        foreach ($snippet['lines'] as $ln) {
            $isErr = $ln['is_error'];
            $rowCls = $isErr ? ' row-error' : '';
            $arrow = $isErr ? '<span class="arrow-icon">&gt;</span>' : '<span class="arrow-icon"></span>';
            $code = $this->highlight($ln['code']);
            $num = $ln['number'];

            $html .= "<div class=\"code-row{$rowCls}\">"
                . "<span class=\"col-arrow\">{$arrow}</span>"
                . "<span class=\"col-ln\">{$num}</span>"
                . "<span class=\"col-code\">{$code}</span>"
                . "</div>";
        }

        return $html;
    }

    /**
     * Renders the visual error page or production fallback.
     * * @param \Throwable $exception
     * @return string
     */
    private function renderErrorPage(\Throwable $exception): string
    {
        if (!$this->isDebug()) {
            return $this->renderProductionPage();
        }

        $appName = htmlspecialchars(env('APP_NAME', 'Slenix'), ENT_QUOTES, 'UTF-8');
        $exClass = htmlspecialchars(get_class($exception), ENT_QUOTES, 'UTF-8');
        $message = htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8');
        $file    = $exception->getFile();
        $line    = $exception->getLine();
        $short   = htmlspecialchars($this->shortenPath($file), ENT_QUOTES, 'UTF-8');

        $sourceRows = $this->buildCodeRows($file, $line, 6);
        $rawTrace   = htmlspecialchars($exception->getTraceAsString(), ENT_QUOTES, 'UTF-8');

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <link rel="shortcut icon" href="/logo.svg" type="image/x-icon">
            <title>Unhandled Error — {$appName}</title>
            <style>
                *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
                :root {
                    --bg: #0a0a0a; --surface: #111; --surface2: #1a1a1a; --border: #262626;
                    --border2: #333; --red: #e5484d; --red-glow: rgba(229,72,77,.18);
                    --green: #3dd68c; --text: #ededed; --muted: #888; --dim: #555;
                    --mono: "Geist Mono","SFMono-Regular",Menlo,Consolas,monospace;
                    --sans: -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
                    --r: 6px; --kw: #ff79c6; --var: #f1fa8c; --fn: #50fa7b; --str: #8be9fd;
                    --num: #bd93f9; --cmt: #5a6272;
                }
                html,body { background:var(--bg); color:var(--text); font-family:var(--sans); font-size:14px; line-height:1.5; min-height:100vh; }
                .topbar { position:fixed; top:0; left:0; right:0; height:3px; background:var(--red); z-index:999; }
                .wrapper { max-width:900px; margin:0 auto; padding:3.5rem 1.5rem 5rem; }
                .nav { display:flex; align-items:center; justify-content:space-between; margin-bottom:1.5rem; font-size:12px; color:var(--muted); }
                .nav-left { display:flex; align-items:center; gap:.4rem; }
                .nav-btn { background:var(--surface2); border:1px solid var(--border2); color:var(--text); width:24px; height:24px; border-radius:var(--r); cursor:pointer; }
                .dot { width:8px; height:8px; background:var(--green); border-radius:50%; }
                .badge-ex { font-size:11px; font-weight:600; background:var(--red-glow); color:var(--red); border:1px solid rgba(229,72,77,.5); border-radius:4px; padding:1px 7px; font-family:var(--mono); }
                .err-heading { font-size:1.65rem; font-weight:700; color:var(--text); margin-bottom:.3rem; }
                .err-msg { font-family:var(--mono); font-size:.875rem; color:var(--red); margin-bottom:2rem; word-break:break-all; }
                .section-lbl { font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.1em; color:var(--muted); margin:2rem 0 .6rem; }
                .code-card { background:var(--surface); border:1px solid var(--border); border-radius:var(--r); overflow:hidden; }
                .code-card-hdr { display:flex; align-items:center; justify-content:space-between; padding:.55rem 1rem; border-bottom:1px solid var(--border); font-family:var(--mono); font-size:.75rem; color:var(--muted); }
                .code-row { display:flex; align-items:flex-start; font-family:var(--mono); font-size:.8rem; line-height:1.8; padding:0 .75rem; }
                .code-row.row-error { background:var(--red-glow); }
                .col-arrow { width:1.25rem; color:var(--red); font-weight:700; }
                .col-ln { width:2.25rem; text-align:right; padding-right:.75rem; color:var(--dim); }
                .col-code { flex:1; white-space:pre; }
                .hl-kw { color:var(--kw); } .hl-var { color:var(--var); } .hl-fn { color:var(--fn); } .hl-string { color:var(--str); } .hl-num { color:var(--num); } .hl-comment { color:var(--cmt); }
                .link-btn { background:none; border:none; color:var(--muted); font-size:.78rem; cursor:pointer; text-decoration:underline; }
                pre.raw { background:var(--surface); border:1px solid var(--border); border-radius:var(--r); padding:.9rem 1rem; font-family:var(--mono); font-size:.72rem; color:var(--muted); white-space:pre-wrap; margin-top:.4rem; }
            </style>
        </head>
        <body>
            <div class="topbar"></div>
            <div class="wrapper">
                <div class="nav">
                    <div class="nav-left">
                        <button class="nav-btn">&#8592;</button>
                        <button class="nav-btn">&#8594;</button>
                        <span>1 of 1 unhandled error</span>
                    </div>
                    <div class="nav-right">
                        <span class="dot"></span>
                        <span>{$appName} is running</span>
                        <span class="badge-ex">{$exClass}</span>
                    </div>
                </div>
                <h1 class="err-heading">Unhandled Runtime Error</h1>
                <div class="err-msg">Error: {$message}</div>
                <div class="section-lbl">Source</div>
                <div class="code-card">
                    <div class="code-card-hdr">
                        <span>{$short} ({$line})</span>
                    </div>
                    <div class="code-body" style="padding:.4rem 0; overflow-x:auto;">{$sourceRows}</div>
                </div>
                <button class="link-btn" style="margin-top:.75rem" onclick="var el=document.getElementById('rt'); el.style.display=el.style.display==='none'?'block':'none';">
                    Toggle raw trace
                </button>
                <pre id="rt" class="raw" style="display:none">{$rawTrace}</pre>
            </div>
        </body>
        </html>
        HTML;
    }

    /**
     * Production error page (generic 500).
     * * @return string
     */
    private function renderProductionPage(): string
    {
        $appName = htmlspecialchars(env('APP_NAME', 'Slenix'), ENT_QUOTES, 'UTF-8');

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <title>500 — {$appName}</title>
            <style>
                body{background:#0a0a0a;color:#ededed;font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;text-align:center}
                h1{font-size:5rem;color:#e5484d}
                p{color:#666}
            </style>
        </head>
        <body>
            <div>
                <h1>500</h1>
                <p>Internal Server Error &mdash; something went wrong.</p>
            </div>
        </body>
        </html>
        HTML;
    }
}