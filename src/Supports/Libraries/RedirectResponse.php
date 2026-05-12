<?php

/*
|--------------------------------------------------------------------------
| RedirectResponse Class — Slenix Framework
|--------------------------------------------------------------------------
|
| Handles HTTP redirection responses fluently. Provides methods to redirect
| to URLs, named routes, or the previous page — while carrying flash
| messages, validation errors, and old form input.
|
| Usage:
|   redirect('/home');
|   redirect()->back();
|   redirect()->route('dashboard');
|   redirect('/login')->with('error', 'Sessão expirada')->withInput();
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Libraries;

use Slenix\Http\Routing\Router;
use Slenix\Supports\Security\Session;

class RedirectResponse
{
    /** @var int HTTP status code for this redirect. */
    private int $status;

    /** @var array<string, mixed> Flash data to commit before redirecting. */
    private array $flashData = [];

    /**
     * @param int $status HTTP redirect status code (default: 302).
     */
    public function __construct(int $status = 302)
    {
        $this->status = $status;
    }

    // -------------------------------------------------------------------------
    // Destinations
    // -------------------------------------------------------------------------

    /**
     * Redirect to a specific URL.
     *
     * Strips control characters to prevent header injection attacks.
     *
     * @param  string $url Absolute or relative URL.
     * @return never
     */
    public function to(string $url): never
    {
        $this->commitFlash();
        $url = str_replace(["\r", "\n", "\0"], '', $url);
        header("Location: {$url}", true, $this->status);
        exit;
    }

    /**
     * Redirect back to the previous page (HTTP_REFERER).
     *
     * Falls back to $fallback if the referer header is missing.
     *
     * @param  string $fallback URL to use when referer is unavailable (default: '/').
     * @return never
     */
    public function back(string $fallback = '/'): never
    {
        $this->to($_SERVER['HTTP_REFERER'] ?? $fallback);
    }

    /**
     * Redirect to a named route.
     *
     * Falls back to '/' if the route cannot be resolved.
     *
     * @param  string               $name   Route name.
     * @param  array<string, mixed> $params Route parameters.
     * @return never
     */
    public function route(string $name, array $params = []): never
    {
        $this->to(Router::route($name, $params) ?? '/');
    }

    /**
     * Redirect with a permanent status code (301).
     *
     * @param  string $url Target URL.
     * @return never
     */
    public function permanent(string $url): never
    {
        $this->status = 301;
        $this->to($url);
    }

    // -------------------------------------------------------------------------
    // Flash Data
    // -------------------------------------------------------------------------

    /**
     * Attach a single flash value to the redirect.
     *
     * @param  string $key
     * @param  mixed  $value
     * @return static
     */
    public function with(string $key, mixed $value): static
    {
        $this->flashData[$key] = $value;
        return $this;
    }

    /**
     * Attach multiple flash values at once.
     *
     * @param  array<string, mixed> $data
     * @return static
     */
    public function withMany(array $data): static
    {
        foreach ($data as $key => $value) {
            $this->flashData[$key] = $value;
        }
        return $this;
    }

    /**
     * Attach validation errors to the session under a named bag.
     *
     * Errors are stored as '_errors' and consumed by the errors() helper.
     *
     * @param  array<string, string|string[]> $errors Field → message(s) map.
     * @param  string                         $bag    Error bag name (default: 'default').
     * @return static
     */
    public function withErrors(array $errors, string $bag = 'default'): static
    {
        $existing = $this->flashData['_errors'] ?? [];
        $existing[$bag] = $errors;
        $this->flashData['_errors'] = $existing;
        return $this;
    }

    /**
     * Flash the current form input for repopulation via old().
     *
     * Sensitive fields (password, password_confirmation, _token) are
     * automatically stripped before flashing.
     *
     * @param  array<string, mixed>|null $input Custom input array (default: $_POST).
     * @return static
     */
    public function withInput(?array $input = null): static
    {
        $input ??= $_POST;
        unset($input['password'], $input['password_confirmation'], $input['_token']);
        $this->flashData['_old_input'] = $input;
        return $this;
    }

    /**
     * Attach a typed flash message (success, error, warning, info).
     *
     * Shorthand for ->with('_flash_success', 'message'), compatible with
     * the FlashMessage class and flash() helper.
     *
     * @param  string $type    One of: success, error, warning, info.
     * @param  string $message Message text.
     * @return static
     */
    public function withFlash(string $type, string $message): static
    {
        $this->flashData['_flash_' . $type] = $message;
        return $this;
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    /**
     * Commits all pending flash data to the session before the redirect fires.
     *
     * @return void
     */
    private function commitFlash(): void
    {
        foreach ($this->flashData as $key => $value) {
            Session::flash($key, $value);
        }
    }
}