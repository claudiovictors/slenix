<?php

/*
|--------------------------------------------------------------------------
| Global Helpers — Slenix Framework
|--------------------------------------------------------------------------
|
| Single entry point for all framework helpers. Grouped by category:
| views, redirect, session, URL, security, numbers, arrays, dates,
| environment, debug, and general utilities.
|
| Auto-loaded via composer.json → autoload.files.
| Luna template globals are registered at the bottom of this file.
|
*/

declare(strict_types=1);

use Slenix\Http\Request;
use Slenix\Http\Response;
use Slenix\Supports\Auth\Auth;
use Slenix\Http\Routing\Router;
use Slenix\Supports\Cache\Cache;
use Slenix\Supports\Logging\Log;
use Slenix\Supports\Template\Luna;
use Slenix\Supports\Storage\Storage;
use Slenix\Supports\Auth\AuthManager;
use Slenix\Supports\Security\Session;
use Slenix\Supports\Validation\Validator;
use Slenix\Supports\Libraries\FlashMessage;
use Slenix\Supports\Libraries\SessionManager;
use Slenix\Supports\Auth\Guards\GuardInterface;
use Slenix\Supports\Libraries\Collection;
use Slenix\Supports\Libraries\RedirectResponse;
use Slenix\Supports\Validation\ValidationException;

// ============================================================================
// CONSTANTS
// ============================================================================

defined('SLENIX_START') or define('SLENIX_START', microtime(true));
defined('ROOT_PATH')    or define('ROOT_PATH',    dirname(__DIR__, 3));
defined('APP_PATH')     or define('APP_PATH',     ROOT_PATH . '/app');
defined('PUBLIC_PATH')  or define('PUBLIC_PATH',  ROOT_PATH . '/public');
defined('SRC_PATH')     or define('SRC_PATH',     ROOT_PATH . '/src');
defined('ROUTES_PATH')  or define('ROUTES_PATH',  ROOT_PATH . '/routes');
defined('VIEWS_PATH')   or define('VIEWS_PATH',   ROOT_PATH . '/views');
defined('STORAGE_PATH') or define('STORAGE_PATH', ROOT_PATH . '/storage');
defined('CONFIG_PATH')  or define('CONFIG_PATH',  ROOT_PATH . '/src/Config');

// ============================================================================
// VIEWS
// ============================================================================

if (!function_exists('view')) {
    /**
     * Render a Luna template and send the HTML response to the client.
     *
     * @param  string               $template Template name to render.
     * @param  array<string, mixed> $data     Variables available inside the template.
     * @return void
     */
    function view(string $template, array $data = []): void
    {
        echo (new Luna($template, $data))->render();
    }
}

// ============================================================================
// REDIRECT
// ============================================================================

if (!function_exists('redirect')) {
    /**
     * Return a fluent RedirectResponse instance.
     *
     * When $url is provided the redirect fires immediately.
     * Chain methods to attach flash data before redirecting.
     *
     * Examples:
     * ```php
     * redirect('/home');
     * redirect()->back();
     * redirect()->route('login');
     * redirect('/home')->with('success', 'Saved!');
     * redirect('/home')->withErrors(['email' => 'Invalid']);
     * redirect('/home')->withInput();
     * redirect('/home')->withFlash('success', 'Done!');
     * redirect('/old')->permanent('/new');
     * ```
     *
     * @param  string|null $url    Destination URL (optional).
     * @param  int         $status HTTP redirect code (default: 302).
     * @return RedirectResponse
     */
    function redirect(?string $url = null, int $status = 302): RedirectResponse
    {
        $r = new RedirectResponse($status);
        if ($url !== null) {
            $r->to($url);
        }
        return $r;
    }
}

// ============================================================================
// FLASH
// ============================================================================

if (!function_exists('flash')) {
    /**
     * Return a FlashMessage instance for typed notifications.
     *
     * Examples:
     * ```php
     * flash()->success('Record saved.');
     * flash()->error('Something went wrong.');
     * flash()->has('success');
     * flash()->get('success');
     * flash()->typed();   // ['success' => '...', 'error' => '...']
     * ```
     *
     * @return FlashMessage
     */
    function flash(): FlashMessage
    {
        return new FlashMessage();
    }
}

// ============================================================================
// SESSION
// ============================================================================

if (!function_exists('session')) {
    /**
     * Access or manipulate session data.
     *
     * Signatures:
     * - session()                → SessionManager (fluent object)
     * - session('key')           → Session::get('key')
     * - session('key', 'value')  → Session::set('key', 'value')
     * - session(['k' => 'v'])    → bulk set, returns SessionManager
     *
     * Available methods on the returned SessionManager:
     *   ->put(key, value)        set a value
     *   ->get(key, default)      read a value
     *   ->pull(key)              read and remove
     *   ->push(key, value)       append to an array key
     *   ->forget(key|array)      remove one or many keys
     *   ->has(key)               check existence
     *   ->missing(key)           check absence
     *   ->increment(key, amount) increment a numeric value
     *   ->decrement(key, amount) decrement a numeric value
     *   ->flash(key, value)      store flash data
     *   ->flashInput(array)      store old form input
     *   ->flush()                clear all data (keeps session alive)
     *   ->regenerate()           regenerate session ID
     *   ->invalidate()           destroy session completely
     *   ->id()                   return session ID
     *   ->all()                  return all session data
     *
     * @param  string|array<string,mixed>|null $key
     * @param  mixed                           $value
     * @return SessionManager|mixed
     */
    function session(string|array|null $key = null, mixed $value = null): mixed
    {
        $manager = new SessionManager();

        if ($key === null) {
            return $manager;
        }

        if (is_array($key)) {
            foreach ($key as $k => $v) {
                Session::set((string) $k, $v);
            }
            return $manager;
        }

        if ($value !== null) {
            Session::set($key, $value);
            return $manager;
        }

        return Session::get($key);
    }
}

// ============================================================================
// OLD INPUT & ERRORS
// ============================================================================

if (!function_exists('old')) {
    /**
     * Retrieve a previous form field value after a failed validation redirect.
     *
     * Reads from '_old_input' flash and re-flashes it so it stays available
     * throughout the current template render.
     *
     * @param  string $key     Form field name.
     * @param  mixed  $default Default value when no old input exists.
     * @return mixed
     */
    function old(string $key, mixed $default = ''): mixed
    {
        $oldInput = Session::getFlash('_old_input');

        if (is_array($oldInput) && isset($oldInput[$key])) {
            Session::flash('_old_input', $oldInput);
            return $oldInput[$key];
        }

        $individual = Session::getFlash('_old_input_' . $key);
        return $individual ?? $default;
    }
}

if (!function_exists('errors')) {
    /**
     * Retrieve validation errors stored in the session.
     *
     * - errors()              → all errors from all bags as flat array
     * - errors('email')       → first error message for field
     * - errors('email', true) → all error messages for field as array
     *
     * @param  string|null $field Field name (null returns all errors).
     * @param  bool        $all   Return all messages for the field.
     * @return array|string|null
     */
    function errors(?string $field = null, bool $all = false): array|string|null
    {
        $bags = Session::getFlash('_errors') ?? [];

        if (!empty($bags)) {
            Session::flash('_errors', $bags);
        }

        if ($field === null) {
            $result = [];
            foreach ($bags as $bag) {
                foreach ((array) $bag as $f => $msg) {
                    $result[$f] = $msg;
                }
            }
            return $result;
        }

        foreach ($bags as $bag) {
            if (isset($bag[$field])) {
                return $all
                    ? (array) $bag[$field]
                    : (is_array($bag[$field]) ? $bag[$field][0] : $bag[$field]);
            }
        }

        return $all ? [] : null;
    }
}

if (!function_exists('has_error')) {
    /**
     * Check whether a validation error exists for the given field.
     *
     * @param  string $field Field name.
     * @return bool
     */
    function has_error(string $field): bool
    {
        return errors($field) !== null;
    }
}

// ============================================================================
// URL & ROUTING
// ============================================================================

if (!function_exists('url')) {
    /**
     * Generate an absolute URL from a relative path.
     *
     * Combines APP_BASE_URL with the path and appends a query string if provided.
     *
     * @param  string               $path  Relative path.
     * @param  array<string, mixed> $query Query string parameters.
     * @return string
     */
    function url(string $path = '', array $query = []): string
    {
        $base = rtrim(env('APP_BASE_URL', ''), '/');
        $url  = $base . '/' . ltrim($path, '/');

        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        return $url;
    }
}

if (!function_exists('asset')) {
    /**
     * Generate the public URL for a static asset (CSS, JS, images, etc).
     *
     * @param  string $path Asset path relative to the public root.
     * @return string
     */
    function asset(string $path): string
    {
        return rtrim(env('APP_PATH', ''), '/') . '/'. $path;
    }
}

