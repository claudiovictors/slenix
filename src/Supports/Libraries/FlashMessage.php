<?php

/*
|--------------------------------------------------------------------------
| FlashMessage Class
|--------------------------------------------------------------------------
|
| Typed flash message interface. All reads come from $_SESSION['_flash_previous'],
| which is populated by Session::age() at the start of each request.
| All writes go to $_SESSION['_flash'] for the next request.
|
| Usage:
|   // Writing (current request → next request)
|   flash()->success('Saved successfully!');
|   flash()->error('Something went wrong.');
|   flash()->warning('Please review your input.');
|   flash()->info('You have 3 new messages.');
|
|   // Reading (populated by the previous request)
|   flash()->has('error')        → bool
|   flash()->get('error')        → string|null
|   flash()->all()               → ['success' => '...', 'error' => '...']
|   flash()->typed()             → only success|error|warning|info keys
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Libraries;

use Slenix\Supports\Security\Session;

class FlashMessage
{
    /** @var string[] Built-in typed message keys. */
    private const TYPES = ['success', 'error', 'warning', 'info'];

    // -------------------------------------------------------------------------
    // Typed Writers
    // -------------------------------------------------------------------------

    /**
     * Store a success flash message for the next request.
     *
     * @param  string $message
     * @return static
     */
    public function success(string $message): static
    {
        return $this->write('success', $message);
    }

    /**
     * Store an error flash message for the next request.
     *
     * @param  string $message
     * @return static
     */
    public function error(string $message): static
    {
        return $this->write('error', $message);
    }

    /**
     * Store a warning flash message for the next request.
     *
     * @param  string $message
     * @return static
     */
    public function warning(string $message): static
    {
        return $this->write('warning', $message);
    }

    /**
     * Store an info flash message for the next request.
     *
     * @param  string $message
     * @return static
     */
    public function info(string $message): static
    {
        return $this->write('info', $message);
    }

    // -------------------------------------------------------------------------
    // Generic Write / Read
    // -------------------------------------------------------------------------

    /**
     * Write a flash message under any key for the next request.
     *
     * The key is normalized to always carry the '_flash_' prefix so that
     * 'success' and '_flash_success' resolve to the same session slot.
     *
     * @param  string $key   Short key ('error') or prefixed key ('_flash_error').
     * @param  mixed  $value Any serializable value.
     * @return static
     */
    public function write(string $key, mixed $value): static
    {
        Session::flash($this->normalize($key), $value);
        return $this;
    }

    /**
     * Read a flash message available in the current request.
     *
     * Reads from $_SESSION['_flash_previous'], which was populated by
     * Session::age() at the start of this request. Non-destructive — safe
     * to call multiple times for the same key.
     *
     * @param  string $key     Short or prefixed key.
     * @param  mixed  $default Returned when the key does not exist.
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION['_flash_previous'][$this->normalize($key)] ?? $default;
    }

    /**
     * Check whether a flash message is available in the current request.
     *
     * Reads from $_SESSION['_flash_previous'].
     *
     * @param  string $key Short or prefixed key.
     * @return bool
     */
    public function has(string $key): bool
    {
        return isset($_SESSION['_flash_previous'][$this->normalize($key)]);
    }

    /**
     * Return all flash messages available in the current request.
     *
     * Keys are returned without the '_flash_' prefix.
     *
     * Example return value:
     *   ['success' => 'Saved!', 'error' => 'Failed.']
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $previous = $_SESSION['_flash_previous'] ?? [];
        $result   = [];

        foreach ($previous as $key => $value) {
            if (str_starts_with((string) $key, '_flash_')) {
                $result[substr($key, 7)] = $value;
            }
        }

        return $result;
    }

    /**
     * Return only the four built-in typed messages that are present.
     *
     * Example return value:
     *   ['success' => 'Saved!', 'warning' => 'Check your input.']
     *
     * @return array<string, mixed>
     */
    public function typed(): array
    {
        $result = [];

        foreach (self::TYPES as $type) {
            $key = $this->normalize($type);
            if (isset($_SESSION['_flash_previous'][$key])) {
                $result[$type] = $_SESSION['_flash_previous'][$key];
            }
        }

        return $result;
    }

    /**
     * Peek at a flash value written in the CURRENT request before it is aged.
     *
     * Reads from $_SESSION['_flash']. Useful right after write() when you need
     * to verify the value was stored without waiting for the next request.
     *
     * @param  string $key
     * @param  mixed  $default
     * @return mixed
     */
    public function peek(string $key, mixed $default = null): mixed
    {
        return $_SESSION['_flash'][$this->normalize($key)] ?? $default;
    }

    /**
     * Remove a flash message written in the current request before it is aged.
     *
     * Has no effect on messages already in $_SESSION['_flash_previous'].
     *
     * @param  string $key Short or prefixed key.
     * @return static
     */
    public function forget(string $key): static
    {
        unset($_SESSION['_flash'][$this->normalize($key)]);
        return $this;
    }

    /**
     * Clear all flash messages written in the current request.
     *
     * Does not affect messages already aged into $_SESSION['_flash_previous'].
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
     * Normalize a key to always carry the '_flash_' prefix.
     *
     * Examples:
     *   'error'         → '_flash_error'
     *   '_flash_error'  → '_flash_error'
     *   'my_alert'      → '_flash_my_alert'
     *
     * @param  string $key
     * @return string
     */
    private function normalize(string $key): string
    {
        return str_starts_with($key, '_flash_') ? $key : '_flash_' . $key;
    }
}