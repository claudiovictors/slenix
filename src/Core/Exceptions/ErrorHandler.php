<?php

/*
|--------------------------------------------------------------------------
| ErrorHandler — Slenix Framework
|--------------------------------------------------------------------------
|
| Central error and exception handler. Acts as an orchestrator only:
| it resolves which renderer to use and delegates the actual output.
|
| PHP 8.4 compatibility:
|   - E_STRICT removed from the $errorTypes map (constant deprecated in 8.4)
|   - Uses isset() guard before referencing E_STRICT
|
| File layout:
|
|   Contracts/
|       ExceptionRenderer.php   — renderer interface
|   Concerns/
|       CodeInspector.php       — source snippet + syntax highlight
|       StackTraceParser.php    — structured stack trace
|       ExceptionContext.php    — request/session/env context
|   Pages/
|       DebugPageAssets.php     — CSS + JS for debug page
|   Renderers/
|       DebugRenderer.php       — rich debug HTML page
|       ProductionRenderer.php  — clean 500 page
|       JsonRenderer.php        — JSON for API / AJAX
|   HttpExceptions.php          — concrete HTTP exception classes
|   SlenixException.php         — framework base exception
|   ErrorHandler.php            — this file
|
*/

declare(strict_types=1);

namespace Slenix\Core\Exceptions;

use Slenix\Http\Request;
use Slenix\Http\Response;
use Slenix\Supports\Logging\Log;
use Slenix\Core\Exceptions\Renderers\DebugRenderer;
use Slenix\Core\Exceptions\Renderers\ProductionRenderer;
use Slenix\Core\Exceptions\Renderers\JsonRenderer;

class ErrorHandler
{
    /** @var bool|null Lazy-cached APP_DEBUG value. */
    private ?bool $debugCache = null;

    /**
     * PHP error level → human-readable label.
     * E_STRICT is excluded: it was deprecated in PHP 8.0 and removed in PHP 8.4.
     *
     * @var array<int, string>
     */
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
        E_RECOVERABLE_ERROR => 'Recoverable Error',
        E_DEPRECATED        => 'Deprecated',
        E_USER_DEPRECATED   => 'User Deprecated',
    ];

    // -------------------------------------------------------------------------
    // PHP error / exception registration hooks
    // -------------------------------------------------------------------------

    /**
     * Converts PHP errors into ErrorException instances for uniform handling.
     *
     * @throws \ErrorException
     */
    public function handleError(int $severity, string $message, string $file, int $line): bool
    {
        // Respect the current error_reporting() mask (e.g. @ operator suppression)
        if (!(error_reporting() & $severity)) {
            return false;
        }

        throw new \ErrorException($message, 0, $severity, $file, $line);
    }

    /**
     * Top-level exception handler: log, then render the appropriate response.
     */
    public function handleException(\Throwable $exception): void
    {
        // Best-effort logging — must never throw
        $this->tryLog($exception);

        $statusCode = $this->resolveStatusCode($exception);

        $request  = new Request();
        $response = new Response();
        $response->withoutCache();

        if ($this->isApiRequest($request)) {
            $body = (new JsonRenderer($this->isDebug()))->render($exception);
            $response->status($statusCode)
                ->header('Content-Type', 'application/json')
                ->send($body);
            return;
        }

        if ($this->isDebug()) {
            $body = (new DebugRenderer())->render($exception);
        } else {
            $body = (new ProductionRenderer())->render($exception);
        }

        $response->status($statusCode)->html($body);
    }

    /**
     * Called from Kernel::handleShutdown() for fatal errors that bypass
     * set_error_handler() (E_ERROR, E_PARSE, E_CORE_ERROR, etc.).
     *
     * Only used internally; not registered directly with PHP.
     */
    public function handleShutdownError(array $error): void
    {
        $this->handleException(
            new \ErrorException(
                $error['message'],
                0,
                $error['type'],
                $error['file'],
                $error['line']
            )
        );
    }

    /**
     * Special path for .env / configuration failures that occur before
     * the full request stack is available.
     */
    public function handleEnvError(\Throwable $exception): never
    {
        $this->tryLog($exception);

        $response = new Response();
        $response->status(500)->json([
            'error'   => 'Configuration Error',
            'message' => $exception->getMessage(),
        ]);

        exit(1);
    }

    // -------------------------------------------------------------------------
    // Public helpers
    // -------------------------------------------------------------------------

    /**
     * Returns the human-readable label for a PHP error level constant.
     */
    public static function errorTypeName(int $severity): string
    {
        return self::$errorTypes[$severity] ?? "Unknown Error ({$severity})";
    }

    // -------------------------------------------------------------------------
    // Private
    // -------------------------------------------------------------------------

    /**
     * Resolves the HTTP status code from the exception.
     * SlenixException subclasses carry their own code; everything else → 500.
     */
    private function resolveStatusCode(\Throwable $exception): int
    {
        if ($exception instanceof SlenixException) {
            return $exception->getStatusCode();
        }

        return match (true) {
            $exception instanceof \InvalidArgumentException => 400,
            default                                          => 500,
        };
    }

    /**
     * Determines whether the current request expects a JSON response.
     */
    private function isApiRequest(Request $request): bool
    {
        return $request->isJson()
            || $request->expectsJson()
            || $request->isAjax()
            || str_starts_with($request->uri(), '/api/');
    }

    /**
     * Lazy-resolves APP_DEBUG from the env, with a robust fallback.
     */
    private function isDebug(): bool
    {
        if ($this->debugCache !== null) {
            return $this->debugCache;
        }

        $val = function_exists('env')
            ? env('APP_DEBUG', false)
            : ($_ENV['APP_DEBUG'] ?? $_SERVER['APP_DEBUG'] ?? getenv('APP_DEBUG') ?? false);

        $this->debugCache = filter_var($val, FILTER_VALIDATE_BOOLEAN);

        return $this->debugCache;
    }

    /**
     * Logs the exception without throwing on failure.
     */
    private function tryLog(\Throwable $exception): void
    {
        try {
            Log::error(sprintf(
                "[%s] [%s] %s in %s:%d\nTrace:\n%s",
                date('Y-m-d H:i:s'),
                get_class($exception),
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine(),
                $exception->getTraceAsString()
            ));
        } catch (\Throwable) {
            // Intentionally swallowed — logging must never mask the original error
        }
    }
}