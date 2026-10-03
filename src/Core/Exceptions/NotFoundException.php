<?php

declare(strict_types=1);

namespace Slenix\Core\Exceptions;

class NotFoundException extends SlenixException
{
    public function __construct(string $message = 'Not Found.', array $context = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 404, $context, $previous);
    }
}