if (!function_exists('route')) {
    /**
     * Generate the URL for a named route registered in the Router.
     *
     * @param  string               $name   Route name.
     * @param  array<string, mixed> $params Route parameters.
     * @return string|null          URL or null if the route does not exist.
     */
    function route(string $name, array $params = []): ?string
    {
        return Router::route($name, $params);
    }
}

if (!function_exists('current_url')) {
    /**
     * Return the full URL of the current request (scheme + host + URI).
     *
     * @return string
     */
    function current_url(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST']    ?? 'localhost';
        $uri    = $_SERVER['REQUEST_URI']  ?? '/';
        return "{$scheme}://{$host}{$uri}";
    }
}

if (!function_exists('request_path')) {
    /**
     * Return only the path of the current request URL, without query string.
     *
     * @return string e.g. '/dashboard/users'
     */
    function request_path(): string
    {
        return parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
    }
}

if (!function_exists('is_active')) {
    /**
     * Return a CSS class when the current path matches a pattern.
     *
     * Supports exact match and wildcard (*) at the end of the pattern.
     *
     * Examples:
     * ```php
     * is_active('/home')         // exact match
     * is_active('/blog/*')       // prefix match
     * is_active('/admin/*', 'bg-blue-500', 'bg-gray-100')
     * ```
     *
     * @param  string $pattern  Route pattern (supports trailing '*').
     * @param  string $active   CSS class returned on match (default: 'active').
     * @param  string $inactive CSS class returned when not matched (default: '').
     * @return string
     */
    function is_active(string $pattern, string $active = 'active', string $inactive = ''): string
    {
        $path  = request_path();
        $match = str_ends_with($pattern, '*')
            ? str_starts_with($path, rtrim($pattern, '*'))
            : ($path === $pattern);

        return $match ? $active : $inactive;
    }
}

if (!function_exists('query_string')) {
    /**
     * Build a query string by merging current params with overrides.
     *
     * @param  array<string, mixed> $merge  Parameters to add or override.
     * @param  string[]             $remove Keys to remove from the result.
     * @return string               Query string without leading '?'.
     */
    function query_string(array $merge = [], array $remove = []): string
    {
        $params = array_merge($_GET, $merge);
        foreach ($remove as $key) {
            unset($params[$key]);
        }
        return http_build_query($params);
    }
}

// ============================================================================
// HTTP / ABORT
// ============================================================================

if (!function_exists('abort')) {
    /**
     * Halt execution and emit an HTTP error response.
     *
     * Renders `src/Core/Exceptions/errors/{code}.php` when it exists.
     * Returns JSON for requests that accept application/json.
     *
     * @param  int    $code    HTTP status code (default: 500).
     * @param  string $message Custom error message (optional).
     * @return never
     */
    function abort(int $code = 500, string $message = ''): never
    {
        $texts = [
            400 => 'Bad Request',        401 => 'Unauthorized',
            403 => 'Forbidden',          404 => 'Not Found',
            405 => 'Method Not Allowed', 408 => 'Request Timeout',
            409 => 'Conflict',           422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',  500 => 'Internal Server Error',
            502 => 'Bad Gateway',        503 => 'Service Unavailable',
        ];

        $msg = $message ?: ($texts[$code] ?? 'Error');
        http_response_code($code);

        $wantsJson = isset($_SERVER['HTTP_ACCEPT'])
            && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json');

        if ($wantsJson) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['error' => true, 'message' => $msg, 'status' => $code]);
            exit;
        }

        $errFile = SRC_PATH . "/Core/Exceptions/errors/{$code}.php";
        if (file_exists($errFile)) {
            extract(['code' => $code, 'message' => $msg]);
            include $errFile;
        } else {
            echo "<h1>{$code} — {$msg}</h1>";
        }

        exit;
    }
}

if (!function_exists('abort_if')) {
    /**
     * Abort with an HTTP error if the condition is true.
     *
     * @param  bool   $condition
     * @param  int    $code
     * @param  string $message
     * @return void
     */
    function abort_if(bool $condition, int $code = 500, string $message = ''): void
    {
        if ($condition) abort($code, $message);
    }
}

if (!function_exists('abort_unless')) {
    /**
     * Abort with an HTTP error if the condition is false.
     *
     * @param  bool   $condition
     * @param  int    $code
     * @param  string $message
     * @return void
     */
    function abort_unless(bool $condition, int $code = 500, string $message = ''): void
    {
        if (!$condition) abort($code, $message);
    }
}

// ============================================================================
// RESPONSE / REQUEST
// ============================================================================

if (!function_exists('response')) {
    /**
     * Create and return an HTTP Response instance.
     *
     * @param  mixed $content Response content (optional).
     * @param  int   $status  HTTP status code (default: 200).
     * @return Response
     */
    function response(mixed $content = null, int $status = 200): Response
    {
        $r = new Response();
        $r->status($status);
        if ($content !== null) {
            $r->setContent($content);
        }
        return $r;
    }
}

if (!function_exists('request')) {
    /**
     * Create and return an HTTP Request instance.
     *
     * @param  array $params
     * @param  array $server
     * @param  array $query
     * @param  array $cookies
     * @param  array $files
     * @return Request
     */
    function request(
        array $params  = [],
        array $server  = [],
        array $query   = [],
        array $cookies = [],
        array $files   = []
    ): Request {
        return new Request($params, $server, $query, $cookies, $files);
    }
}

// ============================================================================
// JSON
// ============================================================================

if (!function_exists('to_json')) {
    /**
     * Encode a value to a JSON string.
     *
     * @param  mixed $data   Value to serialize.
     * @param  bool  $pretty Pretty-print with indentation (default: false).
     * @return string
     */
    function to_json(mixed $data, bool $pretty = false): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if ($pretty) $flags |= JSON_PRETTY_PRINT;
        return json_encode($data, $flags);
    }
}

if (!function_exists('from_json')) {
    /**
     * Decode a JSON string to an array or object.
     *
     * @param  string $json  JSON string to decode.
     * @param  bool   $assoc Return associative array (default: true).
     * @return mixed
     */
    function from_json(string $json, bool $assoc = true): mixed
    {
        return json_decode($json, $assoc);
    }
}

if (!function_exists('is_json')) {
    /**
     * Check whether a string is valid JSON.
     *
     * @param  string $string
     * @return bool
     */
    function is_json(string $string): bool
    {
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }
}

// ============================================================================
// NUMBERS
// ============================================================================

if (!function_exists('currency')) {
    /**
     * Format a number as a monetary value with symbol and separators.
     *
     * Example: currency(1234.5, 'AOA') → 'AOA 1.234,50'
     *
     * @param  float  $value     Numeric value.
     * @param  string $symbol    Currency symbol (default: '$').
     * @param  int    $decimals  Decimal places (default: 2).
     * @param  string $thousands Thousands separator (default: '.').
     * @param  string $decimal   Decimal separator (default: ',').
     * @return string
     */
    function currency(
        float  $value,
        string $symbol    = '$',
        int    $decimals  = 2,
        string $thousands = '.',
        string $decimal   = ','
    ): string {
        return $symbol . ' ' . number_format($value, $decimals, $decimal, $thousands);
    }
}

if (!function_exists('percent')) {
    /**
     * Format a number as a percentage string.
     *
     * Example: percent(98.5) → '98,5%'
     *
     * @param  float $value    Value to format.
     * @param  int   $decimals Decimal places (default: 1).
     * @return string
     */
    function percent(float $value, int $decimals = 1): string
    {
        return number_format($value, $decimals, ',', '.') . '%';
    }
}

if (!function_exists('percentage_of')) {
    /**
     * Calculate the percentage of a value relative to a total.
     *
     * Returns 0.0 when total is zero to avoid division by zero.
     *
     * @param  float|int $value
     * @param  float|int $total
     * @param  int       $decimals
     * @return float
     */
    function percentage_of(float|int $value, float|int $total, int $decimals = 2): float
    {
        if ($total == 0) return 0.0;
        return round(($value / $total) * 100, $decimals);
    }
}

if (!function_exists('clamp')) {
    /**
     * Restrict a numeric value to the range [min, max].
     *
     * @param  float|int $value
     * @param  float|int $min
     * @param  float|int $max
     * @return float|int
     */
    function clamp(float|int $value, float|int $min, float|int $max): float|int
    {
        return max($min, min($max, $value));
    }
}

if (!function_exists('ordinal')) {
    /**
     * Return a number with its English ordinal suffix.
     *
     * Examples: ordinal(1) → '1st', ordinal(12) → '12th'
     *
     * @param  int $number
     * @return string
     */
    function ordinal(int $number): string
    {
        $abs    = abs($number);
        $mod100 = $abs % 100;
        $mod10  = $abs % 10;

        if ($mod100 >= 11 && $mod100 <= 13) return $number . 'th';

        return match ($mod10) {
            1       => $number . 'st',
            2       => $number . 'nd',
            3       => $number . 'rd',
            default => $number . 'th',
        };
    }
}

