<?php

/*
|--------------------------------------------------------------------------
| ValidationException Class
|--------------------------------------------------------------------------
|
| Thrown when Validator::validate() fails.
|
| The static helper redirect() makes the common "redirect back with errors"
| pattern a one-liner:
|
|   ValidationException::redirectBack($e, $request->all());
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Validation;

class ValidationException extends \RuntimeException
{
    protected MessageBag $bag;

    /**
     * @param  MessageBag|array<string, string|string[]> $errors
     */
    public function __construct(MessageBag|array $errors)
    {
        $this->bag = $errors instanceof MessageBag
            ? $errors
            : new MessageBag($errors);

        parent::__construct('Validation failed.');
    }

    /**
     * The MessageBag containing all validation errors.
     */
    public function errors(): MessageBag
    {
        return $this->bag;
    }

    /**
     * Returns the first error message found across all fields.
     */
    public function first(): string
    {
        return $this->bag->first();
    }

    /**
     * Convenience: redirect back, flash errors + old input and exit.
     * Requires the global redirect() and the request $input array.
     *
     * Usage inside a controller:
     *   } catch (ValidationException $e) {
     *       ValidationException::redirectBack($e, $_POST);
     *   }
     *
     * @param  array<string, mixed> $input  Raw POST data (defaults to $_POST).
     * @param  string               $bag    Error bag name.
     * @return never
     */
    public static function redirectBack(
        self $e,
        array $input = [],
        string $bag = 'default'
    ): never {
        redirect()                              // ← sem return
            ->back()
            ->withErrors($e->errors()->toArray(), $bag)
            ->withInput($input ?: $_POST);
    }
}