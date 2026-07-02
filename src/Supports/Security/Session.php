<?php

/*
|--------------------------------------------------------------------------
| Session Class
|--------------------------------------------------------------------------
|
| Secure session management interface. Handles cookie security,
| data persistence, and one-time flash data.
|
| Flash lifecycle (mirrors Laravel):
|   1. Data is written to $_SESSION['_flash'] during a request.
|   2. At the START of the NEXT request, age() moves _flash → _flash_previous
|      and clears _flash. Views read from _flash_previous.
|   3. At the start of the request after that, _flash_previous is cleared.
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Security;

use Slenix\Core\EnvLoader;

class Session
{
    // -------------------------------------------------------------------------
    // Bootstrap
    // -------------------------------------------------------------------------

    /**
     * Initializes the session with security flags.
     *
     * Safe to call multiple times — checks session status before starting.
     *
     * @return void
     */
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            if (strtolower((string) EnvLoader::get('SESSION_DRIVER', 'native')) === 'redis') {
                session_set_save_handler(new RedisSessionHandler(), true);
            }

            session_start([
                'cookie_secure' => isset($_SERVER['HTTPS']),
                'cookie_httponly' => true,
                'cookie_samesite' => 'Lax',
                'use_strict_mode' => true,
                'use_cookies' => true,
                'use_only_cookies' => true,
                'gc_maxlifetime' => (int) EnvLoader::get('SESSION_LIFETIME', 7200),
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Core CRUD
    // -------------------------------------------------------------------------

    /**
     * Store a value in the session.
     *
     * @param  string $key
     * @param  mixed  $value
     * @return void
     */
    public static function set(string $key, mixed $value): void
    {
        self::start();
        $_SESSION[$key] = $value;
    }

    /**
     * Retrieve a value from the session.
     *
     * @param  string $key
     * @param  mixed  $default Returned when the key does not exist.
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        self::start();
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Check whether a key exists in the session.
     *
     * @param  string $key
     * @return bool
     */
    public static function has(string $key): bool
    {
        self::start();
        return isset($_SESSION[$key]);
    }

    /**
     * Return all session variables.
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        self::start();
        return $_SESSION ?? [];
    }

    /**
     * Remove a specific key from the session.
     *
     * @param  string $key
     * @return void
     */
    public static function remove(string $key): void
    {
        self::start();
        unset($_SESSION[$key]);
    }

    // -------------------------------------------------------------------------
    // Identity
    // -------------------------------------------------------------------------

    /**
     * Return the current session ID.
     *
     * @return string
     */
    public static function id(): string
    {
        self::start();
        return session_id();
    }

    /**
     * Regenerate the session ID to prevent session fixation attacks.
     *
     * @param  bool $deleteOldSession Whether to delete the old session file.
     * @return bool
     */
    public static function regenerateId(bool $deleteOldSession = false): bool
    {
        self::start();
        return session_regenerate_id($deleteOldSession);
    }

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    /**
     * Clear all session data without destroying the session itself.
     *
     * Unlike destroy(), the session ID and cookie are preserved.
     *
     * @return void
     */
    public static function flush(): void
    {
        self::start();
        $_SESSION = [];
    }

    /**
     * Clear and destroy the current session completely.
     *
     * Expires the session cookie and calls session_destroy().
     *
     * @return void
     */
    public static function destroy(): void
    {
        self::start();
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
    }

    // -------------------------------------------------------------------------
    // Flash — Write
    // -------------------------------------------------------------------------

    /**
     * Store a flash value that will be available for the next request only.
     *
     * Data is written to $_SESSION['_flash']. On the next request, age()
     * promotes it to $_SESSION['_flash_previous'] where views can read it.
     *
     * @param  string $key
     * @param  mixed  $value
     * @return void
     */
    public static function flash(string $key, mixed $value): void
    {
        self::start();
        $_SESSION['_flash'][$key] = $value;
    }

    /**
     * Flash an associative array of old form input for repopulation via old().
     *
     * Stores under the single key '_old_input' so old() can retrieve it
     * as an array. Sensitive fields must be stripped by the caller.
     *
     * @param  array<string, mixed> $data Form input to store.
     * @return void
     */
    public static function flashInput(array $data): void
    {
        self::flash('_old_input', $data);
    }

    // -------------------------------------------------------------------------
    // Flash — Read
    // -------------------------------------------------------------------------

    /**
     * Read a flash value that was stored in the PREVIOUS request.
     *
     * Reads from $_SESSION['_flash_previous'], which is populated by age()
     * at the start of each request. Safe to call multiple times — non-destructive.
     *
     * @param  string $key
     * @param  mixed  $default
     * @return mixed
     */
    public static function getFlash(string $key, mixed $default = null): mixed
    {
        self::start();
        return $_SESSION['_flash_previous'][$key] ?? $default;
    }

    /**
     * Check whether a flash value exists for the current request.
     *
     * Reads from $_SESSION['_flash_previous'].
     *
     * @param  string $key
     * @return bool
     */
    public static function hasFlash(string $key): bool
    {
        self::start();
        return isset($_SESSION['_flash_previous'][$key]);
    }

    // -------------------------------------------------------------------------
    // Flash — Lifecycle
    // -------------------------------------------------------------------------

    /**
     * Age the flash data by one request cycle.
     *
     * Must be called ONCE at the very start of each request (in the Kernel),
     * BEFORE any route or controller runs.
     *
     * What it does:
     *   1. Discards $_SESSION['_flash_previous'] (data from two requests ago).
     *   2. Promotes $_SESSION['_flash'] → $_SESSION['_flash_previous'].
     *   3. Clears $_SESSION['_flash'] so it is ready for new writes.
     *
     * @return void
     */
    public static function age(): void
    {
        self::start();

        // Discard data that already survived one full cycle
        unset($_SESSION['_flash_previous']);

        // Promote current flash → previous
        if (!empty($_SESSION['_flash'])) {
            $_SESSION['_flash_previous'] = $_SESSION['_flash'];
        }

        // Clear current flash bucket for new writes this request
        unset($_SESSION['_flash']);
    }

    /**
     * Re-flash all or specific keys from the previous request into the current
     * flash bucket so they survive one more request cycle.
     *
     * Useful when a redirect chain spans more than one hop.
     *
     * @param  array<string>|null $keys Specific keys to keep, or null for all.
     * @return void
     */
    public static function keepFlash(?array $keys = null): void
    {
        self::start();

        $previous = $_SESSION['_flash_previous'] ?? [];

        if (empty($previous)) {
            return;
        }

        $targets = $keys === null ? array_keys($previous) : $keys;

        foreach ($targets as $key) {
            if (array_key_exists($key, $previous)) {
                $_SESSION['_flash'][$key] = $previous[$key];
            }
        }
    }

    // -------------------------------------------------------------------------
    // Data Manipulation
    // -------------------------------------------------------------------------

    /**
     * Retrieve a value and immediately remove it from the session.
     *
     * Useful for one-time tokens or nonces that should be consumed on first read.
     *
     * @param  string $key
     * @param  mixed  $default
     * @return mixed
     */
    public static function pull(string $key, mixed $default = null): mixed
    {
        self::start();
        $value = $_SESSION[$key] ?? $default;
        unset($_SESSION[$key]);
        return $value;
    }

    /**
     * Append a value to a session key that holds an array.
     *
     * Initializes the key as an empty array if it does not exist yet.
     *
     * @param  string $key
     * @param  mixed  $value
     * @throws \TypeError If the existing value is not an array.
     * @return void
     */
    public static function push(string $key, mixed $value): void
    {
        self::start();

        $current = $_SESSION[$key] ?? [];

        if (!is_array($current)) {
            throw new \TypeError("Session::push() — key '{$key}' is not an array.");
        }

        $current[] = $value;
        $_SESSION[$key] = $current;
    }

    /**
     * Increment a numeric session value by a given amount.
     *
     * Initializes the key to 0 if it does not exist.
     *
     * @param  string $key
     * @param  int    $by  Amount to add (default: 1).
     * @return int         New value.
     */
    public static function increment(string $key, int $by = 1): int
    {
        self::start();
        $value = (int) ($_SESSION[$key] ?? 0) + $by;
        $_SESSION[$key] = $value;
        return $value;
    }

    /**
     * Decrement a numeric session value by a given amount.
     *
     * Initializes the key to 0 if it does not exist.
     *
     * @param  string $key
     * @param  int    $by  Amount to subtract (default: 1).
     * @return int         New value.
     */
    public static function decrement(string $key, int $by = 1): int
    {
        self::start();
        $value = (int) ($_SESSION[$key] ?? 0) - $by;
        $_SESSION[$key] = $value;
        return $value;
    }

    // -------------------------------------------------------------------------
    // Filtering
    // -------------------------------------------------------------------------

    /**
     * Return a subset of session data for the given keys only.
     *
     * @param  array<string> $keys
     * @return array<string, mixed>
     */
    public static function only(array $keys): array
    {
        self::start();
        return array_intersect_key($_SESSION ?? [], array_flip($keys));
    }

    /**
     * Return all session data except the given keys.
     *
     * @param  array<string> $keys
     * @return array<string, mixed>
     */
    public static function except(array $keys): array
    {
        self::start();
        return array_diff_key($_SESSION ?? [], array_flip($keys));
    }

    // -------------------------------------------------------------------------
    // Security
    // -------------------------------------------------------------------------

    /**
     * Return the CSRF token stored in the session.
     *
     * Generates and stores a new token if one does not exist yet.
     *
     * @return string 64-character hex token.
     */
    public static function token(): string
    {
        self::start();

        if (empty($_SESSION['_token'])) {
            $_SESSION['_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['_token'];
    }
}