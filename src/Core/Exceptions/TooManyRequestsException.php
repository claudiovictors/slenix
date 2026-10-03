<?php

declare(strict_types=1);

namespace Slenix\Core\Exceptions;

class TooManyRequestsException extends SlenixException
{
    public function __construct(string $message = 'Too Many Requests.', array $context = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 429, $context, $previous);
    }
}