if (!function_exists('roman')) {
    /**
     * Convert an integer to a Roman numeral.
     *
     * Example: roman(2024) → 'MMXXIV'
     *
     * @param  int $number Positive integer.
     * @return string
     */
    function roman(int $number): string
    {
        $map    = [1000=>'M',900=>'CM',500=>'D',400=>'CD',100=>'C',90=>'XC',
                   50=>'L',40=>'XL',10=>'X',9=>'IX',5=>'V',4=>'IV',1=>'I'];
        $result = '';
        foreach ($map as $value => $numeral) {
            while ($number >= $value) { $result .= $numeral; $number -= $value; }
        }
        return $result;
    }
}

if (!function_exists('format_bytes')) {
    /**
     * Format a byte count as a human-readable string.
     *
     * Example: format_bytes(1536) → '1.50 KB'
     *
     * @param  int $bytes
     * @param  int $precision
     * @return string
     */
    function format_bytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $bytes = max(0, $bytes);
        $pow   = $bytes > 0 ? (int) floor(log($bytes) / log(1024)) : 0;
        $pow   = min($pow, count($units) - 1);
        return round($bytes / (1024 ** $pow), $precision) . ' ' . $units[$pow];
    }
}

if (!function_exists('number_short')) {
    /**
     * Shorten a large number using K, M, B suffixes.
     *
     * Examples:
     *   number_short(1500)        → '1.5K'
     *   number_short(2500000)     → '2.5M'
     *   number_short(1000000000)  → '1B'
     *
     * @param  float|int $number
     * @param  int       $precision Decimal places (default: 1).
     * @return string
     */
    function number_short(float|int $number, int $precision = 1): string
    {
        $abs = abs($number);
        $sign = $number < 0 ? '-' : '';

        return match (true) {
            $abs >= 1_000_000_000 => $sign . round($abs / 1_000_000_000, $precision) . 'B',
            $abs >= 1_000_000     => $sign . round($abs / 1_000_000, $precision) . 'M',
            $abs >= 1_000         => $sign . round($abs / 1_000, $precision) . 'K',
            default               => (string) $number,
        };
    }
}

if (!function_exists('number_pad')) {
    /**
     * Pad a number with leading zeros to a fixed width.
     *
     * Example: number_pad(7, 3) → '007'
     *
     * @param  int $number
     * @param  int $width  Total character width.
     * @return string
     */
    function number_pad(int $number, int $width = 2): string
    {
        return str_pad((string) $number, $width, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('is_even')) {
    /**
     * Check whether a number is even.
     *
     * @param  int $number
     * @return bool
     */
    function is_even(int $number): bool
    {
        return $number % 2 === 0;
    }
}

if (!function_exists('is_odd')) {
    /**
     * Check whether a number is odd.
     *
     * @param  int $number
     * @return bool
     */
    function is_odd(int $number): bool
    {
        return $number % 2 !== 0;
    }
}

if (!function_exists('is_between')) {
    /**
     * Check whether a value is within a range (inclusive).
     *
     * @param  float|int $value
     * @param  float|int $min
     * @param  float|int $max
     * @return bool
     */
    function is_between(float|int $value, float|int $min, float|int $max): bool
    {
        return $value >= $min && $value <= $max;
    }
}

// ============================================================================
// FUNCTIONAL UTILITIES
// ============================================================================

if (!function_exists('value')) {
    /**
     * Return the value. If callable, execute it and return the result.
     *
     * @param  mixed $value
     * @param  mixed ...$args Arguments forwarded to the callable.
     * @return mixed
     */
    function value(mixed $value, mixed ...$args): mixed
    {
        return is_callable($value) ? $value(...$args) : $value;
    }
}

if (!function_exists('tap')) {
    /**
     * Pass a value to a callback and return the original value (side-effect safe).
     *
     * @param  mixed    $value
     * @param  callable $callback
     * @return mixed The original $value unchanged.
     */
    function tap(mixed $value, callable $callback): mixed
    {
        $callback($value);
        return $value;
    }
}

if (!function_exists('with')) {
    /**
     * Pass a value to a callback and return the callback's result.
     *
     * @param  mixed    $value
     * @param  callable $callback
     * @return mixed
     */
    function with(mixed $value, callable $callback): mixed
    {
        return $callback($value);
    }
}

if (!function_exists('when')) {
    /**
     * Execute a callback only when the condition is true.
     *
     * Optionally executes a fallback when the condition is false.
     *
     * @param  bool          $condition
     * @param  callable      $callback
     * @param  callable|null $default
     * @return mixed
     */
    function when(bool $condition, callable $callback, ?callable $default = null): mixed
    {
        if ($condition) return $callback();
        return $default ? $default() : null;
    }
}

if (!function_exists('optional')) {
    /**
     * Return the value or a null-safe object that silences property/method access.
     *
     * @param  mixed $value
     * @return mixed The original value or a null object.
     */
    function optional(mixed $value): mixed
    {
        return $value ?? new class {
            public function __get(string $name): null  { return null; }
            public function __call(string $n, array $a): null { return null; }
        };
    }
}

if (!function_exists('retry')) {
    /**
     * Execute a callable multiple times before throwing on final failure.
     *
     * @param  int      $times    Maximum attempts.
     * @param  callable $callback Receives the attempt number (0-based).
     * @param  int      $sleepMs  Milliseconds to wait between attempts.
     * @return mixed
     * @throws \Throwable The last exception after all attempts are exhausted.
     */
    function retry(int $times, callable $callback, int $sleepMs = 0): mixed
    {
        $attempt = 0;
        while (true) {
            try {
                return $callback($attempt);
            } catch (\Throwable $e) {
                if (++$attempt >= $times) throw $e;
                if ($sleepMs > 0) usleep($sleepMs * 1000);
            }
        }
    }
}

if (!function_exists('memoize')) {
    /**
     * Cache and reuse the result of a callable by a static key.
     *
     * The result is stored in memory for the current process lifetime.
     *
     * @param  string   $key      Cache key.
     * @param  callable $callback Function whose result will be memoized.
     * @return mixed
     */
    function memoize(string $key, callable $callback): mixed
    {
        static $cache = [];
        if (!array_key_exists($key, $cache)) {
            $cache[$key] = $callback();
        }
        return $cache[$key];
    }
}

if (!function_exists('pipe')) {
    /**
     * Pass a value through a chain of callables sequentially.
     *
     * Each callable receives the result of the previous one.
     *
     * Example: pipe('hello', 'strtoupper', 'trim') → 'HELLO'
     *
     * @param  mixed    $value  Starting value.
     * @param  callable ...$fns Transformations to apply in order.
     * @return mixed
     */
    function pipe(mixed $value, callable ...$fns): mixed
    {
        return array_reduce($fns, fn($carry, $fn) => $fn($carry), $value);
    }
}

if (!function_exists('class_basename')) {
    /**
     * Return the short class name without the namespace.
     *
     * Example: class_basename('App\Models\User') → 'User'
     *
     * @param  string|object $class
     * @return string
     */
    function class_basename(string|object $class): string
    {
        $class = is_object($class) ? get_class($class) : $class;
        return basename(str_replace('\\', '/', $class));
    }
}

if (!function_exists('data_get')) {
    /**
     * Retrieve a value from nested arrays/objects using dot-notation.
     *
     * Supports '*' wildcard to extract a column from each child.
     *
     * Example: data_get($users, '*.name') → ['Alice', 'Bob']
     *
     * @param  mixed            $target
     * @param  string|int|null  $key     Dot-notation key (null returns target).
     * @param  mixed            $default
     * @return mixed
     */
    function data_get(mixed $target, string|int|null $key, mixed $default = null): mixed
    {
        if ($key === null) return $target;

        $keys = is_string($key) ? explode('.', $key) : [$key];

        foreach ($keys as $segment) {
            if ($segment === '*') {
                if (!is_array($target)) return $default;
                $result = [];
                foreach ($target as $item) {
                    $result[] = is_array($item)
                        ? ($item[implode('.', array_splice($keys, 1))] ?? $default)
                        : $default;
                }
                return $result;
            }

            if (is_array($target) && array_key_exists($segment, $target)) {
                $target = $target[$segment];
            } elseif (is_object($target) && property_exists($target, $segment)) {
                $target = $target->$segment;
            } else {
                return $default;
            }
        }

        return $target;
    }
}

// ============================================================================
// PASSWORDS
// ============================================================================

if (!function_exists('hash_make')) {
    /**
     * Create a secure bcrypt hash of a password.
     *
     * @param  string $password
     * @param  int    $cost bcrypt cost factor (default: 12).
     * @return string
     */
    function hash_make(string $password, int $cost = 12): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => $cost]);
    }
}

if (!function_exists('hash_check')) {
    /**
     * Verify a plain password against a hash.
     *
     * @param  string $password
     * @param  string $hash
     * @return bool
     */
    function hash_check(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }
}

