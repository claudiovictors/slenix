<?php

declare(strict_types=1);

namespace Slenix\Supports\Validation;

class ValidationException extends \RuntimeException
{
    public function __construct(protected array $errors)
    {
        parent::__construct('Validation failed.');
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function first(): string
    {
        return array_values($this->errors)[0] ?? '';
    }
}