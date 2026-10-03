<?php

declare(strict_types=1);

namespace Slenix\Core\Exceptions;

class ForbiddenException extends SlenixException
{
    public function __construct(string $message = 'Forbidden.', array $context = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 403, $context, $previous);
    }
}
