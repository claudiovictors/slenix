<?php

declare(strict_types=1);

namespace Slenix\Core\Exceptions;

class UnauthorizedException extends SlenixException
{
    public function __construct(string $message = 'Unauthorized.', array $context = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 401, $context, $previous);
    }
}
