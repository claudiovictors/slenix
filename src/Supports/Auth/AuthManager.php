<?php

/*
|--------------------------------------------------------------------------
| AuthManager
|--------------------------------------------------------------------------
|
| Central authentication manager. Resolves the correct guard (web/api)
| and proxies all GuardInterface method calls to it.
|
| Acts as both a factory (creates guard instances on demand) and a
| proxy — calling auth()->check() delegates to the active guard.
|
| Built-in guards:
|   'web' → SessionGuard  (stateful, session-based)
|   'api' → JwtGuard      (stateless, Bearer token)
|
| Usage:
|   auth()->check()                      — web guard (default)
|   auth('api')->user()                  — api guard
|   auth()->guard('api')->getToken()     — api guard with JWT-specific methods
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Auth;

use Slenix\Supports\Auth\Guards\GuardInterface;
use Slenix\Supports\Auth\Guards\JwtGuard;
use Slenix\Supports\Auth\Guards\SessionGuard;
use Slenix\Supports\Security\Jwt;

class AuthManager
{
    /**
     * Resolved guard instances, keyed by guard name.
     * Guards are created lazily and cached for the request lifecycle.
     *
     * @var array<string, GuardInterface>
     */
    private array $guards = [];

    /**
     * The name of the default guard.
     * Resolved from APP_AUTH_GUARD in .env, falling back to 'web'.
     *
     * @var string
     */
    private string $defaultGuard;

    /**
     * Custom guard resolvers registered via extend().
     *
     * @var array<string, callable(UserProvider): GuardInterface>
     */
    private array $customResolvers = [];

    /**
     * Create a new AuthManager instance.
     *
     * @param string $defaultGuard  Name of the default guard ('web' or 'api').
     */
    public function __construct(string $defaultGuard = 'web')
    {
        $this->defaultGuard = $defaultGuard;
    }

    // -------------------------------------------------------------------------
    // Guard resolution
    // -------------------------------------------------------------------------

    /**
     * Get a guard instance by name.
     *
     * Resolves and caches the guard on the first call.
     * Pass null to get the default guard.
     *
     * @param  string|null $name  'web', 'api', or a custom name.
     * @return GuardInterface
     *
     * @throws \InvalidArgumentException If the guard name is not registered.
     */
    public function guard(?string $name = null): GuardInterface
    {
        $name = $name ?? $this->defaultGuard;

        if (!isset($this->guards[$name])) {
            $this->guards[$name] = $this->resolve($name);
        }

        return $this->guards[$name];
    }

    /**
     * Instantiate the correct guard for the given name.
     *
     * @param  string $name
     * @return GuardInterface
     *
     * @throws \InvalidArgumentException
     */
    private function resolve(string $name): GuardInterface
    {
        if (isset($this->customResolvers[$name])) {
            return ($this->customResolvers[$name])(new UserProvider());
        }

        return match ($name) {
            'web'   => new SessionGuard(new UserProvider()),
            'api'   => new JwtGuard(new UserProvider(), new Jwt()),
            default => throw new \InvalidArgumentException(
                "Auth guard [{$name}] is not defined. " .
                "Register it with AuthManager::extend('{$name}', fn(\$provider) => ...)."
            ),
        };
    }

    // -------------------------------------------------------------------------
    // GuardInterface proxy — default guard passthrough
    // -------------------------------------------------------------------------
    // These methods allow calling auth()->check() instead of auth()->guard()->check()

    /**
     * Determine if the current user is authenticated.
     *
     * @return bool
     */
    public function check(): bool
    {
        return $this->guard()->check();
    }

    /**
     * Determine if the current user is a guest (not authenticated).
     *
     * @return bool
     */
    public function guest(): bool
    {
        return $this->guard()->guest();
    }

    /**
     * Get the currently authenticated user model.
     *
     * @return object|null
     */
    public function user(): ?object
    {
        return $this->guard()->user();
    }

    /**
     * Get the ID of the currently authenticated user.
     *
     * @return int|string|null
     */
    public function id(): int|string|null
    {
        return $this->guard()->id();
    }

    /**
     * Attempt to authenticate using the default guard.
     *
     * @param  array $credentials  ['email' => ..., 'password' => ...]
     * @param  bool  $remember     Persist the session (web) or ignored (api).
     * @return bool
     */
    public function attempt(array $credentials, bool $remember = false): bool
    {
        return $this->guard()->attempt($credentials, $remember);
    }

    /**
     * Log a user in via the default guard without verifying credentials.
     *
     * @param  object $user
     * @param  bool   $remember
     * @return void
     */
    public function login(object $user, bool $remember = false): void
    {
        $this->guard()->login($user, $remember);
    }

    /**
     * Log the current user out via the default guard.
     *
     * @return void
     */
    public function logout(): void
    {
        $this->guard()->logout();
    }

    /**
     * Validate credentials without logging in.
     *
     * @param  array $credentials
     * @return bool
     */
    public function validate(array $credentials): bool
    {
        return $this->guard()->validate($credentials);
    }

    // -------------------------------------------------------------------------
    // Configuration helpers
    // -------------------------------------------------------------------------

    /**
     * Register a custom guard resolver.
     *
     * The callback receives a UserProvider and must return a GuardInterface.
     *
     * @example
     *   auth()->extend('ldap', fn($provider) => new LdapGuard($provider));
     *
     * @param  string   $name
     * @param  callable $resolver  fn(UserProvider): GuardInterface
     * @return static
     */
    public function extend(string $name, callable $resolver): static
    {
        $this->customResolvers[$name] = $resolver;
        return $this;
    }

    /**
     * Override the default guard at runtime.
     *
     * @param  string $name
     * @return static
     */
    public function setDefaultGuard(string $name): static
    {
        $this->defaultGuard = $name;
        return $this;
    }

    /**
     * Get the current default guard name.
     *
     * @return string
     */
    public function getDefaultGuard(): string
    {
        return $this->defaultGuard;
    }

    /**
     * Clear a resolved guard instance from the cache.
     *
     * Useful in tests to force a fresh guard on the next call.
     *
     * @param  string|null $name  Guard name, or null to clear all.
     * @return static
     */
    public function forgetGuard(?string $name = null): static
    {
        if ($name === null) {
            $this->guards = [];
        } else {
            unset($this->guards[$name]);
        }

        return $this;
    }
}