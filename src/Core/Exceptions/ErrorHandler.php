<?php

/*
|--------------------------------------------------------------------------
| ErrorHandler — Slenix Framework
|--------------------------------------------------------------------------
|
| Central error and exception handler. Acts as an orchestrator only:
| it resolves which renderer to use and delegates the actual output.
|
|
*/

declare(strict_types=1);

namespace Slenix\Core\Exceptions;

use Slenix\Http\Request;
use Slenix\Http\Response;
use Slenix\Supports\Logging\Log;
use Slenix\Core\Exceptions\Renderers\DebugRenderer;

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
     * Top-level exception handler: logs the exception, then renders the
     * response that best fits the current request.
     *
     * Rendering strategy:
     *   - API / AJAX / JSON requests  -> JSON via Response::json().
     *   - Browser + APP_DEBUG=true    -> detailed {@see DebugRenderer} page.
     *   - Browser + APP_DEBUG=false   -> {@see ErrorPage} (developer view from
     *                                    views/erros/{code}.luna.php, or the
     *                                    built-in default page).
     *
     * The status code is passed straight to json()/html(), because both of
     * them re-apply their own $statusCode argument (default 200).
     *
     * @param  \Throwable $exception The uncaught exception or converted PHP error.
     * @return void
     */
    public function handleException(\Throwable $exception): void
    {
        // Best-effort logging — must never throw.
        $this->tryLog($exception);
 
        $statusCode = $this->resolveStatusCode($exception);
 
        $request  = new Request();
        $response = new Response();
        $response->withoutCache();
 
        if (self::wantsJson($request)) {
            // INVALID_UTF8_SUBSTITUTE: a message or trace with bad bytes must
            // not make json_encode() throw inside the error handler.
            $response->json(
                $this->toPayload($exception, $statusCode),
                $statusCode,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            );
            return;
        }
 
        $body = $this->isDebug()
            ? (new DebugRenderer())->render($exception)
            : ErrorPage::render($statusCode);
 
        $response->html($body, $statusCode);
    }

    /**
     * Builds the JSON error payload for API / AJAX responses.
     *
     * In production only the status code and a safe, generic message are
     * included. In debug mode the real message is used and a `debug` entry is
     * added with class, file, line and a trimmed trace (30 lines max). When the
     * exception has predecessors, `debug` holds the whole chain under the
     * `chain` key instead.
     *
     * Returns an array (not a string) so Response::json() handles encoding
     * and the Content-Type header.
     *
     * @param  \Throwable $exception The exception to describe.
     * @param  int        $code      HTTP status code already resolved for it.
     * @return array<string, mixed>  Payload ready for Response::json().
     */
    private function toPayload(\Throwable $exception, int $code): array
    {
        $debug   = $this->isDebug();
        $payload = [
            'success' => false,
            'error'   => true,
            'status'  => $code,
            'message' => $debug ? $exception->getMessage() : ErrorPage::message($code),
        ];
 
        if ($debug) {
            $chain = [];
 
            for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
                $chain[] = [
                    'class'   => get_class($current),
                    'message' => $current->getMessage(),
                    'file'    => $current->getFile(),
                    'line'    => $current->getLine(),
                    'trace'   => array_slice(explode("\n", $current->getTraceAsString()), 0, 30),
                ];
            }
 
            $payload['debug'] = count($chain) === 1 ? $chain[0] : ['chain' => $chain];
        }
 
        return $payload;
    }

     /**
     * Encodes an exception as a JSON payload for API / AJAX responses.
     *
     * In production only the status code and a safe, generic message are
     * emitted. In debug mode the real message is used and a `debug` object is
     * added with class, file, line and a trimmed trace (30 frames max). When
     * the exception has predecessors, `debug` holds the whole chain under the
     * `chain` key instead.
     *
     * The caller is responsible for setting the status and Content-Type headers.
     *
     * @param  \Throwable $exception The exception to serialise.
     * @param  int        $code      HTTP status code already resolved for it.
     * @return string                Pretty-printed JSON document.
     */
    private function toJson(\Throwable $exception, int $code): string
    {
        $debug   = $this->isDebug();
        $payload = [
            'success' => false,
            'error'   => true,
            'status'  => $code,
            'message' => $debug ? $exception->getMessage() : ErrorPage::message($code),
        ];
 
        if ($debug) {
            $chain = [];
 
            for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
                $chain[] = [
                    'class'   => get_class($current),
                    'message' => $current->getMessage(),
                    'file'    => $current->getFile(),
                    'line'    => $current->getLine(),
                    'trace'   => array_slice(explode("\n", $current->getTraceAsString()), 0, 30),
                ];
            }
 
            $payload['debug'] = count($chain) === 1 ? $chain[0] : ['chain' => $chain];
        }
 
        return (string) json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );
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
     *
     * Always answers with a JSON 500. The status is passed to json() so it is
     * not reset to 200.
     *
     * @param  \Throwable $exception The configuration error.
     * @return never
     */
    public function handleEnvError(\Throwable $exception): never
    {
        $this->tryLog($exception);
 
        (new Response())->json([
            'error'   => 'Configuration Error',
            'message' => $exception->getMessage(),
        ], 500);
 
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
     * Determines whether a request expects a JSON response.
     *
     * Public and static so other framework components (e.g. the Router) can
     * apply the exact same rule instead of duplicating it.
     *
     * A request is considered an API request when any of these is true:
     *   - its body is JSON,
     *   - it sends `Accept: application/json`,
     *   - it is an AJAX (XMLHttpRequest) call,
     *   - its URI starts with `/api/`.
     *
     * @param  Request $request The current request.
     * @return bool             True when a JSON response is expected.
     */
    public static function wantsJson(Request $request): bool
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
     * Logs the exception without ever throwing.
     *
     * Client errors (a {@see SlenixException} whose status is below 500, such
     * as a 404 or 403) are expected traffic, so they are logged as a single
     * WARNING line without a stack trace. Any other exception is logged as an
     * ERROR including the full trace.
     *
     * The timestamp is not added here: the Log class already prefixes each
     * entry with one.
     *
     * @param  \Throwable $exception The exception to record.
     * @return void
     */
    private function tryLog(\Throwable $exception): void
    {
        try {
            $isClientError = $exception instanceof SlenixException
                && $exception->getStatusCode() < 500;
 
            if ($isClientError) {
                Log::warning(sprintf(
                    '[%s] %s in %s:%d',
                    get_class($exception),
                    $exception->getMessage(),
                    $exception->getFile(),
                    $exception->getLine()
                ));
                return;
            }
 
            Log::error(sprintf(
                "[%s] %s in %s:%d\nTrace:\n%s",
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