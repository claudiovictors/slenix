<?php

declare(strict_types=1);

namespace Slenix\Core\Exceptions;

/**
 * Thrown when request input fails validation.
 * Carries a bag of field-level error messages.
 */
class ValidationFailedException extends SlenixException
{
    /** @var array<string, string[]> Field-level validation errors. */
    private array $errors;

    /**
     * @param array<string, string[]> $errors   Keyed by field name.
     * @param string                  $message  Summary message.
     */
    public function __construct(
        array $errors = [],
        string $message = 'The given data was invalid.',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 422, ['errors' => $errors], $previous);
        $this->errors = $errors;
    }

    /**
     * @return array<string, string[]>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