if (!function_exists('hash_needs_rehash')) {
    /**
     * Check whether a bcrypt hash needs to be rehashed (cost or algorithm change).
     *
     * @param  string $hash
     * @param  int    $cost Desired cost (default: 12).
     * @return bool
     */
    function hash_needs_rehash(string $hash, int $cost = 12): bool
    {
        return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => $cost]);
    }
}

// ============================================================================
// AVATAR
// ============================================================================

if (!function_exists('avatar')) {
    /**
     * Generate an inline SVG avatar using the initials of a name.
     *
     * @param  string $name  Full name to extract initials from.
     * @param  int    $size  Pixel size of the avatar (default: 40).
     * @param  string $bg    Background color (default: '#4f46e5').
     * @param  string $color Text color (default: '#ffffff').
     * @return string        SVG markup.
     */
    function avatar(
        string $name,
        int    $size  = 40,
        string $bg    = '#0f0f0f',
        string $color = '#ffffff'
    ): string {
        $words    = preg_split('/\s+/', trim($name));
        $initials = strtoupper(substr($words[0], 0, 1));
        if (count($words) > 1) {
            $initials .= strtoupper(substr(end($words), 0, 1));
        }

        $font = (int) round($size * 0.38);

        return <<<SVG
        <svg xmlns="http://www.w3.org/2000/svg" width="{$size}" height="{$size}" viewBox="0 0 {$size} {$size}">
          <rect width="{$size}" height="{$size}" rx="{$size}" fill="{$bg}"/>
          <text x="50%" y="50%" dominant-baseline="central" text-anchor="middle"
                font-family="system-ui,sans-serif" font-size="{$font}" font-weight="600" fill="{$color}">{$initials}</text>
        </svg>
        SVG;
    }
}

// ============================================================================
// SECURITY
// ============================================================================

if (!function_exists('csrf_token')) {
    /**
     * Return the current CSRF token, generating one if it does not exist.
     *
     * @return string 64-character hex token.
     */
    function csrf_token(): string
    {
        Session::start();
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf_token'];
    }
}

if (!function_exists('csrf_field')) {
    /**
     * Return a hidden HTML input with the CSRF token for use in forms.
     *
     * @return string
     */
    function csrf_field(): string
    {
        return '<input type="hidden" name="_csrf_token" value="' . csrf_token() . '"/>';
    }
}

if (!function_exists('csrf_meta')) {
    /**
     * Return a meta tag with the CSRF token for use in Ajax requests.
     *
     * Place in <head>: <meta name="csrf-token" content="...">
     *
     * @return string
     */
    function csrf_meta(): string
    {
        return '<meta name="csrf-token" content="' . csrf_token() . '"/>';
    }
}

if (!function_exists('csrf_verify')) {
    /**
     * Verify the CSRF token from the current request.
     *
     * Accepts the token via POST (_csrf_token) or X-CSRF-Token header.
     * Uses hash_equals() for timing-attack resistance.
     *
     * @param  string|null $token Token to verify (null reads from request).
     * @return bool
     */
    function csrf_verify(?string $token = null): bool
    {
        $token ??= $_POST['_csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if (!$token) return false;
        Session::start();
        return hash_equals($_SESSION['_csrf_token'] ?? '', $token);
    }
}

if (!function_exists('method_field')) {
    /**
     * Return a hidden input to spoof HTTP methods in HTML forms.
     *
     * Required for PUT, PATCH, and DELETE in standard HTML forms.
     *
     * @param  string $method HTTP method to spoof.
     * @return string
     */
    function method_field(string $method): string
    {
        return '<input type="hidden" name="_method" value="' . strtoupper($method) . '">';
    }
}

if (!function_exists('encrypt')) {
    /**
     * Encrypt a string using AES-256-GCM with the application APP_KEY.
     *
     * The result includes IV and auth tag concatenated in base64.
     *
     * @param  string $value String to encrypt.
     * @return string        Base64-encoded ciphertext.
     * @throws \RuntimeException If APP_KEY is invalid or too short.
     */
    function encrypt(string $value): string
    {
        $key = (string) env('APP_KEY', '');
        $key = base64_decode(str_replace('base64:', '', $key)) ?: $key;

        if (strlen($key) < 16) {
            throw new \RuntimeException('APP_KEY is invalid or too short for encryption.');
        }

        $iv         = random_bytes(12);
        $tag        = '';
        $ciphertext = openssl_encrypt($value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

        return base64_encode($iv . '::' . $tag . '::' . $ciphertext);
    }
}

if (!function_exists('decrypt')) {
    /**
     * Decrypt a string previously encrypted with encrypt().
     *
     * Returns false if the payload is invalid or corrupted.
     *
     * @param  string $encrypted Base64-encoded ciphertext.
     * @return string|false
     */
    function decrypt(string $encrypted): string|false
    {
        $key = (string) env('APP_KEY', '');
        $key = base64_decode(str_replace('base64:', '', $key)) ?: $key;

        $data  = base64_decode($encrypted);
        if (!$data) return false;

        $parts = explode('::', $data, 3);
        if (count($parts) !== 3) return false;

        [$iv, $tag, $ciphertext] = $parts;

        return openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    }
}

if (!function_exists('secure_compare')) {
    /**
     * Compare two strings in constant time (timing-attack resistant).
     *
     * @param  string $a
     * @param  string $b
     * @return bool
     */
    function secure_compare(string $a, string $b): bool
    {
        return hash_equals($a, $b);
    }
}

if (!function_exists('generate_token')) {
    /**
     * Generate a cryptographically secure hexadecimal token.
     *
     * @param  int $bytes Number of random bytes (default: 32 → 64 hex chars).
     * @return string
     */
    function generate_token(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }
}

if (!function_exists('mask_email')) {
    /**
     * Partially mask an email address for safe display.
     *
     * Example: mask_email('claudio@slenix.com') → 'cl****@slenix.com'
     *
     * @param  string $email
     * @return string
     */
    function mask_email(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2);
        $visible = max(2, (int) floor(strlen($local) / 3));
        $masked  = substr($local, 0, $visible) . str_repeat('*', strlen($local) - $visible);
        return "{$masked}@{$domain}";
    }
}

if (!function_exists('mask_phone')) {
    /**
     * Partially mask a phone number for safe display.
     *
     * Example: mask_phone('244912345678') → '244*****5678'
     *
     * @param  string $phone
     * @return string
     */
    function mask_phone(string $phone): string
    {
        $clean  = preg_replace('/\D/', '', $phone);
        $len    = strlen($clean);
        $start  = max(3, (int) floor($len / 4));
        $end    = 4;
        return substr($clean, 0, $start)
            . str_repeat('*', $len - $start - $end)
            . substr($clean, -$end);
    }
}

if (!function_exists('is_safe_url')) {
    /**
     * Check whether a URL is safe for redirection (prevents open redirect).
     *
     * Relative URLs are always considered safe.
     * Absolute URLs are checked against the allowed host.
     *
     * @param  string      $url
     * @param  string|null $allowedHost Defaults to current HTTP_HOST.
     * @return bool
     */
    function is_safe_url(string $url, ?string $allowedHost = null): bool
    {
        $parsed = parse_url($url);
        if (!isset($parsed['host'])) return true;
        $allowed = $allowedHost ?? ($_SERVER['HTTP_HOST'] ?? '');
        return $parsed['host'] === $allowed;
    }
}

if (!function_exists('purify_html')) {
    /**
     * Strip potentially dangerous HTML tags to mitigate XSS.
     *
     * For advanced sanitization consider a library like HTMLPurifier.
     *
     * @param  string   $html
     * @param  string[] $allowedTags Tags to keep.
     * @return string
     */
    function purify_html(
        string $html,
        array  $allowedTags = ['p','b','i','u','strong','em','br','ul','ol','li','a']
    ): string {
        return strip_tags($html, $allowedTags);
    }
}

// ============================================================================
// ENVIRONMENT
// ============================================================================

if (!function_exists('env')) {
    /**
     * Get an environment variable with an optional default.
     *
     * Auto-casts "true"/"false"/"null"/"empty" to native PHP types.
     *
     * @param  string $key
     * @param  mixed  $default
     * @return mixed
     */
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === null) return $default;

        return match (strtolower((string) $value)) {
            'true',  '(true)'  => true,
            'false', '(false)' => false,
            'null',  '(null)'  => null,
            'empty', '(empty)' => '',
            default            => $value,
        };
    }
}

if (!function_exists('config')) {
    /**
     * Read a config value using dot-notation mapped to environment variables.
     *
     * Example: config('app.debug') → env('APP_DEBUG')
     *
     * @param  string $key     Dot-notation key.
     * @param  mixed  $default
     * @return mixed
     */
    function config(string $key, mixed $default = null): mixed
    {
        return env(strtoupper(str_replace('.', '_', $key)), $default);
    }
}

if (!function_exists('app_env')) {
    /**
     * Check whether the current APP_ENV matches any of the given values.
     *
     * @param  string ...$environments e.g. 'production', 'local'
     * @return bool
     */
    function app_env(string ...$environments): bool
    {
        return in_array(env('APP_ENV', 'local'), $environments, true);
    }
}

