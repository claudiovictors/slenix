<?php

/*
|--------------------------------------------------------------------------
| JsonRenderer — Slenix Framework
|--------------------------------------------------------------------------
|
| Renders exception information as a JSON payload for API / AJAX contexts.
| In debug mode it includes the full exception chain, file, line, and trace.
| In production only the status code and a safe message are emitted.
|
*/

declare(strict_types=1);

namespace Slenix\Core\Exceptions\Renderers;

use Slenix\Core\Exceptions\Contracts\ExceptionRenderer;
use Slenix\Core\Exceptions\SlenixException;

class JsonRenderer implements ExceptionRenderer
{
    public function __construct(private readonly bool $debug = false) {}

    public function canRender(\Throwable $exception): bool
    {
        return true;
    }

    /**
     * Encodes the exception as a JSON string.
     * Note: the caller is responsible for setting Content-Type and status headers.
     */
    public function render(\Throwable $exception): string
    {
        $code    = $exception instanceof SlenixException
            ? $exception->getStatusCode()
            : 500;

        $payload = [
            'success'    => false,
            'error'      => true,
            'status'     => $code,
            'message'    => $this->debug
                ? $exception->getMessage()
                : $this->safeMessage($code),
        ];

        if ($this->debug) {
            $payload['debug'] = $this->debugPayload($exception);
        }

        return (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Builds the debug sub-object including the full exception chain.
     *
     * @return array<string, mixed>
     */
    private function debugPayload(\Throwable $exception): array
    {
        $chain   = [];
        $current = $exception;

        while ($current !== null) {
            $chain[] = [
                'class'   => get_class($current),
                'message' => $current->getMessage(),
                'file'    => $current->getFile(),
                'line'    => $current->getLine(),
                'trace'   => array_slice(
                    array_map(
                        static fn (array $f) => ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? '') . ' ' . ($f['file'] ?? '') . ':' . ($f['line'] ?? ''),
                        $current->getTrace()
                    ),
                    0,
                    30
                ),
            ];

            $current = $current->getPrevious();
        }

        return count($chain) === 1 ? $chain[0] : ['chain' => $chain];
    }

    /**
     * Returns a safe, generic message for production responses.
     */
    private function safeMessage(int $code): string
    {
        return match ($code) {
            400 => 'Bad request.',
            401 => 'Unauthenticated.',
            403 => 'Forbidden.',
            404 => 'Resource not found.',
            405 => 'Method not allowed.',
            422 => 'The given data was invalid.',
            429 => 'Too many requests.',
            503 => 'Service temporarily unavailable.',
            default => 'An unexpected error occurred.',
        };
    }
}