<?php

/*
|--------------------------------------------------------------------------
| ProductionRenderer — Slenix Framework
|--------------------------------------------------------------------------
|
| Renders a generic, user-facing error page when APP_DEBUG=false.
| Zero internal details are exposed; only the HTTP status code and
| a friendly message are shown.
|
*/

declare(strict_types=1);

namespace Slenix\Core\Exceptions\Renderers;

use Slenix\Core\Exceptions\Contracts\ExceptionRenderer;
use Slenix\Core\Exceptions\SlenixException;

class ProductionRenderer implements ExceptionRenderer
{
    public function canRender(\Throwable $exception): bool
    {
        return true;
    }

    public function render(\Throwable $exception): string
    {
        $code    = $exception instanceof SlenixException
            ? $exception->getStatusCode()
            : 500;

        $appName = $this->appName();
        $title   = $this->titleForCode($code);
        $message = $this->messageForCode($code);

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <link rel="shortcut icon" href="/logo.svg" type="image/x-icon">
            <title>{$code} {$title} — {$appName}</title>
            <style>
                *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
                body{
                    background:#0a0a0a;color:#ededed;
                    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
                    display:flex;align-items:center;justify-content:center;
                    min-height:100vh;text-align:center;padding:2rem;
                }
                .code{
                    font-size:6rem;font-weight:800;line-height:1;
                    color:transparent;
                    background:linear-gradient(135deg,#e5484d,#f76808);
                    -webkit-background-clip:text;background-clip:text;
                    letter-spacing:-.04em;
                }
                .title{font-size:1.25rem;font-weight:600;margin:.75rem 0 .5rem;color:#ededed}
                .msg{color:#666;font-size:.9rem;max-width:28rem;line-height:1.6}
                .divider{width:3rem;height:2px;background:#222;margin:1.25rem auto}
                .back{
                    display:inline-block;margin-top:1.5rem;
                    padding:.45rem 1.1rem;
                    border:1px solid #2e2e2e;border-radius:6px;
                    color:#a1a1a1;font-size:.82rem;text-decoration:none;
                    transition:color .15s,border-color .15s;
                }
                .back:hover{color:#ededed;border-color:#555}
            </style>
        </head>
        <body>
            <div>
                <div class="code">{$code}</div>
                <div class="title">{$title}</div>
                <div class="divider"></div>
                <p class="msg">{$message}</p>
                <a class="back" href="/">← Back to home</a>
            </div>
        </body>
        </html>
        HTML;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function titleForCode(int $code): string
    {
        return match ($code) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Page Not Found',
            405 => 'Method Not Allowed',
            419 => 'Session Expired',
            422 => 'Unprocessable Content',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            503 => 'Service Unavailable',
            default => 'Something Went Wrong',
        };
    }

    private function messageForCode(int $code): string
    {
        return match ($code) {
            400 => 'The server could not understand the request due to invalid syntax.',
            401 => 'You must be authenticated to access this resource.',
            403 => 'You do not have permission to access this resource.',
            404 => 'The page you are looking for does not exist or has been moved.',
            405 => 'The HTTP method used is not supported for this route.',
            419 => 'Your session has expired. Please refresh the page and try again.',
            422 => 'The request data could not be processed. Please check your input.',
            429 => 'You have sent too many requests. Please slow down and try again later.',
            500 => 'The server encountered an internal error and could not complete your request.',
            503 => 'The service is temporarily unavailable. Please try again in a few minutes.',
            default => 'An unexpected error occurred. Please try again or contact support.',
        };
    }

    private function appName(): string
    {
        if (function_exists('env')) {
            return htmlspecialchars((string) env('APP_NAME', 'Slenix'), ENT_QUOTES, 'UTF-8');
        }

        $name = $_ENV['APP_NAME'] ?? $_SERVER['APP_NAME'] ?? 'Slenix';

        return htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8');
    }
}