<?php

/*
|--------------------------------------------------------------------------
| SessionGuard
|--------------------------------------------------------------------------
|
| Handles stateful (web) authentication using PHP sessions.
| Stores the authenticated user's ID in the session under the
| key 'auth_id'. Regenerates the session ID on login and logout
| to prevent session fixation attacks.
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Auth\Guards;

use Slenix\Supports\Auth\UserProvider;
use Slenix\Supports\Security\Session;

class SessionGuard implements GuardInterface
{
    /**
     * The session key that holds the authenticated user's primary key.
     *
     * @var string
     */
    private const SESSION_KEY = 'auth_id';

    /**
     * The resolved user model, cached for the duration of the request.
     * Avoids multiple database hits when user() is called more than once.
     *
     * @var object|null
     */
    private ?object $resolvedUser = null;

    /**
     * The UserProvider instance used to retrieve the User model.
     *
     * @var UserProvider
     */
    private UserProvider $provider;

    /**
     * Create a new SessionGuard instance.
     *
     * @param UserProvider $provider
     */
    public function __construct(UserProvider $provider)
    {
        $this->provider = $provider;
    }

    // -------------------------------------------------------------------------
    // GuardInterface
    // -------------------------------------------------------------------------

    /**
     * Determine if the current user is authenticated.
     *
     * @return bool
     */
    public function check(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Determine if the current user is a guest (not authenticated).
     *
     * @return bool
     */
    public function guest(): bool
    {
        return !$this->check();
    }

    /**
     * Get the currently authenticated user.
     *
     * Resolution order:
     *   1. In-memory cache ($resolvedUser) — avoids multiple DB hits per request.
     *   2. Session key 'auth_id'           — standard stateful login.
     *
     * @return object|null
     */
    public function user(): ?object
    {
        if ($this->resolvedUser !== null) {
            return $this->resolvedUser;
        }

        $id = Session::get(self::SESSION_KEY);

        if ($id !== null) {
            $this->resolvedUser = $this->provider->retrieveById($id);
        }

        return $this->resolvedUser;
    }

    /**
     * Get the ID of the currently authenticated user.
     *
     * @return int|string|null
     */
    public function id(): int|string|null
    {
        return $this->user()?->getAuthIdentifier();
    }

    /**
     * Attempt to authenticate a user with the given credentials.
     *
     * Retrieves the user by the identity column, then verifies the
     * password using the Authenticatable trait's verifyPassword() method.
     *
     * @param  array $credentials  ['email' => ..., 'password' => ...]
     * @param  bool  $remember     Unused in SessionGuard — reserved for future use.
     * @return bool
     */
    public function attempt(array $credentials, bool $remember = false): bool
    {
        $user = $this->provider->retrieveByCredentials($credentials);

        if ($user === null) {
            return false;
        }

        if (!$this->provider->validateCredentials($user, $credentials)) {
            return false;
        }

        $this->login($user);
        return true;
    }

    /**
     * Log a user into the application without verifying credentials.
     *
     * Regenerates the session ID to prevent session fixation attacks,
     * then stores the user's primary key in the session.
     *
     * @param  object $user     Must use the Authenticatable trait.
     * @param  bool   $remember Unused in SessionGuard — reserved for future use.
     * @return void
     */
    public function login(object $user, bool $remember = false): void
    {
        Session::regenerateId(true);
        Session::set(self::SESSION_KEY, $user->getAuthIdentifier());

        $this->resolvedUser = $user;
    }

    /**
     * Log the current user out of the application.
     *
     * Removes the auth ID from the session and regenerates
     * the session ID to invalidate the previous session token.
     *
     * @return void
     */
    public function logout(): void
    {
        Session::remove(self::SESSION_KEY);
        Session::regenerateId(true);

        $this->resolvedUser = null;
    }

    /**
     * Validate a user's credentials without logging them in.
     *
     * @param  array $credentials
     * @return bool
     */
    public function validate(array $credentials): bool
    {
        $user = $this->provider->retrieveByCredentials($credentials);

        if ($user === null) {
            return false;
        }

        return $this->provider->validateCredentials($user, $credentials);
    }

    // -------------------------------------------------------------------------
    // Extra helpers
    // -------------------------------------------------------------------------

    /**
     * Re-fetch the authenticated user from the database.
     *
     * Useful after profile updates to sync the in-memory instance
     * with the latest data from the database.
     *
     * @return void
     */
    public function refresh(): void
    {
        $id = Session::get(self::SESSION_KEY);

        if ($id !== null) {
            $this->resolvedUser = $this->provider->retrieveById($id);
        }
    }
}