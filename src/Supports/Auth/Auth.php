<?php

/*
|--------------------------------------------------------------------------
| Auth
|--------------------------------------------------------------------------
|
| Singleton container for the AuthManager instance and entry point
| for the global auth() helper function.
|
| The Auth class holds a single AuthManager for the entire request
| lifecycle. The auth() function is the developer-facing API,
| mirroring the simplicity of Laravel's auth() helper.
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Auth;

use Slenix\Supports\Auth\Guards\GuardInterface;

final class Auth
{
    /**
     * The singleton AuthManager instance for the current request.
     *
     * @var AuthManager|null
     */
    private static ?AuthManager $manager = null;

    /**
     * Get the singleton AuthManager instance, creating it on first call.
     *
     * The default guard is read from the APP_AUTH_GUARD environment variable,
     * falling back to 'web' if not set.
     *
     * @internal Use the auth() helper instead of calling this directly.
     * @return AuthManager
     */
    public static function manager(): AuthManager
    {
        if (static::$manager === null) {
            static::$manager = new AuthManager(env('APP_AUTH_GUARD', 'web'));
        }

        return static::$manager;
    }

    /**
     * Resolve a guard instance or return the AuthManager.
     *
     * When $guard is null   → returns the AuthManager (proxies the default guard).
     * When $guard is 'api'  → returns the JwtGuard instance directly.
     * When $guard is 'web'  → returns the SessionGuard instance directly.
     *
     * @param  string|null $guard  Guard name ('web', 'api', …) or null for the manager.
     * @return AuthManager|GuardInterface
     */
    public static function resolve(?string $guard = null): AuthManager|GuardInterface
    {
        $manager = static::manager();

        return $guard === null ? $manager : $manager->guard($guard);
    }

    /**
     * Replace the singleton with a custom AuthManager.
     *
     * Useful in tests to inject a mock or a different default guard.
     *
     * @example Auth::swap(new AuthManager('api'));
     *
     * @param  AuthManager $manager
     * @return void
     */
    public static function swap(AuthManager $manager): void
    {
        static::$manager = $manager;
    }

    /**
     * Clear the singleton instance.
     *
     * Call this between test cases or between requests in long-running
     * processes (Swoole, RoadRunner) to reset authentication state.
     *
     * @return void
     */
    public static function flush(): void
    {
        static::$manager = null;
    }

    /** @codeCoverageIgnore */
    private function __construct() {}
}