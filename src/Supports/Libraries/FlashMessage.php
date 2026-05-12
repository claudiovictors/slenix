<?php

/*
|--------------------------------------------------------------------------
| FlashMessage Class — Slenix Framework
|--------------------------------------------------------------------------
|
| Manages typed flash messages within the session. Flash messages are
| temporary notifications that persist only until the next request.
|
| Usage:
|   flash()->success('Salvo com sucesso!');
|   flash()->error('Algo correu mal.');
|   flash()->has('success');
|   flash()->get('success');
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Libraries;

use Slenix\Supports\Security\Session;

class FlashMessage
{
    /**
     * Supported built-in message types.
     * Each type maps to the session key '_flash_{type}'.
     */
    private const TYPES = ['success', 'error', 'warning', 'info'];

    // -------------------------------------------------------------------------
    // Built-in message types
    // -------------------------------------------------------------------------

    /**
     * Store a success flash message.
     *
     * @param  string $message
     * @return static
     */
    public function success(string $message): static
    {
        return $this->write('_flash_success', $message);
    }

    /**
     * Store an error flash message.
     *
     * @param  string $message
     * @return static
     */
    public function error(string $message): static
    {
        return $this->write('_flash_error', $message);
    }

    /**
     * Store a warning flash message.
     *
     * @param  string $message
     * @return static
     */
    public function warning(string $message): static
    {
        return $this->write('_flash_warning', $message);
    }

    /**
     * Store an info flash message.
     *
     * @param  string $message
     * @return static
     */
    public function info(string $message): static
    {
        return $this->write('_flash_info', $message);
    }

    // -------------------------------------------------------------------------
    // Generic write / read
    // -------------------------------------------------------------------------

    /**
     * Write a value to a custom flash key.
     *
     * Normalizes the key: 'success' and '_flash_success' both map to the
     * same underlying session key '_flash_success'.
     *
     * @param  string $key
     * @param  mixed  $value
     * @return static
     */
    public function write(string $key, mixed $value): static
    {
        Session::flash($this->normalizeKey($key), $value);
        return $this;
    }

    /**
     * Get the value of a flash message (consumed on first read).
     *
     * Accepts both shorthand ('success') and full keys ('_flash_success').
     *
     * @param  string $key
     * @param  mixed  $default
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return Session::getFlash($this->normalizeKey($key), $default);
    }

    /**
     * Peek at a flash value without consuming it.
     *
     * Unlike get(), the value remains available for the current request.
     *
     * @param  string $key
     * @param  mixed  $default
     * @return mixed
     */
    public function peek(string $key, mixed $default = null): mixed
    {
        return $_SESSION['_flash'][$this->normalizeKey($key)] ?? $default;
    }

    /**
     * Check if a flash message exists.
     *
     * @param  string $key
     * @return bool
     */
    public function has(string $key): bool
    {
        return Session::hasFlash($this->normalizeKey($key));
    }

    /**
     * Returns all current flash messages as an associative array.
     *
     * Keys are stripped of the '_flash_' prefix for convenience.
     * Example: ['success' => 'Salvo!', 'error' => 'Falhou.']
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $flash = $_SESSION['_flash'] ?? [];
        $result = [];

        foreach ($flash as $key => $value) {
            $cleanKey = str_starts_with($key, '_flash_') ? substr($key, 7) : $key;
            $result[$cleanKey] = $value;
        }

        return $result;
    }

    /**
     * Returns only the built-in typed messages (success, error, warning, info).
     *
     * @return array<string, string>
     */
    public function typed(): array
    {
        $result = [];

        foreach (self::TYPES as $type) {
            $key = '_flash_' . $type;
            if (Session::hasFlash($key)) {
                $result[$type] = Session::getFlash($key);
            }
        }

        return $result;
    }

    /**
     * Remove a specific flash message without reading it.
     *
     * @param  string $key
     * @return static
     */
    public function forget(string $key): static
    {
        unset($_SESSION['_flash'][$this->normalizeKey($key)]);
        return $this;
    }

    /**
     * Clear all flash messages.
     *
     * @return static
     */
    public function clear(): static
    {
        unset($_SESSION['_flash']);
        return $this;
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    /**
     * Normalizes a flash key to always use the '_flash_' prefix.
     *
     * 'success'        → '_flash_success'
     * '_flash_success' → '_flash_success'
     * 'my_custom'      → '_flash_my_custom'
     *
     * @param  string $key
     * @return string
     */
    private function normalizeKey(string $key): string
    {
        return str_starts_with($key, '_flash_') ? $key : '_flash_' . $key;
    }
}