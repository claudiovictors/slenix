<?php

declare(strict_types=1);

namespace Slenix\Core\Exceptions;

class InternalServerException extends SlenixException
{
    public function __construct(string $message = 'Internal Server Error.', array $context = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 500, $context, $previous);
    }
}
