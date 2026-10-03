<?php

declare(strict_types=1);

namespace Slenix\Core\Exceptions;

class MethodNotAllowedException extends SlenixException
{
    public function __construct(string $message = 'Method Not Allowed.', array $context = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 405, $context, $previous);
    }
}