if (!function_exists('is_debug')) {
    /**
     * Check whether debug mode is active (APP_DEBUG=true).
     *
     * @return bool
     */
    function is_debug(): bool
    {
        return (bool) env('APP_DEBUG', false);
    }
}

if (!function_exists('is_production')) {
    /**
     * Check whether the current environment is production.
     *
     * @return bool
     */
    function is_production(): bool
    {
        return app_env('production', 'prod');
    }
}

if (!function_exists('is_local')) {
    /**
     * Check whether the current environment is local/development.
     *
     * @return bool
     */
    function is_local(): bool
    {
        return app_env('local', 'development', 'dev');
    }
}

// ============================================================================
// PATHS
// ============================================================================

if (!function_exists('base_path')) {
    /** Absolute path to the project root. */
    function base_path(string $path = ''): string
    {
        return ROOT_PATH . ($path ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : '');
    }
}

if (!function_exists('app_path')) {
    /** Absolute path to the app/ directory. */
    function app_path(string $path = ''): string
    {
        return APP_PATH . ($path ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : '');
    }
}

if (!function_exists('public_path')) {
    /** Absolute path to the public/ directory. */
    function public_path(string $path = ''): string
    {
        return PUBLIC_PATH . ($path ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : '');
    }
}

if (!function_exists('storage_path')) {
    /** Absolute path to the storage/ directory. */
    function storage_path(string $path = ''): string
    {
        return STORAGE_PATH . ($path ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : '');
    }
}

if (!function_exists('views_path')) {
    /** Absolute path to the views/ directory. */
    function views_path(string $path = ''): string
    {
        return VIEWS_PATH . ($path ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : '');
    }
}

if (!function_exists('src_path')) {
    /** Absolute path to the src/ directory. */
    function src_path(string $path = ''): string
    {
        return SRC_PATH . ($path ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : '');
    }
}

// ============================================================================
// DEBUG
// ============================================================================

if (!function_exists('dd')) {
    /**
     * Dump values with syntax highlighting and halt execution.
     *
     * @param  mixed ...$vars
     * @return never
     */
    function dd(mixed ...$vars): never
    {
        foreach ($vars as $i => $var) {
            echo _slenix_dump_render($var, $i, count($vars));
        }
        exit;
    }
}

if (!function_exists('dump')) {
    /**
     * Dump values with syntax highlighting without halting execution.
     *
     * @param  mixed ...$vars
     * @return void
     */
    function dump(mixed ...$vars): void
    {
        foreach ($vars as $i => $var) {
            echo _slenix_dump_render($var, $i, count($vars));
        }
    }
}

if (!function_exists('_slenix_dump_render')) {
    /** @internal */
    function _slenix_dump_render(mixed $var, int $index, int $total): string
    {
        $type      = gettype($var);
        $isLast    = $index === $total - 1;
        $typeColor = match ($type) {
            'string'           => '#a78bfa',
            'integer','double' => '#34d399',
            'boolean'          => '#fbbf24',
            'NULL'             => '#6b7280',
            'array'            => '#38bdf8',
            'object'           => '#f472b6',
            default            => '#cdd6f4',
        };

        ob_start(); var_export($var); $raw = ob_get_clean();

        $raw = preg_replace("/\b(true|false|null|NULL)\b/", '§BOOL§$1§/BOOL§', $raw);
        $raw = preg_replace("/'((?:[^'\\\\]|\\\\.)*)'/", "§STR§'$1'§/STR§", $raw);
        $raw = preg_replace('/(?<![\'a-zA-Z_])\b(\d+\.?\d*)\b(?![\'a-zA-Z_])/', '§NUM§$1§/NUM§', $raw);
        $raw = preg_replace('/\b(array)\s*\(/i', '§ARR§array§/ARR§(', $raw);
        $raw = htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $raw = preg_replace('/§BOOL§(.*?)§\/BOOL§/', '<span style="color:#fbbf24;font-weight:600">$1</span>', $raw);
        $raw = preg_replace('/§STR§(.*?)§\/STR§/',   '<span style="color:#a78bfa">$1</span>', $raw);
        $raw = preg_replace('/§NUM§(.*?)§\/NUM§/',   '<span style="color:#34d399">$1</span>', $raw);
        $raw = preg_replace('/§ARR§(.*?)§\/ARR§/',   '<span style="color:#38bdf8;font-weight:600">$1</span>', $raw);

        $mb     = $isLast ? '1rem' : '0.5rem';
        $border = '1px solid rgba(255,255,255,0.08)';

        return <<<HTML
<div style="background:#0a0a0a;border:{$border};border-radius:8px;font-family:'JetBrains Mono','Fira Code',monospace;font-size:13px;overflow:auto;margin:0.4rem 1rem {$mb};box-shadow:0 4px 24px rgba(0,0,0,0.5);">
  <div style="display:flex;align-items:center;gap:0.5rem;padding:0.45rem 0.85rem;background:rgba(255,255,255,0.03);border-bottom:{$border};">
    <span style="background:{$typeColor};color:#000;font-size:10px;font-weight:700;padding:1px 8px;border-radius:99px;letter-spacing:0.5px;text-transform:uppercase;">{$type}</span>
    <span style="color:#4b5563;font-size:11px;margin-left:auto;">Slenix</span>
  </div>
  <pre style="margin:0;padding:0.85rem;color:#e2e8f0;line-height:1.7;">{$raw}</pre>
</div>
HTML;
    }
}

