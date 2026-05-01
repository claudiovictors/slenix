<?php

/*
|--------------------------------------------------------------------------
| FlashMessage Class — Slenix Framework
|--------------------------------------------------------------------------
|
| This class manages flash messages within the session. Flash messages are 
| temporary notifications (success, error, etc.) that persist only until 
| the next request.
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Libraries;

use Slenix\Supports\Security\Session;

class FlashMessage
{
    /**
     * Store a success flash message.
     * @param string $message
     * @return static
     */
    public function success(string $message): static
    {
        Session::flash('_flash_success', $message);
        return $this;
    }

    /**
     * Store an error flash message.
     * @param string $message
     * @return static
     */
    public function error(string $message): static
    {
        Session::flash('_flash_error', $message);
        return $this;
    }

    /**
     * Store a warning flash message.
     * @param string $message
     * @return static
     */
    public function warning(string $message): static
    {
        Session::flash('_flash_warning', $message);
        return $this;
    }

    /**
     * Store an info flash message.
     * @param string $message
     * @return static
     */
    public function info(string $message): static
    {
        Session::flash('_flash_info', $message);
        return $this;
    }

    /**
     * Write a value to a custom flash key.
     * @param string $key
     * @param mixed $value
     * @return static
     */
    public function write(string $key, mixed $value): static
    {
        Session::flash($key, $value);
        return $this;
    }

    /**
     * Determine if a flash message exists for a key.
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool
    {
        return Session::hasFlash($key) || Session::hasFlash('_flash_' . $key);
    }

    /**
     * Get the value of a flash message.
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (Session::hasFlash($key)) {
            return Session::getFlash($key, $default);
        }
        return Session::getFlash('_flash_' . $key, $default);
    }

    /**
     * Get all flash messages.
     * @return array
     */
    public function all(): array
    {
        return $_SESSION['_flash'] ?? [];
    }
}