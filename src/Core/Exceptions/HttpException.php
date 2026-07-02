<?php

/*
|--------------------------------------------------------------------------
| HTTP Exceptions — Slenix Framework
|--------------------------------------------------------------------------
|
| Concrete exception classes for common HTTP error scenarios.
| Each one hardcodes the appropriate status code and provides
| a sensible default message, reducing boilerplate at call sites.
|
| Usage:
|   throw new NotFoundException('Page not found.');
|   throw new ForbiddenException();
|   throw new ValidationException($errors);
|
*/

declare(strict_types=1);

namespace Slenix\Core\Exceptions;

// -------------------------------------------------------------------------
// 4xx Client Errors
// -------------------------------------------------------------------------

class BadRequestException extends SlenixException
{
    public function __construct(string $message = 'Bad Request.', array $context = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 400, $context, $previous);
    }
}

class UnauthorizedException extends SlenixException
{
    public function __construct(string $message = 'Unauthorized.', array $context = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 401, $context, $previous);
    }
}

class ForbiddenException extends SlenixException
{
    public function __construct(string $message = 'Forbidden.', array $context = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 403, $context, $previous);
    }
}

class NotFoundException extends SlenixException
{
    public function __construct(string $message = 'Not Found.', array $context = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 404, $context, $previous);
    }
}

class MethodNotAllowedException extends SlenixException
{
    public function __construct(string $message = 'Method Not Allowed.', array $context = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 405, $context, $previous);
    }
}

class TooManyRequestsException extends SlenixException
{
    public function __construct(string $message = 'Too Many Requests.', array $context = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 429, $context, $previous);
    }
}

// -------------------------------------------------------------------------
// 5xx Server Errors
// -------------------------------------------------------------------------

class InternalServerException extends SlenixException
{
    public function __construct(string $message = 'Internal Server Error.', array $context = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 500, $context, $previous);
    }
}

class ServiceUnavailableException extends SlenixException
{
    public function __construct(string $message = 'Service Unavailable.', array $context = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 503, $context, $previous);
    }
}

// -------------------------------------------------------------------------
// Domain Exceptions
// -------------------------------------------------------------------------

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

/**
 * Thrown when the .env or config file is missing/corrupt.
 */
class ConfigurationException extends SlenixException
{
    public function __construct(string $message = 'Application misconfigured.', ?\Throwable $previous = null)
    {
        parent::__construct($message, 500, [], $previous);
    }
}