if (!function_exists('dj')) {
    /**
     * Dump values as formatted JSON and halt execution.
     *
     * @param  mixed ...$vars
     * @return never
     */
    function dj(mixed ...$vars): never
    {
        header('Content-Type: application/json; charset=UTF-8');
        $out = count($vars) === 1 ? $vars[0] : $vars;
        echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('benchmark')) {
    /**
     * Return elapsed milliseconds since application boot.
     *
     * @return float
     */
    function benchmark(): float
    {
        return round((microtime(true) - SLENIX_START) * 1000, 2);
    }
}

if (!function_exists('memory_usage')) {
    /**
     * Return current memory usage as a human-readable string.
     *
     * @param  bool $peak Return peak usage instead of current (default: false).
     * @return string e.g. '4.50 MB'
     */
    function memory_usage(bool $peak = false): string
    {
        $bytes = $peak ? memory_get_peak_usage(true) : memory_get_usage(true);
        if ($bytes < 1024)    return $bytes . ' B';
        if ($bytes < 1048576) return round($bytes / 1024, 2) . ' KB';
        return round($bytes / 1048576, 2) . ' MB';
    }
}

if (!function_exists('log_debug')) {
    /**
     * Write a debug message to a log file.
     *
     * Creates the log directory automatically. Uses LOCK_EX to prevent race conditions.
     *
     * @param  mixed  $message String or value to log (arrays serialized as JSON).
     * @param  string $channel Log file name without extension (default: 'debug').
     * @return void
     */
    function log_debug(mixed $message, string $channel = 'debug'): void
    {
        if (!defined('STORAGE_PATH')) return;

        $dir  = STORAGE_PATH . '/logs';
        $file = "{$dir}/{$channel}.log";

        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $content = is_string($message) ? $message : json_encode($message, JSON_UNESCAPED_UNICODE);
        file_put_contents($file, '[' . date('Y-m-d H:i:s') . '] ' . $content . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

if (!function_exists('log_error')) {
    /**
     * Write an error or exception to a log file.
     *
     * @param  string|\Throwable $error
     * @param  string            $channel Log channel (default: 'error').
     * @return void
     */
    function log_error(string|\Throwable $error, string $channel = 'error'): void
    {
        $message = $error instanceof \Throwable
            ? "[{$error->getCode()}] {$error->getMessage()} in {$error->getFile()}:{$error->getLine()}"
            : $error;

        log_debug($message, $channel);
    }
}

if (!function_exists('trace')) {
    /**
     * Return a formatted debug backtrace as a string.
     *
     * @param  int $limit Max frames to include (default: 5).
     * @return string
     */
    function trace(int $limit = 5): string
    {
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $limit + 1);
        array_shift($frames);
        $output = '';
        foreach ($frames as $i => $f) {
            $class = isset($f['class']) ? $f['class'] . '::' : '';
            $output .= "#{$i} {$class}" . ($f['function'] ?? '') . " [{$f['file']}:{$f['line']}]\n";
        }
        return $output;
    }
}

// ============================================================================
// DATES
// ============================================================================

if (!function_exists('now')) {
    /**
     * Return the current date and time as a DateTimeImmutable.
     *
     * @param  \DateTimeZone|null $timezone
     * @return \DateTimeImmutable
     */
    function now(?\DateTimeZone $timezone = null): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', $timezone);
    }
}

if (!function_exists('today')) {
    /**
     * Return today's date at midnight as a DateTimeImmutable.
     *
     * @param  \DateTimeZone|null $timezone
     * @return \DateTimeImmutable
     */
    function today(?\DateTimeZone $timezone = null): \DateTimeImmutable
    {
        return new \DateTimeImmutable('today', $timezone);
    }
}

if (!function_exists('yesterday')) {
    /**
     * Return yesterday's date at midnight as a DateTimeImmutable.
     *
     * @param  \DateTimeZone|null $timezone
     * @return \DateTimeImmutable
     */
    function yesterday(?\DateTimeZone $timezone = null): \DateTimeImmutable
    {
        return new \DateTimeImmutable('yesterday', $timezone);
    }
}

if (!function_exists('tomorrow')) {
    /**
     * Return tomorrow's date at midnight as a DateTimeImmutable.
     *
     * @param  \DateTimeZone|null $timezone
     * @return \DateTimeImmutable
     */
    function tomorrow(?\DateTimeZone $timezone = null): \DateTimeImmutable
    {
        return new \DateTimeImmutable('tomorrow', $timezone);
    }
}

if (!function_exists('format_date')) {
    /**
     * Format a date string using the given format.
     *
     * @param  string $dateString
     * @param  string $format     Output format (default: 'd/m/Y H:i:s').
     * @return string|null        Formatted date or null on invalid input.
     */
    function format_date(string $dateString, string $format = 'd/m/Y H:i:s'): ?string
    {
        try {
            return (new \DateTimeImmutable($dateString))->format($format);
        } catch (\Exception) {
            return null;
        }
    }
}

if (!function_exists('human_date')) {
    /**
     * Return a relative human-readable date string in Portuguese.
     *
     * Examples:
     *   human_date('2024-01-01') → 'há 3 meses'
     *   human_date('+2 days')    → 'em 2 dias'
     *
     * @param  string|\DateTimeInterface $date
     * @return string
     */
    function human_date(string|\DateTimeInterface $date): string
    {
        $dt   = is_string($date) ? new \DateTimeImmutable($date) : $date;
        $diff = (new \DateTimeImmutable())->diff($dt);
        $past = $diff->invert === 1;

        $str = match (true) {
            $diff->y > 0 => "{$diff->y} " . ($diff->y === 1 ? 'ano'    : 'anos'),
            $diff->m > 0 => "{$diff->m} " . ($diff->m === 1 ? 'mês'    : 'meses'),
            $diff->d > 0 => "{$diff->d} " . ($diff->d === 1 ? 'dia'    : 'dias'),
            $diff->h > 0 => "{$diff->h} " . ($diff->h === 1 ? 'hora'   : 'horas'),
            $diff->i > 0 => "{$diff->i} " . ($diff->i === 1 ? 'minuto' : 'minutos'),
            default      => null,
        };

        if ($str === null) return 'agora mesmo';
        return $past ? "há {$str}" : "em {$str}";
    }
}

if (!function_exists('diff_in_days')) {
    /**
     * Calculate the number of days between two dates.
     *
     * @param  string|\DateTimeInterface $from
     * @param  string|\DateTimeInterface $to
     * @return int
     */
    function diff_in_days(string|\DateTimeInterface $from, string|\DateTimeInterface $to): int
    {
        $from = is_string($from) ? new \DateTimeImmutable($from) : $from;
        $to   = is_string($to)   ? new \DateTimeImmutable($to)   : $to;
        return (int) $from->diff($to)->days;
    }
}

if (!function_exists('diff_in_hours')) {
    /**
     * Calculate the number of hours between two dates.
     *
     * @param  string|\DateTimeInterface $from
     * @param  string|\DateTimeInterface $to
     * @return int
     */
    function diff_in_hours(string|\DateTimeInterface $from, string|\DateTimeInterface $to): int
    {
        $from = is_string($from) ? new \DateTimeImmutable($from) : $from;
        $to   = is_string($to)   ? new \DateTimeImmutable($to)   : $to;
        return (int) abs(($to->getTimestamp() - $from->getTimestamp()) / 3600);
    }
}

if (!function_exists('diff_in_minutes')) {
    /**
     * Calculate the number of minutes between two dates.
     *
     * @param  string|\DateTimeInterface $from
     * @param  string|\DateTimeInterface $to
     * @return int
     */
    function diff_in_minutes(string|\DateTimeInterface $from, string|\DateTimeInterface $to): int
    {
        $from = is_string($from) ? new \DateTimeImmutable($from) : $from;
        $to   = is_string($to)   ? new \DateTimeImmutable($to)   : $to;
        return (int) abs(($to->getTimestamp() - $from->getTimestamp()) / 60);
    }
}

if (!function_exists('add_days')) {
    /**
     * Add a number of days to a date.
     *
     * @param  \DateTimeInterface $date
     * @param  int                $days
     * @return \DateTimeImmutable
     */
    function add_days(\DateTimeInterface $date, int $days): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($date)->modify("{$days} days");
    }
}

if (!function_exists('add_months')) {
    /**
     * Add a number of months to a date.
     *
     * @param  \DateTimeInterface $date
     * @param  int                $months
     * @return \DateTimeImmutable
     */
    function add_months(\DateTimeInterface $date, int $months): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($date)->modify("{$months} months");
    }
}

if (!function_exists('add_years')) {
    /**
     * Add a number of years to a date.
     *
     * @param  \DateTimeInterface $date
     * @param  int                $years
     * @return \DateTimeImmutable
     */
    function add_years(\DateTimeInterface $date, int $years): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($date)->modify("{$years} years");
    }
}

if (!function_exists('start_of_week')) {
    /**
     * Return the start of the week (Monday) for the given date.
     *
     * @param  \DateTimeInterface|null $date Defaults to today.
     * @return \DateTimeImmutable
     */
    function start_of_week(?\DateTimeInterface $date = null): \DateTimeImmutable
    {
        $dt = $date ? \DateTimeImmutable::createFromInterface($date) : new \DateTimeImmutable();
        return $dt->modify('monday this week')->setTime(0, 0, 0);
    }
}

if (!function_exists('end_of_week')) {
    /**
     * Return the end of the week (Sunday) for the given date.
     *
     * @param  \DateTimeInterface|null $date Defaults to today.
     * @return \DateTimeImmutable
     */
    function end_of_week(?\DateTimeInterface $date = null): \DateTimeImmutable
    {
        $dt = $date ? \DateTimeImmutable::createFromInterface($date) : new \DateTimeImmutable();
        return $dt->modify('sunday this week')->setTime(23, 59, 59);
    }
}

if (!function_exists('start_of_month')) {
    /**
     * Return the first day of the month for the given date.
     *
     * @param  \DateTimeInterface|null $date
     * @return \DateTimeImmutable
     */
    function start_of_month(?\DateTimeInterface $date = null): \DateTimeImmutable
    {
        $dt = $date ? \DateTimeImmutable::createFromInterface($date) : new \DateTimeImmutable();
        return $dt->modify('first day of this month')->setTime(0, 0, 0);
    }
}

if (!function_exists('end_of_month')) {
    /**
     * Return the last day of the month for the given date.
     *
     * @param  \DateTimeInterface|null $date
     * @return \DateTimeImmutable
     */
    function end_of_month(?\DateTimeInterface $date = null): \DateTimeImmutable
    {
        $dt = $date ? \DateTimeImmutable::createFromInterface($date) : new \DateTimeImmutable();
        return $dt->modify('last day of this month')->setTime(23, 59, 59);
    }
}

if (!function_exists('is_past')) {
    /**
     * Check whether a date is in the past.
     *
     * @param  string|\DateTimeInterface $date
     * @return bool
     */
    function is_past(string|\DateTimeInterface $date): bool
    {
        $dt = is_string($date) ? new \DateTimeImmutable($date) : $date;
        return $dt < new \DateTimeImmutable();
    }
}

if (!function_exists('is_future')) {
    /**
     * Check whether a date is in the future.
     *
     * @param  string|\DateTimeInterface $date
     * @return bool
     */
    function is_future(string|\DateTimeInterface $date): bool
    {
        $dt = is_string($date) ? new \DateTimeImmutable($date) : $date;
        return $dt > new \DateTimeImmutable();
    }
}

if (!function_exists('is_today')) {
    /**
     * Check whether a date is today.
     *
     * @param  string|\DateTimeInterface $date
     * @return bool
     */
    function is_today(string|\DateTimeInterface $date): bool
    {
        $dt = is_string($date) ? new \DateTimeImmutable($date) : $date;
        return $dt->format('Y-m-d') === (new \DateTimeImmutable())->format('Y-m-d');
    }
}

if (!function_exists('is_weekend')) {
    /**
     * Check whether a date falls on a weekend (Saturday or Sunday).
     *
     * @param  string|\DateTimeInterface $date
     * @return bool
     */
    function is_weekend(string|\DateTimeInterface $date): bool
    {
        $dt = is_string($date) ? new \DateTimeImmutable($date) : $date;
        return in_array((int) $dt->format('N'), [6, 7], true);
    }
}

