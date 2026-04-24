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
}