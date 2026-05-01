<?php

/*
|--------------------------------------------------------------------------
| ValidationException Class
|--------------------------------------------------------------------------
|
| Exception thrown when data validation fails.
| Carries an array of error messages indexed by field name.
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Validation;

class ValidationException extends \RuntimeException
{
    /**
     * ValidationException constructor.
     * @param array $errors Field-indexed error messages.
     */
    public function __construct(protected array $errors)
    {
        parent::__construct('Validation failed.');
    }

    /**
     * Retrieves all validation errors.
     * @return array
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Returns the first error message found.
     * @return string
     */
    public function first(): string
    {
        return array_values($this->errors)[0] ?? '';
    }
}