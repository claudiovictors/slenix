<?php

/*
|--------------------------------------------------------------------------
| CSRF Class
|--------------------------------------------------------------------------
|
| Manages CSRF tokens to protect mutable requests (POST, PUT, etc)
| against Cross-Site Request Forgery.
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Security;

class CSRF
{
    /** @var string Session storage key for the token */
    private const SESSION_KEY  = '_csrf_token';

    /** @var int Byte length for token generation */
    private const TOKEN_LENGTH = 32;

    /** @var string Expected HTTP Header name */
    private const HEADER_NAME  = 'HTTP_X_CSRF_TOKEN';

    /** @var string Expected form field name */
    private const FIELD_NAME   = '_csrf_token';

    /** @var string[] URL patterns to exclude from verification */
    private static array $except = [];

    /**
     * Gets or generates the active CSRF token.
     * @return string
     */
    public static function token(): string
    {
        Session::start();

        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(self::TOKEN_LENGTH));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    /**
     * Forces token regeneration for security purposes.
     * @return string
     */
    public static function regenerate(): string
    {
        Session::start();
        $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(self::TOKEN_LENGTH));
        return $_SESSION[self::SESSION_KEY];
    }

    /**
     * Validates the request token against the session.
     * @return bool
     */
    public static function verify(): bool
    {
        $tokenInSession = $_SESSION[self::SESSION_KEY] ?? null;

        if (!$tokenInSession) return false;

        $tokenInRequest = static::getTokenFromRequest();

        if (!$tokenInRequest) return false;

        return hash_equals($tokenInSession, $tokenInRequest);
    }

    /**
     * Validates the token and halts execution on failure.
     * @throws \RuntimeException
     */
    public static function verifyOrFail(): void
    {
        if (!static::verify()) {
            http_response_code(419);
            throw new \RuntimeException('Invalid or missing CSRF token.', 419);
        }
    }

    /**
     * Checks if the HTTP method requires protection.
     * @return bool
     */
    public static function isSafeMethod(): bool
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        return in_array($method, ['GET', 'HEAD', 'OPTIONS'], true);
    }

    /**
     * Determines if verification should proceed.
     * @return bool
     */
    public static function shouldVerify(): bool
    {
        return !static::isSafeMethod();
    }

    /**
     * Generates a hidden HTML input field for forms.
     * @return string
     */
    public static function field(): string
    {
        $token = htmlspecialchars(static::token(), ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="' . self::FIELD_NAME . '" value="' . $token . '">';
    }

    /**
     * Generates a meta tag for AJAX headers.
     * @return string
     */
    public static function meta(): string
    {
        $token = htmlspecialchars(static::token(), ENT_QUOTES, 'UTF-8');
        return '<meta name="csrf-token" content="' . $token . '">';
    }

    /**
     * Returns both meta tag and hidden field.
     * @return string
     */
    public static function fieldAndMeta(): string
    {
        return static::meta() . "\n" . static::field();
    }

    /**
     * Registers exclusion patterns (wildcards supported).
     * @param array $patterns
     */
    public static function except(array $patterns): void
    {
        self::$except = $patterns;
    }

    /**
     * Verifies if the current path is whitelisted.
     * @return bool
     */
    public static function isExcluded(): bool
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?? $uri;

        foreach (self::$except as $pattern) {
            $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#';
            if (preg_match($regex, $path)) return true;
        }

        return false;
    }

    /**
     * Internal retrieval logic for the token from various request parts.
     * @return string|null
     */
    private static function getTokenFromRequest(): ?string
    {
        $header = $_SERVER[self::HEADER_NAME] ?? $_SERVER['HTTP_X_XSRF_TOKEN'] ?? null;
        if ($header) return trim($header);

        if (isset($_POST[self::FIELD_NAME])) return trim($_POST[self::FIELD_NAME]);

        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $body = json_decode(file_get_contents('php://input'), true);
            if (is_array($body) && isset($body[self::FIELD_NAME])) {
                return trim($body[self::FIELD_NAME]);
            }
        }

        return null;
    }
}