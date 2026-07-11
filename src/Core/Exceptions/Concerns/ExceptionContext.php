<?php

/*
|--------------------------------------------------------------------------
| ExceptionContext — Slenix Framework
|--------------------------------------------------------------------------
|
| Collects runtime context (request, headers, session, environment)
| to be displayed on the debug error page. Sensitive keys are redacted
| automatically before any output is produced.
|
*/

declare(strict_types=1);

namespace Slenix\Core\Exceptions\Concerns;

class ExceptionContext
{
    /**
     * Keys whose values are replaced with '[REDACTED]' in all context panels.
     *
     * @var string[]
     */
    private static array $sensitiveKeys = [
        'password', 'password_confirmation', 'secret', 'token',
        'api_key', 'apikey', 'auth', 'authorization',
        'app_key', 'jwt_secret', 'db_password',
        'smtp_password', 'smtp_username',
        'credit_card', 'card_number', 'cvv',
    ];

    /**
     * Collects all context groups for the debug page.
     *
     * @return array<string, array<string, string>>
     */
    public function collect(): array
    {
        return [
            'Request'     => $this->requestContext(),
            'GET'         => $this->sanitize($_GET),
            'POST'        => $this->sanitize($_POST),
            'Cookies'     => $this->sanitize($_COOKIE),
            'Session'     => $this->sessionContext(),
            'Server'      => $this->serverContext(),
            'Environment' => $this->envContext(),
        ];
    }

    // -------------------------------------------------------------------------
    // Context Collectors
    // -------------------------------------------------------------------------

    /**
     * Basic request metadata.
     *
     * @return array<string, string>
     */
    private function requestContext(): array
    {
        return $this->sanitize([
            'URL'         => ($_SERVER['REQUEST_SCHEME'] ?? 'http')
                . '://'
                . ($_SERVER['HTTP_HOST'] ?? 'localhost')
                . ($_SERVER['REQUEST_URI'] ?? '/'),
            'Method'      => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
            'IP'          => $_SERVER['REMOTE_ADDR'] ?? '–',
            'User-Agent'  => $_SERVER['HTTP_USER_AGENT'] ?? '–',
            'Accept'      => $_SERVER['HTTP_ACCEPT'] ?? '–',
            'Content-Type'=> $_SERVER['CONTENT_TYPE'] ?? '–',
        ]);
    }

    /**
     * Active session data (if session is started).
     *
     * @return array<string, string>
     */
    private function sessionContext(): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return ['status' => 'No active session'];
        }

        return $this->sanitize($_SESSION ?? []);
    }

    /**
     * A curated subset of $_SERVER (HTTP_* headers).
     *
     * @return array<string, string>
     */
    private function serverContext(): array
    {
        $server = array_filter(
            $_SERVER,
            static fn (string $k) => str_starts_with($k, 'HTTP_')
                || in_array($k, ['SERVER_SOFTWARE', 'SERVER_NAME', 'HTTPS', 'PHP_SELF'], true),
            ARRAY_FILTER_USE_KEY
        );

        return $this->sanitize($server);
    }

    /**
     * App-level environment variables (from $_ENV / getenv).
     * Strips system internals; only shows keys with common app prefixes.
     *
     * @return array<string, string>
     */
    private function envContext(): array
    {
        $appPrefixes = ['APP_', 'DB_', 'MAIL_', 'SMTP_', 'CACHE_', 'LOG_', 'JWT_', 'CSP_', 'STORAGE_'];
        $env         = [];

        foreach ($_ENV as $key => $value) {
            foreach ($appPrefixes as $prefix) {
                if (str_starts_with($key, $prefix)) {
                    $env[$key] = $value;
                    break;
                }
            }
        }

        return $this->sanitize($env);
    }

    // -------------------------------------------------------------------------
    // Sanitisation
    // -------------------------------------------------------------------------

    /**
     * Recursively redacts sensitive values from any array.
     *
     * @param  array<string, mixed> $data
     * @return array<string, string>
     */
    private function sanitize(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            $lowerKey = strtolower((string) $key);

            $isSensitive = false;
            foreach (self::$sensitiveKeys as $k) {
                if (str_contains($lowerKey, $k)) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                $result[(string) $key] = '[REDACTED]';
                continue;
            }

            if (is_array($value)) {
                $result[(string) $key] = json_encode(
                    $this->sanitize($value),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ) ?: '[]';
                continue;
            }

            $result[(string) $key] = (string) $value;
        }

        return $result;
    }
}