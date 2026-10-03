<?php

declare(strict_types=1);

namespace Slenix\Core\Exceptions;

/**
 * Thrown when the .env or config file is missing/corrupt.
 */
class ConfigurationException extends SlenixException
{
    public function __construct(string $message = 'Application misconfigured.', ?\Throwable $previous = null)
    {
        parent::__construct($message, 500, [], $previous);
    }
}
