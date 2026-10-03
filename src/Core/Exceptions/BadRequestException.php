<?php

declare(strict_types=1);

namespace Slenix\Core\Exceptions;

class BadRequestException extends SlenixException
{
    public function __construct(string $message = 'Bad Request.', array $context = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 400, $context, $previous);
    }
}
