<?php

/*
|--------------------------------------------------------------------------
| UserProvider
|--------------------------------------------------------------------------
|
| Responsible for retrieving user models from the database and validating
| their credentials. Decouples the guards from the concrete User model,
| making it straightforward to swap the user source in the future.
|
| The User model must use the Authenticatable trait, which provides:
|   - getAuthIdentifier()   — returns the primary key value
|   - getAuthPassword()     — returns the hashed password string
|   - verifyPassword($plain)— runs password_verify() internally
|
| Depends on:
|   - app\Models\User  (resolved dynamically via $modelClass)
|   - Slenix\Database\Model (base — find, firstWhere)
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Auth;

class UserProvider
{
    /**
     * Fully-qualified class name of the user model.
     * Defaults to the conventional App\Models\User.
     *
     * @var class-string
     */
    private string $modelClass;

    /**
     * The column used to look up a user by identity (e.g. email or username).
     *
     * @var string
     */
    private string $identityColumn;

    /**
     * Create a new UserProvider instance.
     *
     * @param class-string|null $modelClass     Override the user model class.
     * @param string            $identityColumn Column to search by credentials (default: 'email').
     */
    public function __construct(
        ?string $modelClass     = null,
        string  $identityColumn = 'email'
    ) {
        $this->modelClass     = $modelClass ?? 'App\\Models\\User';
        $this->identityColumn = $identityColumn;
    }

    // -------------------------------------------------------------------------
    // Retrieval
    // -------------------------------------------------------------------------

    /**
     * Retrieve a user by their primary key.
     *
     * Used by SessionGuard (session stores the ID)
     * and JwtGuard (JWT 'sub' claim).
     *
     * @param  int|string $id
     * @return object|null  The user model instance, or null if not found.
     */
    public function retrieveById(int|string $id): ?object
    {
        return ($this->modelClass)::find($id);
    }

    /**
     * Retrieve a user by their identity column value (e.g. email).
     *
     * The password key in the credentials array is intentionally ignored
     * here — password verification is done separately in validateCredentials().
     *
     * @param  array $credentials  e.g. ['email' => 'user@example.com', 'password' => '...']
     * @return object|null
     */
    public function retrieveByCredentials(array $credentials): ?object
    {
        $identity = $credentials[$this->identityColumn] ?? null;

        if ($identity === null || $identity === '') {
            return null;
        }

        return ($this->modelClass)::firstWhere($this->identityColumn, $identity);
    }

    /**
     * Retrieve a user by a specific column/value pair.
     *
     * Useful for token-based lookups (e.g. api_token, verify_token).
     *
     * @param  string $column
     * @param  mixed  $value
     * @return object|null
     */
    public function retrieveByColumn(string $column, mixed $value): ?object
    {
        return ($this->modelClass)::firstWhere($column, $value);
    }

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    /**
     * Validate that the given plain-text password matches the user's stored hash.
     *
     * Delegates to the Authenticatable trait's verifyPassword() method,
     * which calls PHP's password_verify() internally.
     *
     * @param  object $user         User model instance (must use Authenticatable).
     * @param  array  $credentials  Must contain a 'password' key with the plain text.
     * @return bool
     *
     * @throws \RuntimeException If the user model does not use the Authenticatable trait.
     */
    public function validateCredentials(object $user, array $credentials): bool
    {
        $plain = $credentials['password'] ?? null;

        if ($plain === null || $plain === '') {
            return false;
        }

        if (!method_exists($user, 'verifyPassword')) {
            throw new \RuntimeException(sprintf(
                'Model [%s] must use the Authenticatable trait to validate credentials.',
                $user::class
            ));
        }

        return $user->verifyPassword((string) $plain);
    }

    // -------------------------------------------------------------------------
    // Configuration helpers
    // -------------------------------------------------------------------------

    /**
     * Override the user model class at runtime.
     *
     * @param  class-string $class
     * @return static
     */
    public function setModel(string $class): static
    {
        $this->modelClass = $class;
        return $this;
    }

    /**
     * Override the identity column at runtime.
     *
     * @param  string $column
     * @return static
     */
    public function setIdentityColumn(string $column): static
    {
        $this->identityColumn = $column;
        return $this;
    }

    /**
     * Get the current user model class.
     *
     * @return class-string
     */
    public function getModelClass(): string
    {
        return $this->modelClass;
    }
}