if (!function_exists('is_weekday')) {
    /**
     * Check whether a date falls on a weekday (Monday to Friday).
     *
     * @param  string|\DateTimeInterface $date
     * @return bool
     */
    function is_weekday(string|\DateTimeInterface $date): bool
    {
        return !is_weekend($date);
    }
}

if (!function_exists('timestamp')) {
    /**
     * Return the Unix timestamp for a date string.
     *
     * @param  string $date Date string (default: 'now').
     * @return int
     */
    function timestamp(string $date = 'now'): int
    {
        return (new \DateTimeImmutable($date))->getTimestamp();
    }
}

if (!function_exists('date_range')) {
    /**
     * Generate an array of dates between two dates with a configurable step.
     *
     * @param  string|\DateTimeInterface $start
     * @param  string|\DateTimeInterface $end   Inclusive.
     * @param  string                    $step  Date modifier (default: '+1 day').
     * @param  string                    $format Output format (default: 'Y-m-d').
     * @return string[]
     */
    function date_range(
        string|\DateTimeInterface $start,
        string|\DateTimeInterface $end,
        string $step   = '+1 day',
        string $format = 'Y-m-d'
    ): array {
        $start   = is_string($start) ? new \DateTimeImmutable($start) : \DateTimeImmutable::createFromInterface($start);
        $end     = is_string($end)   ? new \DateTimeImmutable($end)   : \DateTimeImmutable::createFromInterface($end);
        $current = $start;
        $dates   = [];

        while ($current <= $end) {
            $dates[]  = $current->format($format);
            $current  = $current->modify($step);
        }

        return $dates;
    }
}

if (!function_exists('business_days')) {
    /**
     * Count weekdays (Mon–Fri) between two dates, inclusive.
     *
     * Does not account for public holidays.
     *
     * @param  string|\DateTimeInterface $from
     * @param  string|\DateTimeInterface $to
     * @return int
     */
    function business_days(string|\DateTimeInterface $from, string|\DateTimeInterface $to): int
    {
        $from    = is_string($from) ? new \DateTimeImmutable($from) : \DateTimeImmutable::createFromInterface($from);
        $to      = is_string($to)   ? new \DateTimeImmutable($to)   : \DateTimeImmutable::createFromInterface($to);
        $days    = 0;
        $current = $from;

        while ($current <= $to) {
            if ((int) $current->format('N') < 6) $days++;
            $current = $current->modify('+1 day');
        }

        return $days;
    }
}

if (!function_exists('age')) {
    /**
     * Calculate the age in years from a birth date to today (or a reference date).
     *
     * @param  string|\DateTimeInterface      $birthDate
     * @param  \DateTimeInterface|null        $referenceDate Defaults to now.
     * @return int
     */
    function age(string|\DateTimeInterface $birthDate, ?\DateTimeInterface $referenceDate = null): int
    {
        $birth = is_string($birthDate) ? new \DateTimeImmutable($birthDate) : $birthDate;
        $ref   = $referenceDate ?? new \DateTimeImmutable();
        return (int) $birth->diff($ref)->y;
    }
}

if (!function_exists('days_in_month')) {
    /**
     * Return the number of days in a given month and year.
     *
     * @param  int $month 1–12 (default: current month).
     * @param  int $year  Four-digit year (default: current year).
     * @return int
     */
    function days_in_month(int $month = 0, int $year = 0): int
    {
        $month = $month ?: (int) date('n');
        $year  = $year  ?: (int) date('Y');
        return (int) (new \DateTimeImmutable("{$year}-{$month}-01"))->format('t');
    }
}

// ============================================================================
// ARRAYS
// ============================================================================

if (!function_exists('is_empty')) {
    /**
     * Check whether a value is considered empty (null, '', or []).
     *
     * @param  mixed $value
     * @return bool
     */
    function is_empty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }
}

if (!function_exists('array_get')) {
    /**
     * Get a value from an array using dot-notation.
     *
     * Example: array_get($user, 'address.city', 'N/A')
     *
     * @param  array      $array
     * @param  string|int $key
     * @param  mixed      $default
     * @return mixed
     */
    function array_get(array $array, string|int $key, mixed $default = null): mixed
    {
        if (isset($array[$key])) return $array[$key];

        if (is_string($key) && str_contains($key, '.')) {
            $current = $array;
            foreach (explode('.', $key) as $k) {
                if (!is_array($current) || !array_key_exists($k, $current)) return $default;
                $current = $current[$k];
            }
            return $current;
        }

        return $default;
    }
}

if (!function_exists('array_set')) {
    /**
     * Set a value in an array using dot-notation, creating intermediate keys.
     *
     * @param  array  $array Passed by reference.
     * @param  string $key
     * @param  mixed  $value
     * @return void
     */
    function array_set(array &$array, string $key, mixed $value): void
    {
        $keys    = explode('.', $key);
        $current = &$array;
        foreach ($keys as $k) {
            if (!isset($current[$k]) || !is_array($current[$k])) $current[$k] = [];
            $current = &$current[$k];
        }
        $current = $value;
    }
}

if (!function_exists('array_forget')) {
    /**
     * Remove a key from an array using dot-notation.
     *
     * @param  array  $array Passed by reference.
     * @param  string $key
     * @return void
     */
    function array_forget(array &$array, string $key): void
    {
        $keys    = explode('.', $key);
        $current = &$array;
        while (count($keys) > 1) {
            $k = array_shift($keys);
            if (!isset($current[$k]) || !is_array($current[$k])) return;
            $current = &$current[$k];
        }
        unset($current[array_shift($keys)]);
    }
}

if (!function_exists('array_only')) {
    /**
     * Return only the specified keys from an array.
     *
     * @param  array    $array
     * @param  string[] $keys
     * @return array
     */
    function array_only(array $array, array $keys): array
    {
        return array_intersect_key($array, array_flip($keys));
    }
}

if (!function_exists('array_except')) {
    /**
     * Return the array without the specified keys.
     *
     * @param  array    $array
     * @param  string[] $keys
     * @return array
     */
    function array_except(array $array, array $keys): array
    {
        return array_diff_key($array, array_flip($keys));
    }
}

if (!function_exists('array_flatten')) {
    /**
     * Flatten a multi-dimensional array into a single level.
     *
     * @param  array $array
     * @return array
     */
    function array_flatten(array $array): array
    {
        $result = [];
        array_walk_recursive($array, function ($v) use (&$result) { $result[] = $v; });
        return $result;
    }
}

if (!function_exists('array_wrap')) {
    /**
     * Ensure a value is returned as an array.
     *
     * @param  mixed $value
     * @return array
     */
    function array_wrap(mixed $value): array
    {
        if (is_null($value)) return [];
        return is_array($value) ? $value : [$value];
    }
}

if (!function_exists('array_pluck')) {
    /**
     * Extract a column from an array of arrays or objects.
     *
     * @param  array       $array
     * @param  string      $key
     * @param  string|null $indexBy Column to use as the result index.
     * @return array
     */
    function array_pluck(array $array, string $key, ?string $indexBy = null): array
    {
        $result = [];
        foreach ($array as $item) {
            $value = is_array($item) ? ($item[$key] ?? null) : ($item->$key ?? null);
            if ($indexBy !== null) {
                $index          = is_array($item) ? ($item[$indexBy] ?? null) : ($item->$indexBy ?? null);
                $result[$index] = $value;
            } else {
                $result[] = $value;
            }
        }
        return $result;
    }
}

if (!function_exists('array_group_by')) {
    /**
     * Group an array of arrays/objects by a column's value.
     *
     * @param  array  $array
     * @param  string $key
     * @return array
     */
    function array_group_by(array $array, string $key): array
    {
        $result = [];
        foreach ($array as $item) {
            $group            = is_array($item) ? ($item[$key] ?? null) : ($item->$key ?? null);
            $result[$group][] = $item;
        }
        return $result;
    }
}

if (!function_exists('array_key_first_value')) {
    /**
     * Return the value of the first key in an array.
     *
     * @param  array $array
     * @return mixed
     */
    function array_key_first_value(array $array): mixed
    {
        $key = array_key_first($array);
        return $key !== null ? $array[$key] : null;
    }
}

if (!function_exists('array_sum_column')) {
    /**
     * Sum a numeric column across an array of arrays.
     *
     * @param  array  $array
     * @param  string $key
     * @return int|float
     */
    function array_sum_column(array $array, string $key): int|float
    {
        return array_sum(array_column($array, $key));
    }
}

if (!function_exists('array_unique_by')) {
    /**
     * Remove duplicate items from an array based on a column key.
     *
     * @param  array  $array
     * @param  string $key
     * @return array
     */
    function array_unique_by(array $array, string $key): array
    {
        $seen = []; $result = [];
        foreach ($array as $item) {
            $v = is_array($item) ? ($item[$key] ?? null) : ($item->$key ?? null);
            if (!in_array($v, $seen, true)) { $seen[] = $v; $result[] = $item; }
        }
        return $result;
    }
}

