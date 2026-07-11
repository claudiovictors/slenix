<?php

/*
|--------------------------------------------------------------------------
| FormRequest Class — Slenix Framework
|--------------------------------------------------------------------------
|
| Base class for dedicated request validation objects. Extend this class
| to encapsulate validation rules, custom messages, field labels,
| authorization logic, and post-validation hooks outside of controllers.
|
| The Router resolves FormRequest subclasses automatically via
| dependency injection — you never instantiate them manually.
|
| Lifecycle (per request):
|   1. resolveAndValidate()  — entry point called by the Router
|   2. authorize()           — gate check; 403 on false
|   3. prepareForValidation()— optional data transformation before rules run
|   4. Validator::make()     — runs all rules
|   5a. passedValidation()   — hook called on success
|   5b. failedValidation()   — hook called on failure (redirects or JSON)
|
| Usage:
|   php celestial make:request LoginRequest
|
| @version 2.8.0
| @package Slenix\Http
|
*/

declare(strict_types=1);

namespace Slenix\Http;

use Slenix\Supports\Validation\Validator;
use Slenix\Supports\Validation\MessageBag;
use Slenix\Supports\Validation\ValidationException;

abstract class FormRequest extends Request
{
    // -------------------------------------------------------------------------
    // Internal State
    // -------------------------------------------------------------------------

    /**
     * Data that passed all validation rules.
     *
     * Populated by resolveAndValidate() on success.
     * Access via validated(), only(), or except().
     *
     * @var array<string, mixed>
     */
    protected array $validated = [];

    /**
     * The MessageBag produced by the last failed validation attempt.
     *
     * Null until validation has been run and failed.
     *
     * @var MessageBag|null
     */
    protected ?MessageBag $errorBag = null;

    /**
     * Named bag used when flashing errors to the session.
     *
     * Override in a subclass to namespace errors when multiple forms
     * exist on the same page (e.g. 'login', 'register').
     *
     * @var string
     */
    protected string $errorBagName = 'default';

    /**
     * Fields that should never be included in flashed old input.
     *
     * Merged with the framework defaults (password, _csrf_token) in
     * withInput() before the data is written to the session.
     *
     * @var string[]
     */
    protected array $dontFlash = [];

    // -------------------------------------------------------------------------
    // Abstract Contract
    // -------------------------------------------------------------------------

    /**
     * Define the validation rules for this request.
     *
     * Supports pipe-separated strings or arrays of rule strings.
     * All rules available in Validator are supported.
     *
     * Example:
     *   return [
     *       'email'    => 'required|email|unique:users,email',
     *       'password' => ['required', 'min:8', 'confirmed'],
     *   ];
     *
     * @return array<string, string|string[]>
     */
    abstract public function rules(): array;

    // -------------------------------------------------------------------------
    // Overridable Hooks
    // -------------------------------------------------------------------------

    /**
     * Custom per-rule error messages. Override to replace the defaults.
     *
     * Keys follow the pattern "{field}.{rule}" or just "{rule}".
     *
     * Example:
     *   return [
     *       'email.required' => 'We need your e-mail address.',
     *       'email.email'    => 'That does not look like a valid e-mail.',
     *   ];
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [];
    }

    /**
     * Human-readable labels for field names used in default error messages.
     *
     * Replaces the auto-generated label (e.g. "first_name" → "First name")
     * with a custom string shown to the user.
     *
     * Example:
     *   return ['email' => 'e-mail address'];
     *
     * @return array<string, string>
     */
    public function labels(): array
    {
        return [];
    }

    /**
     * Authorization gate. Return false to trigger a 403 response.
     *
     * Override to add role, permission, or ownership checks.
     *
     * Example:
     *   return auth()->check() && auth()->user()->can('edit-posts');
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Transform or sanitize input data before validation rules are applied.
     *
     * Call $this->merge() inside to add or overwrite values.
     * Runs between authorize() and Validator::make().
     *
     * Example:
     *   protected function prepareForValidation(): void
     *   {
     *       $this->merge(['slug' => Str::slug($this->input('name'))]);
     *   }
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        // Override in subclass to transform input before validation.
    }

    /**
     * Hook executed immediately after validation succeeds.
     *
     * Use this to transform validated data, fire events, or set
     * additional properties before the controller receives the request.
     *
     * @return void
     */
    protected function passedValidation(): void
    {
        // Override in subclass to act on successful validation.
    }

    /**
     * Handle a failed validation attempt.
     *
     * Default behaviour:
     *   - JSON/API requests → 422 JSON response with error bag
     *   - Web requests      → redirect back with flashed errors + old input
     *
     * Override to customize the failure response (e.g. redirect to a
     * specific route, add extra flash data, log the attempt).
     *
     * @param  ValidationException $exception  The exception carrying the MessageBag.
     * @return never
     */
    protected function failedValidation(ValidationException $exception): never
    {
        $this->errorBag = $exception->errors();

        if ($this->expectsJson()) {
            $this->jsonValidationResponse($this->errorBag);
        }

        $this->webValidationResponse($this->errorBag);
    }

