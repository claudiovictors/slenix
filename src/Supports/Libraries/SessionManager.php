<?php

/*
|--------------------------------------------------------------------------
| SessionManager Class — Slenix Framework
|--------------------------------------------------------------------------
|
| Object-oriented interface for the native session layer. Provides 
| methods for reading, writing, removing, and flashing session data 
| with a fluent API.
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Libraries;

use Slenix\Supports\Security\Session;

class SessionManager
{
    /**
     * Set a value in the session.
     * @param string $key
     * @param mixed $value
     * @return static
     */
    public function set(string $key, mixed $value): static
    {
        Session::set($key, $value);
        return $this;
    }

    /**
     * Get a value from the session.
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return Session::get($key, $default);
    }

    /**
     * Check if key exists.
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool
    {
        return Session::has($key);
    }

    /**
     * Check if key is missing.
     * @param string $key
     * @return bool
     */
    public function missing(string $key): bool
    {
        return !Session::has($key);
    }

    /** @return array All session data. */
    public function all(): array
    {
        return Session::all();
    }

    /**
     * Put one or many items into the session.
     */
    public function put(string|array $key, mixed $value = null): static
    {
        if (is_array($key)) {
            foreach ($key as $k => $v) Session::set((string)$k, $v);
        } else {
            Session::set($key, $value);
        }
        return $this;
    }

    /**
     * Push a value onto a session array.
     */
    public function push(string $key, mixed $value): static
    {
        $arr = (array) Session::get($key, []);
        $arr[] = $value;
        Session::set($key, $arr);
        return $this;
    }

    /**
     * Get an item and remove it.
     */
    public function pull(string $key, mixed $default = null): mixed
    {
        $value = Session::get($key, $default);
        Session::remove($key);
        return $value;
    }

    /**
     * Remove one or many items.
     */
    public function forget(string|array $keys): static
    {
        foreach ((array) $keys as $key) Session::remove($key);
        return $this;
    }

    /**
     * Clear all session data without destroying it.
     */
    public function flush(): static
    {
        Session::start();
        $_SESSION = [];
        return $this;
    }

    /** @return string Current session ID. */
    public function id(): string
    {
        Session::start();
        return session_id();
    }

    /**
     * Completely destroy the session.
     */
    public function invalidate(): static
    {
        Session::destroy();
        return $this;
    }

    /**
     * Regenerate the session ID.
     */
    public function regenerate(bool $deleteOld = true): static
    {
        Session::regenerateId($deleteOld);
        return $this;
    }

    /**
     * Store a temporary flash value.
     */
    public function flash(string $key, mixed $value): static
    {
        Session::flash($key, $value);
        return $this;
    }

    public function getFlash(string $key, mixed $default = null): mixed
    {
        return Session::getFlash($key, $default);
    }

    public function hasFlash(string $key): bool
    {
        return Session::hasFlash($key);
    }

    /**
     * Store input as flash data.
     */
    public function flashInput(array $data): static
    {
        unset($data['password'], $data['password_confirmation'], $data['_token']);
        Session::flashOldInput($data);
        return $this;
    }

    /**
     * Increment a numeric value in the session.
     */
    public function increment(string $key, int $amount = 1): int
    {
        $new = ((int) Session::get($key, 0)) + $amount;
        Session::set($key, $new);
        return $new;
    }

    /**
     * Decrement a numeric value in the session.
     */
    public function decrement(string $key, int $amount = 1): int
    {
        return $this->increment($key, -$amount);
    }
}