<?php

/*
|--------------------------------------------------------------------------
| ErrorPage — Slenix Framework
|--------------------------------------------------------------------------
|
| Renders the user-facing HTTP error page (404, 500, 503, ...).
|
| Resolution order for a given status code:
|   1. Developer view:  views/{erros|errors|error|erro}/{code}.luna.php
|                       views/{erros|errors|error|erro}/{code}.php
|   2. Built-in default page (same layout as the old 404.php).
|
| If the developer view itself throws, the built-in page is used, so an
| error while rendering an error page can never loop.
|
*/

declare(strict_types=1);

namespace Slenix\Core\Exceptions;

final class ErrorPage
{
    /** Folder names searched inside /views, in order. */
    private const DIRS = ['erros', 'errors', 'error', 'erro'];

    /**
     * @var array<int, array{0: string, 1: string}> code => [heading, description]
     */
    private const MESSAGES = [
        400 => ['Bad Request.',           'The server could not understand the request.'],
        401 => ['Unauthorized.',          'You must be authenticated to access this resource.'],
        403 => ['Forbidden.',             'You do not have permission to access this resource.'],
        404 => ['This page could not be found.', 'The requested URL was not found on this server.'],
        405 => ['Method Not Allowed.',    'The HTTP method used is not supported for this route.'],
        419 => ['Session Expired.',       'Your session has expired. Please refresh the page and try again.'],
        422 => ['Unprocessable Content.', 'The request data could not be processed.'],
        429 => ['Too Many Requests.',     'Please slow down and try again later.'],
        500 => ['Internal Server Error.', 'The server encountered an internal error and could not complete your request.'],
        503 => ['Service Unavailable.',   'The service is temporarily unavailable. Please try again in a few minutes.'],
    ];

    public static function render(int $code): string
    {
        return self::fromView($code) ?? self::fallback($code);
    }

     /**
     * Returns the short, user-safe description for an HTTP status code.
     *
     * Shares the same message table used by the HTML page, so JSON and HTML
     * responses always agree on the wording. Unknown codes get a generic text.
     *
     * @param  int    $code HTTP status code (e.g. 404, 500).
     * @return string       Human-readable description, safe to expose in production.
     */
    public static function message(int $code): string
    {
        return self::MESSAGES[$code][1] ?? 'An unexpected error occurred.';
    }

    // -------------------------------------------------------------------------
    // Developer view
    // -------------------------------------------------------------------------

    private static function fromView(int $code): ?string
    {
        $root = defined('ROOT_PATH')
            ? rtrim(str_replace('\\', '/', ROOT_PATH), '/')
            : str_replace('\\', '/', dirname(__DIR__, 3));

        foreach (self::DIRS as $dir) {
            foreach (['.luna.php', '.php'] as $ext) {
                $path = "{$root}/views/{$dir}/{$code}{$ext}";

                if (!is_file($path)) {
                    continue;
                }

                try {
                    if ($ext === '.luna.php') {
                        // dot-notation view name, e.g. "erros.500"
                        return (string) view("{$dir}.{$code}", ['code' => $code]);
                    }

                    ob_start();
                    include $path;
                    return (string) ob_get_clean();
                } catch (\Throwable) {
                    if (ob_get_level() > 0) {
                        ob_end_clean();
                    }
                    return null; // fall back to the built-in page
                }
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Built-in page
    // -------------------------------------------------------------------------

    private static function fallback(int $code): string
    {
        [$heading, $text] = self::MESSAGES[$code]
            ?? ['Something went wrong.', 'An unexpected error occurred. Please try again or contact support.'];

        return <<<HTML
<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="shortcut icon" href="/logo.svg" type="image/x-icon">
    <title>{$code} - {$heading}</title>
    <style>
        :root {
            --bg: #0a0a0c;
            --text-main: #ffffff;
            --text-muted: #888888;
            --accent: #ff2d55;
        }

        body {
            margin: 0;
            padding: 0;
            background-color: var(--bg);
            color: var(--text-main);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            text-align: center;
        }

        .container { padding: 20px; }

        h1 {
            font-size: 24px;
            font-weight: 600;
            margin: 0;
            display: inline-block;
            border-right: 1px solid rgba(255, 255, 255, 0.3);
            padding: 10px 23px 10px 0;
            margin-right: 20px;
            vertical-align: middle;
        }

        .message-box {
            display: inline-block;
            text-align: left;
            vertical-align: middle;
        }

        h2 { font-size: 14px; font-weight: 400; margin: 0; line-height: 24px; }

        p { font-size: 12px; color: var(--text-muted); margin: 5px 0 0 0; }

        .back-link {
            margin-top: 30px;
            display: block;
            color: var(--accent);
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: opacity 0.2s;
        }

        .back-link:hover { opacity: 0.8; text-decoration: underline; }

        .brand {
            position: absolute;
            bottom: 30px;
            font-size: 10px;
            color: #222;
            letter-spacing: 2px;
            text-transform: uppercase;
        }
    </style>
</head>
<body>

    <div class="container">
        <h1>{$code}</h1>
        <div class="message-box">
            <h2>{$heading}</h2>
            <p>{$text}</p>
        </div>

        <a href="/" class="back-link">Return to Home Page</a>
    </div>

    <div class="brand">Slenix Framework</div>

</body>
</html>
HTML;
    }
}