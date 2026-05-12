<?php

/*
|--------------------------------------------------------------------------
| Session Class
|--------------------------------------------------------------------------
|
| Secure session management interface. Handles cookie security,
| data persistence, and one-time flash data.
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Security;

class Session
{
    /**
     * Initializes the session with security flags.
     * @return void
     */
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start([
                'cookie_secure'   => isset($_SERVER['HTTPS']),
                'cookie_httponly' => true,
                'cookie_samesite' => 'Lax',
                'use_strict_mode' => true,
                'use_cookies'     => true,
                'use_only_cookies'=> true,
            ]);
        }
    }

    /**
     * Stores a value in the session.
     * @param string $key
     * @param mixed $value
     */
    public static function set(string $key, mixed $value): void
    {
        self::start();
        $_SESSION[$key] = $value;
    }

    /**
     * Retrieves a value from the session.
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        self::start();
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Checks for existence of a key.
     * @param string $key
     * @return bool
     */
    public static function has(string $key): bool
    {
        self::start();
        return isset($_SESSION[$key]);
    }

    /**
     * Returns all session variables.
     * @return array
     */
    public static function all(): array
    {
        self::start();
        return $_SESSION ?? [];
    }

    /**
     * Removes a specific item from the session.
     * @param string $key
     */
    public static function remove(string $key): void
    {
        self::start();
        unset($_SESSION[$key]);
    }

    /**
     * Regenerates the session ID to prevent fixation attacks.
     * @param bool $deleteOldSession
     * @return bool
     */
    public static function regenerateId(bool $deleteOldSession = false): bool
    {
        self::start();
        return session_regenerate_id($deleteOldSession);
    }

    /**
     * Clears and destroys the current session.
     * @return void
     */
    public static function destroy(): void
    {
        self::start();
        $_SESSION = [];

        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }

        session_destroy();
    }

    /**
     * Stores flash data for the next request only.
     * @param string $key
     * @param mixed $value
     */
    public static function flash(string $key, mixed $value): void
    {
        self::start();
        $_SESSION['_flash'][$key] = $value;
    }

    /**
     * Retrieves flash data and deletes it immediately.
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function getFlash(string $key, mixed $default = null): mixed
    {
        self::start();
        $value = $_SESSION['_flash'][$key] ?? $default;
        unset($_SESSION['_flash'][$key]);
        if (empty($_SESSION['_flash'])) unset($_SESSION['_flash']);
        return $value;
    }

    /**
     * Checks if flash data exists for a key.
     * @param string $key
     * @return bool
     */
    public static function hasFlash(string $key): bool
    {
        self::start();
        return isset($_SESSION['_flash'][$key]);
    }

    /**
     * Flashes an array of input (useful for forms).
     * @param array $data
     */
    public static function flashOldInput(array $data): void
    {
        self::start();
        foreach ($data as $key => $value) {
            $_SESSION['_flash']['_old_input_' . $key] = $value;
        }
    }

    // -------------------------------------------------------------------------
    // Flash Lifecycle
    // -------------------------------------------------------------------------

    /**
     * Ages the flash data by one request cycle.
     *
     * Moves current flash data to a "_previous" bucket and clears the
     * previous bucket. This must be called once per request (e.g. in a
     * middleware or at the start of the Kernel) so flash data lives for
     * exactly one request.
     *
     * Without this, old() data from a login form will still be present
     * when the user visits the dashboard after logout.
     *
     * Usage in Kernel or Middleware:
     *   Session::age();
     *
     * @return void
     */
    public static function age(): void
    {
        self::start();

        // Discard data that already survived one request
        unset($_SESSION['_flash_previous']);

        // Promote current flash to previous
        if (!empty($_SESSION['_flash'])) {
            $_SESSION['_flash_previous'] = $_SESSION['_flash'];
        }

        // Clear current flash — it has been consumed
        unset($_SESSION['_flash']);
    }

    /**
     * Keeps flash data alive for one more request cycle (reflash).
     *
     * Useful when a redirect chain is longer than one hop and you need
     * the flash data to survive an extra request.
     *
     * Usage:
     *   Session::keepFlash();           // keep all flash
     *   Session::keepFlash(['email']);   // keep specific keys only
     *
     * @param  array<string>|null $keys Specific keys to keep, or null to keep all.
     * @return void
     */
    public static function keepFlash(?array $keys = null): void
    {
        self::start();

        $previous = $_SESSION['_flash_previous'] ?? [];

        if (empty($previous)) {
            return;
        }

        if ($keys === null) {
            // Re-flash everything from previous
            foreach ($previous as $key => $value) {
                $_SESSION['_flash'][$key] = $value;
            }
        } else {
            // Re-flash only the requested keys
            foreach ($keys as $key) {
                if (array_key_exists($key, $previous)) {
                    $_SESSION['_flash'][$key] = $previous[$key];
                }
            }
        }
    }

    /**
     * Clears all old input flash data without touching the rest of the session.
     *
     * Useful after a successful form submission or login to ensure stale
     * input values do not bleed into subsequent views.
     *
     * Usage:
     *   Session::clearOldInput();
     *
     * @return void
     */
    public static function clearOldInput(): void
    {
        self::start();

        if (!isset($_SESSION['_flash'])) {
            return;
        }

        foreach (array_keys($_SESSION['_flash']) as $key) {
            if (str_starts_with((string) $key, '_old_input_')) {
                unset($_SESSION['_flash'][$key]);
            }
        }

        if (empty($_SESSION['_flash'])) {
            unset($_SESSION['_flash']);
        }
    }

    // -------------------------------------------------------------------------
    // Data Manipulation
    // -------------------------------------------------------------------------

    /**
     * Retrieves a value and immediately removes it from the session (get + remove).
     *
     * Useful for one-time tokens, nonces, or any value that should be
     * consumed on first read.
     *
     * Usage:
     *   $token = Session::pull('pending_token');
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
     * Appends a value to a session key that holds an array.
     *
     * If the key does not exist yet, it is initialized as an empty array.
     * If the key exists but is not an array, a TypeError is thrown.
     *
     * Usage:
     *   Session::push('cart', ['id' => 5, 'qty' => 1]);
     *   Session::push('roles', 'editor');
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
     * Increments a numeric session value by a given amount.
     *
     * Initializes the key to 0 before incrementing if it does not exist.
     *
     * Usage:
     *   Session::increment('login_attempts');
     *   Session::increment('login_attempts', 2);
     *
     * @param  string $key
     * @param  int    $by
     * @return int New value.
     */
    public static function increment(string $key, int $by = 1): int
    {
        self::start();
        $value = (int) ($_SESSION[$key] ?? 0) + $by;
        $_SESSION[$key] = $value;
        return $value;
    }

    /**
     * Decrements a numeric session value by a given amount.
     *
     * Initializes the key to 0 before decrementing if it does not exist.
     *
     * Usage:
     *   Session::decrement('credits');
     *   Session::decrement('credits', 5);
     *
     * @param  string $key
     * @param  int    $by
     * @return int New value.
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
     * Returns a subset of session data for the given keys only.
     *
     * Usage:
     *   $data = Session::only(['user_id', 'locale']);
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
     * Returns all session data except the given keys.
     *
     * Usage:
     *   $safe = Session::except(['_flash', '_token']);
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
    // Utilities
    // -------------------------------------------------------------------------

    /**
     * Clears all session data without destroying the session itself.
     *
     * Unlike destroy(), the session ID and cookie are preserved.
     * Useful when you want to reset state but keep the session alive
     * (e.g. switching user context in tests).
     *
     * Usage:
     *   Session::flush();
     *
     * @return void
     */
    public static function flush(): void
    {
        self::start();
        $_SESSION = [];
    }

    /**
     * Returns the current session ID.
     *
     * @return string
     */
    public static function id(): string
    {
        self::start();
        return session_id();
    }

    /**
     * Returns the CSRF token stored in the session.
     *
     * Creates and stores a new token if one does not exist yet.
     * This complements the CSRF class which handles verification.
     *
     * Usage:
     *   $token = Session::token();
     *   // In views: <input type="hidden" name="_token" value="<?= Session::token() ?>">
     *
     * @return string
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