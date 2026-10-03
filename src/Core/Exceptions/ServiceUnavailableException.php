<?php

declare(strict_types=1);

namespace Slenix\Core\Exceptions;

class ServiceUnavailableException extends SlenixException
{
    public function __construct(string $message = 'Service Unavailable.', array $context = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 503, $context, $previous);
    }
}
