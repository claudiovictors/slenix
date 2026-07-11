<?php

/*
|--------------------------------------------------------------------------
| RedirectResponse Class
|--------------------------------------------------------------------------
|
| Fluent HTTP redirect builder. Attach flash messages, validation errors,
| and old form input before the redirect fires.
|
| Usage:
|   redirect('/home');
|   redirect()->back();
|   redirect()->route('dashboard');
|   redirect()->back()->with('status', 'Profile updated!');
|   redirect()->back()->withErrors(['email' => 'Invalid email.']);
|   redirect()->back()->withInput();
|   redirect('/login')->with('error', 'Session expired.');
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

    /** @var array<string, mixed> Flash data queued for commit before redirect. */
    private array $flashData = [];

    /** @var array<string, mixed>|null Old input to flash (null = not set). */
    private ?array $oldInput = null;

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
     * Commits all queued flash data to the session, forces a session write,
     * then sends the Location header and exits.
     *
     * Control characters are stripped to prevent header injection.
     *
     * @param  string $url Absolute or relative URL.
     * @return never
     */
    public function to(string $url): never
    {
        $this->commitFlash();

        // Force PHP to write the session to disk before the process exits.
        // Without this, session data written after the last automatic write
        // can be lost when exit is called immediately after header().
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $url = str_replace(["\r", "\n", "\0"], '', $url);
        header("Location: {$url}", true, $this->status);
        exit;
    }

    /**
     * Redirect back to the previous page using the HTTP Referer header.
     *
     * Must be called LAST in the chain — fires the redirect immediately.
     * Attach flash data before calling back():
     *   redirect()->withFlash('error', 'msg')->back();
     *
     * @param  string $fallback URL to use when Referer is unavailable (default: '/').
     * @return never
     */
    public function back(string $fallback = '/'): never
    {
        $this->to($_SERVER['HTTP_REFERER'] ?? $fallback);
    }

    /**
     * Redirect to a named route.
     *
     * Falls back to '/' when the route name cannot be resolved.
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
     * Redirect with a permanent (301) status code.
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
     * The value will be available via Session::getFlash($key) on the next request.
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
     * @param  array<string, mixed> $data Key → value pairs to flash.
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
     * Attach a typed flash message (success, error, warning, info).
     *
     * Stored under the key '_flash_{type}' and read by FlashMessage::get().
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

    /**
     * Attach validation errors to be read by the errors() helper.
     *
     * Errors are stored under '_errors' as a named bag so multiple validators
     * can coexist without overwriting each other.
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
     * Sensitive fields (password, password_confirmation, _csrf_token) are
     * stripped automatically. Stored under '_old_input' as a single array.
     *
     * @param  array<string, mixed>|null $input Custom input (defaults to $_POST).
     * @return static
     */
    public function withInput(?array $input = null): static
    {
        $input = $input ?? $_POST;
        unset($input['password'], $input['password_confirmation'], $input['_csrf_token']);
        $this->oldInput = $input;
        return $this;
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    /**
     * Write all queued flash data and old input to the session.
     *
     * Called internally by to() before session_write_close().
     *
     * @return void
     */
    private function commitFlash(): void
    {
        foreach ($this->flashData as $key => $value) {
            Session::flash($key, $value);
        }

        if ($this->oldInput !== null) {
            Session::flashInput($this->oldInput);
        }
    }
}