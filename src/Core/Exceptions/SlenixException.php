<?php

/*
|--------------------------------------------------------------------------
| SlenixException — Slenix Framework Base Exception
|--------------------------------------------------------------------------
|
| Base exception class for all framework-level exceptions.
| Carries an HTTP status code and an optional context payload,
| enabling renderers to produce richer error responses.
|
*/

declare(strict_types=1);

namespace Slenix\Core\Exceptions;

class SlenixException extends \RuntimeException
{
    /** @var int HTTP status code associated with this exception. */
    protected int $statusCode;

    /** @var array<string, mixed> Optional contextual data for debug output. */
    protected array $context;

    /**
     * @param string          $message    Human-readable error message.
     * @param int             $statusCode HTTP status code (default: 500).
     * @param array           $context    Extra context for debug renderers.
     * @param \Throwable|null $previous   Previous exception in the chain.
     */
    public function __construct(
        string $message = '',
        int $statusCode = 500,
        array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
        $this->statusCode = $statusCode;
        $this->context    = $context;
    }

    /**
     * Returns the HTTP status code for this exception.
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Returns additional context attached to this exception.
     *
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Attach extra context data to this exception.
     *
     * @param  array<string, mixed> $context
     * @return static
     */
    public function withContext(array $context): static
    {
        $this->context = array_merge($this->context, $context);
        return $this;
    }
}