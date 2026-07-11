<?php

/*
|--------------------------------------------------------------------------
| GuardInterface
|--------------------------------------------------------------------------
|
| Contract that every authentication guard must implement.
| Allows AuthManager to work with SessionGuard and JwtGuard
| interchangeably through a unified API.
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Auth\Guards;

interface GuardInterface
{
    /**
     * Determine if the current user is authenticated.
     *
     * @return bool
     */
    public function check(): bool;

    /**
     * Determine if the current user is a guest (not authenticated).
     *
     * @return bool
     */
    public function guest(): bool;

    /**
     * Get the currently authenticated user model.
     *
     * @return object|null The user model instance, or null if not authenticated.
     */
    public function user(): ?object;

    /**
     * Get the ID of the currently authenticated user.
     *
     * @return int|string|null
     */
    public function id(): int|string|null;

    /**
     * Attempt to authenticate a user with the given credentials.
     *
     * @param  array $credentials  Typically ['email' => ..., 'password' => ...]
     * @param  bool  $remember     Whether to persist the session beyond the browser session.
     * @return bool  True on success, false on failure.
     */
    public function attempt(array $credentials, bool $remember = false): bool;

    /**
     * Log a user into the application without verifying credentials.
     *
     * @param  object $user     A model instance that uses the Authenticatable trait.
     * @param  bool   $remember Whether to set a remember-me cookie.
     * @return void
     */
    public function login(object $user, bool $remember = false): void;

    /**
     * Log the current user out of the application.
     *
     * @return void
     */
    public function logout(): void;

    /**
     * Validate a user's credentials without logging them in.
     *
     * @param  array $credentials
     * @return bool
     */
    public function validate(array $credentials): bool;
}