    /**
     * Handle a failed authorization check.
     *
     * Default behaviour:
     *   - JSON/API requests → 403 JSON response
     *   - Web requests      → 403 HTML page (or redirect)
     *
     * Override to redirect to a login page or show a custom error view.
     *
     * @return never
     */
    protected function failedAuthorization(): never
    {
        if ($this->expectsJson()) {
            header('Content-Type: application/json', true, 403);
            echo json_encode([
                'message' => 'This action is unauthorized.',
                'status'  => 403,
            ]);
            exit;
        }

        abort(403, 'This action is unauthorized.');
    }

    // -------------------------------------------------------------------------
    // Resolution — called by the Router
    // -------------------------------------------------------------------------

    /**
     * Entry point for the Router. Runs the full lifecycle:
     * authorize → prepareForValidation → validate → hooks.
     *
     * The Router calls this automatically when a controller method
     * type-hints a FormRequest subclass.
     *
     * @return static
     */
    public function resolveAndValidate(): static
    {
        // Step 1 — Authorization
        if (!$this->authorize()) {
            $this->failedAuthorization();
        }

        // Step 2 — Input preparation (transform/sanitize before rules run)
        $this->prepareForValidation();

        // Step 3 — Validation
        try {
            $validator = Validator::make(
                $this->all(),
                $this->rules(),
                $this->messages(),
                $this->labels()
            );

            $this->validated = $validator->validate();

        } catch (ValidationException $e) {
            $this->failedValidation($e);
        }

        // Step 4 — Post-validation hook (only reached on success)
        $this->passedValidation();

        return $this;
    }

    /**
     * Create a FormRequest instance from PHP globals and run validation.
     *
     * Used internally by the Router for manual resolution.
     *
     * @param  string $class Fully-qualified FormRequest subclass name.
     * @return static
     */
    public static function createAndValidate(string $class): static
    {
        /** @var static $instance */
        $instance = new $class();
        return $instance->resolveAndValidate();
    }

    // -------------------------------------------------------------------------
    // Validated Data Accessors
    // -------------------------------------------------------------------------

    /**
     * Return all data that passed validation.
     *
     * @return array<string, mixed>
     */
    public function validated(): array
    {
        return $this->validated;
    }

    /**
     * Return only the specified validated keys.
     *
     * @param  string ...$keys
     * @return array<string, mixed>
     */
    public function safe(string ...$keys): array
    {
        if (empty($keys)) {
            return $this->validated;
        }

        return array_intersect_key($this->validated, array_flip($keys));
    }

    /**
     * Return validated data excluding the specified keys.
     *
     * @param  string ...$keys
     * @return array<string, mixed>
     */
    public function safeExcept(string ...$keys): array
    {
        return array_diff_key($this->validated, array_flip($keys));
    }

    // -------------------------------------------------------------------------
    // Error Inspection
    // -------------------------------------------------------------------------

    /**
     * Return the MessageBag from the last failed validation, or an empty one.
     *
     * Safe to call even if validation has not been run yet.
     *
     * @return MessageBag
     */
    public function errors(): MessageBag
    {
        return $this->errorBag ?? new MessageBag();
    }

    /**
     * Determine whether validation has failed at least once.
     *
     * @return bool
     */
    public function hasErrors(): bool
    {
        return $this->errorBag !== null && $this->errorBag->isNotEmpty();
    }

    // -------------------------------------------------------------------------
    // Input Helpers
    // -------------------------------------------------------------------------

    /**
     * Merge additional key-value pairs into the request's input data.
     *
     * Useful inside prepareForValidation() to inject or overwrite values
     * before the Validator sees them.
     *
     * @param  array<string, mixed> $data Key-value pairs to merge.
     * @return static
     */
    public function merge(array $data): static
    {
        foreach ($data as $key => $value) {
            $_POST[$key] = $value;
        }

        return $this;
    }

    /**
     * Replace the entire input dataset.
     *
     * Use with care — this overwrites $_POST for the current request.
     *
     * @param  array<string, mixed> $data
     * @return static
     */
    public function replace(array $data): static
    {
        $_POST = $data;
        return $this;
    }

    // -------------------------------------------------------------------------
    // Internal — Response Helpers
    // -------------------------------------------------------------------------

    /**
     * Send a 422 JSON response with the full error bag and exit.
     *
     * Called by failedValidation() when the client expects JSON.
     *
     * @param  MessageBag $bag
     * @return never
     */
    private function jsonValidationResponse(MessageBag $bag): never
    {
        header('Content-Type: application/json', true, 422);
        echo json_encode([
            'message' => 'The given data was invalid.',
            'errors'  => $bag->toArray(),
            'status'  => 422,
        ]);
        exit;
    }

    /**
     * Flash errors + old input and redirect back. Exits the process.
     *
     * Uses the Slenix RedirectResponse pipeline so errors land in
     * $_SESSION['_flash_previous']['_errors'] on the next request,
     * making @error(), errors(), and $errors->has() work in views.
     *
     * Sensitive fields (password, password_confirmation, _csrf_token)
     * are always stripped from flashed input, along with any extra
     * fields listed in $this->dontFlash.
     *
     * @param  MessageBag $bag
     * @return never
     */
    private function webValidationResponse(MessageBag $bag): never
    {
        // Build the list of fields to strip from old input
        $strip = array_merge(
            ['password', 'password_confirmation', '_csrf_token'],
            $this->dontFlash
        );

        $oldInput = array_diff_key($this->all(), array_flip($strip));

        redirect()
            ->withErrors($bag->toArray(), $this->errorBagName)
            ->withInput($oldInput)
            ->back();
    }
}