if (!function_exists('array_paginate')) {
    /**
     * Paginate an array and return items with metadata.
     *
     * @param  array $array
     * @param  int   $perPage
     * @param  int   $page    Current page (default: 1).
     * @return array{data:array,total:int,per_page:int,current_page:int,last_page:int,from:int,to:int,has_more:bool}
     */
    function array_paginate(array $array, int $perPage, int $page = 1): array
    {
        $total = count($array);
        $items = array_slice($array, ($page - 1) * $perPage, $perPage);
        return [
            'data'         => $items,
            'total'        => $total,
            'per_page'     => $perPage,
            'current_page' => $page,
            'last_page'    => (int) ceil($total / $perPage),
            'from'         => (($page - 1) * $perPage) + 1,
            'to'           => min($page * $perPage, $total),
            'has_more'     => $page < (int) ceil($total / $perPage),
        ];
    }
}

if (!function_exists('array_map_keys')) {
    /**
     * Apply a callback to the keys of an array, keeping the values unchanged.
     *
     * @param  array    $array
     * @param  callable $callback
     * @return array
     */
    function array_map_keys(array $array, callable $callback): array
    {
        $result = [];
        foreach ($array as $key => $value) { $result[$callback($key)] = $value; }
        return $result;
    }
}

if (!function_exists('collect')) {
    /**
     * Create a new Collection instance from an array.
     *
     * @param  array $items
     * @return Collection
     */
    function collect(array $items = []): Collection
    {
        return new Collection($items);
    }
}

// ============================================================================
// VALIDATION
// ============================================================================

if (!function_exists('validate')) {
    /**
     * Validate data against a set of rules.
     *
     * On failure: redirects back with errors and old input for web requests,
     * or throws ValidationException for JSON/Ajax requests.
     *
     * @param  array $data
     * @param  array $rules
     * @param  array $messages Custom error messages.
     * @return array           Validated data on success.
     * @throws ValidationException For JSON requests on validation failure.
     */
    function validate(array $data, array $rules, array $messages = []): array
    {
        try {
            return Validator::make($data, $rules, $messages)->validate();
        } catch (ValidationException $e) {
            if (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')) {
                throw $e;
            }

            Session::start();
            Session::flash('_errors',    ['default' => $e->errors()]);
            Session::flash('_old_input', $data);

            header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/'), true, 302);
            exit;
        }
    }
}

// ============================================================================
// CACHE
// ============================================================================

if (!function_exists('cache')) {
    /**
     * Access the cache system.
     *
     * - cache()                       → Cache class string (for static calls)
     * - cache('key')                  → Cache::get('key')
     * - cache('key', $default)        → Cache::get('key', $default)
     * - cache(['key' => $val], $ttl)  → Cache::put('key', $val, $ttl)
     *
     * @param  string|array|null $key
     * @param  mixed             $default
     * @param  int               $ttl     TTL in seconds (default: 3600).
     * @return mixed
     */
    function cache(string|array|null $key = null, mixed $default = null, int $ttl = 3600): mixed
    {
        if ($key === null) return Cache::class;

        if (is_array($key)) {
            foreach ($key as $k => $v) Cache::put((string) $k, $v, $ttl);
            return null;
        }

        return Cache::get($key, $default);
    }
}

// ============================================================================
// LOGGER
// ============================================================================

if (!function_exists('logger')) {
    /**
     * Log a message at the given level.
     *
     * - logger('message')                 → debug
     * - logger('message', [], 'info')     → info
     * - logger('message', ['key'=>'val']) → debug with context
     *
     * @param  string $message
     * @param  array  $context
     * @param  string $level   debug|info|warning|error|critical
     * @return void
     */
    function logger(string $message, array $context = [], string $level = 'debug'): void
    {
        match ($level) {
            'info'     => Log::info($message, $context),
            'warning'  => Log::warning($message, $context),
            'error'    => Log::error($message, $context),
            'critical' => Log::critical($message, $context),
            default    => Log::debug($message, $context),
        };
    }
}

// ============================================================================
// QUEUE
// ============================================================================

if (!function_exists('dispatch')) {
    /**
     * Dispatch a job to the queue.
     *
     * @param  \Slenix\Supports\Queue\Job $job
     * @param  string                     $queue  Queue channel override.
     * @param  int                        $delay  Delay in seconds.
     * @return string                             Job ID.
     */
    function dispatch(\Slenix\Supports\Queue\Job $job, string $queue = '', int $delay = 0): string
    {
        $basePath = (defined('STORAGE_PATH') ? STORAGE_PATH : dirname(__DIR__, 3) . '/storage') . '/queue';
        \Slenix\Supports\Queue\Queue::setBasePath($basePath);
        return \Slenix\Supports\Queue\Queue::push($job, $queue, $delay);
    }
}

if (!function_exists('queue')) {
    /**
     * Return the Queue class for direct interaction.
     *
     * @return string
     */
    function queue(): string
    {
        return \Slenix\Supports\Queue\Queue::class;
    }
}

// ============================================================================
// STORAGE
// ============================================================================

if (!function_exists('storage')) {
    /**
     * Access a storage disk instance.
     *
     * - storage()          → default disk (StorageDisk)
     * - storage('local')   → private disk
     * - storage('public')  → public disk
     *
     * @param  string $disk
     * @return \Slenix\Supports\Storage\StorageDisk
     */
    function storage(string $disk = 'public'): \Slenix\Supports\Storage\StorageDisk
    {
        return Storage::disk($disk);
    }
}

if (!function_exists('storage_url')) {
    /**
     * Generate a public URL for a file on the public disk.
     *
     * @param  string $path e.g. 'avatars/user-1.jpg'
     * @return string
     */
    function storage_url(string $path): string
    {
        return Storage::disk('public')->url($path);
    }
}

// ============================================================================
// AUTH
// ============================================================================

if (!function_exists('auth')) {
    /**
     * Return the AuthManager or a specific guard instance.
     *
     * @param  string|null $guard Guard name ('web', 'api', …) or null for default.
     * @return AuthManager|GuardInterface
     */
    function auth(?string $guard = null): AuthManager|GuardInterface
    {
        return Auth::resolve($guard);
    }
}

// ============================================================================
// LUNA — Global template variables
// ============================================================================

if (class_exists(Luna::class)) {

    // Routing
    Luna::share('route',        fn(string $name, array $params = []): ?string => Router::route($name, $params));

    // CSRF
    Luna::share('csrf_token',   fn(): string => csrf_token());
    Luna::share('csrf_field',   fn(): string => csrf_field());

    // Forms
    Luna::share('old',          fn(string $key, mixed $default = ''): mixed => old($key, $default));
    Luna::share('errors',       fn(?string $field = null): mixed => errors($field));
    Luna::share('has_error',    fn(string $field): bool => has_error($field));

    // Flash
    Luna::share('flash',        fn(): FlashMessage => flash());

    // URL / navigation
    Luna::share('is_active',    fn(string $p, string $a = 'active', string $i = ''): string => is_active($p, $a, $i));
    Luna::share('asset',        fn(string $path): string => asset($path));
    Luna::share('url',          fn(string $path = '', array $q = []): string => url($path, $q));
    Luna::share('current_url',  fn(): string => current_url());
    Luna::share('request_path', fn(): string => request_path());

    // Dates
    Luna::share('now',          fn(): \DateTimeImmutable => now());
    Luna::share('format_date',  fn(string $d, string $f = 'd/m/Y H:i:s'): ?string => format_date($d, $f));
    Luna::share('human_date',   fn(string|\DateTimeInterface $d): string => human_date($d));

    // Numbers / formatting
    Luna::share('currency',     fn(float $v, string $s = '$'): string => currency($v, $s));
    Luna::share('format_bytes', fn(int $b, int $p = 2): string => format_bytes($b, $p));
    Luna::share('number_short', fn(float|int $n, int $p = 1): string => number_short($n, $p));

    // Session (array shorthand for templates)
    Luna::share('Session', [
        'has'      => fn(string $key): bool   => Session::has($key),
        'get'      => fn(string $key, mixed $d = null) => Session::get($key, $d),
        'set'      => fn(string $key, mixed $v) => Session::set($key, $v),
        'flash'    => fn(string $key, mixed $v) => Session::flash($key, $v),
        'getFlash' => fn(string $key, mixed $d = null) => Session::getFlash($key, $d),
        'hasFlash' => fn(string $key): bool    => Session::hasFlash($key),
        'remove'   => fn(string $key) => Session::remove($key),
        'all'      => fn(): array => Session::all(),
        'destroy'  => fn() => Session::destroy(),
        'id'       => fn(): string => session_id(),
    ]);

    // Debug (local only)
    if (function_exists('is_local') && is_local()) {
        Luna::share('benchmark',    fn(): float => benchmark());
        Luna::share('memory_usage', fn(): string => memory_usage());
    }
}