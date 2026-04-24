<?php

/*
|--------------------------------------------------------------------------
| RedirectResponse Class — Slenix Framework
|--------------------------------------------------------------------------
|
| Handles HTTP redirection responses fluently. It provides methods to redirect 
| to URLs, named routes, or previous pages while carrying flash messages, 
| validation errors, and old input data.
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Libraries;

use Slenix\Http\Routing\Router;
use Slenix\Supports\Security\Session;

class RedirectResponse
{
    /** @var int HTTP status code */
    private int $status;

    /** @var array<string, mixed> Pending flash data to be sent */
    private array $flashData = [];

    /**
     * @param int $status HTTP status code (default 302).
     */
    public function __construct(int $status = 302)
    {
        $this->status = $status;
    }

    /**
     * Redirect to a specific URL.
     * * @param string $url
     * @return never
     */
    public function to(string $url): never
    {
        $this->sendFlash();
        $url = str_replace(["\r", "\n", "\0"], '', $url);
        header("Location: {$url}", true, $this->status);
        exit;
    }

    /**
     * Redirect back to the previous page.
     * * @param string $fallback URL if HTTP_REFERER is missing.
     * @return never
     */
    public function back(string $fallback = '/'): never
    {
        $this->to($_SERVER['HTTP_REFERER'] ?? $fallback);
    }

    /**
     * Redirect to a named route.
     * * @param string $name
     * @param array  $params
     * @return never
     */
    public function route(string $name, array $params = []): never
    {
        $this->to(Router::route($name, $params) ?? '/');
    }

    /**
     * Attach a flash message to the redirect.
     * * @param string $key
     * @param mixed  $value
     * @return static
     */
    public function with(string $key, mixed $value): static
    {
        $this->flashData[$key] = $value;
        return $this;
    }

    /**
     * Attach multiple flash values.
     */
    public function withMany(array $data): static
    {
        foreach ($data as $key => $value) {
            $this->flashData[$key] = $value;
        }
        return $this;
    }

    /**
     * Attach validation errors to the session.
     */
    public function withErrors(array $errors, string $bag = 'default'): static
    {
        $this->flashData['_errors'][$bag] = $errors;
        return $this;
    }

    /**
     * Flash current input data to the session for repopulation.
     */
    public function withInput(?array $input = null): static
    {
        $input ??= $_POST;
        unset($input['password'], $input['password_confirmation'], $input['_token']);
        $this->flashData['_old_input'] = $input;
        return $this;
    }

    /**
     * Internal: Commit flash data to the session before redirecting.
     */
    private function sendFlash(): void
    {
        foreach ($this->flashData as $key => $value) {
            Session::flash($key, $value);
        }
    }
}