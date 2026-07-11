<?php

/*
|--------------------------------------------------------------------------
| Kernel Class — Slenix Framework
|--------------------------------------------------------------------------
|
| The application core. Orchestrates the full request lifecycle:
|
|   1. loadEnvironment()      — Parses the .env file via EnvLoader
|   2. configurePHP()         — Overrides PHP runtime settings (MUST run before output)
|   3. sendSecurityHeaders()  — Emits HTTP security headers
|   4. startSession()         — Initialises session management
|   5. verifyCsrf()           — Validates CSRF token on unsafe methods
|   6. bootServices()         — Initialises Log, Cache, and Storage
|   7. dispatch()             — Loads routes and dispatches to Router
|
| PHP 8.4 note: E_STRICT was removed; the error_reporting() call in
| configurePHP() no longer references it.
|
*/

declare(strict_types=1);

namespace Slenix\Core;

use Slenix\Core\Console\MaintenanceCommand;
use Slenix\Core\EnvLoader;
use Slenix\Core\Exceptions\ErrorHandler;
use Slenix\Http\Routing\Router;
use Slenix\Supports\Cache\Cache;
use Slenix\Supports\Logging\Log;
use Slenix\Supports\Redis\RedisConnection;
use Slenix\Supports\Security\CSRF;
use Slenix\Supports\Security\Session;
use Slenix\Supports\Storage\Storage;
use Slenix\Supports\Template\Luna;

class Kernel
{
    /** @var float Application boot timestamp (set in public/index.php). */
    private float $startTime;

    /** @var ErrorHandler Centralised error and exception handler. */
    private ErrorHandler $errorHandler;

    /**
     * Routes excluded from CSRF verification.
     *
     * Supports wildcard (*) patterns:
     *   '/api/*'          matches /api/users, /api/login, etc.
     *   '/webhook/stripe' matches exactly that path
     *
     * @var array<string>
     */
    private array $csrfExcept = [
        '/api/*',
    ];

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * @param float $startTime Microtime from application entry point.
     */
    public function __construct(float $startTime)
    {
        $this->startTime = $startTime;
        $this->errorHandler = new ErrorHandler();
    }

    // -------------------------------------------------------------------------
    // Entry point
    // -------------------------------------------------------------------------

    /**
     * Boots the application and dispatches the incoming HTTP request.
     *
     * configurePHP() is intentionally called FIRST inside the try block so
     * that ini_set() for session hardening always runs before any output or
     * header() call. This prevents the "headers already sent" error that
     * occurred when the ErrorHandler emitted output before Kernel ran ini_set.
     *
     * @return void
     */
    public function run(): void
    {
        // Register handlers before anything else so even very early errors
        // are caught. The handlers themselves produce no output.
        set_error_handler([$this->errorHandler, 'handleError']);
        set_exception_handler([$this->errorHandler, 'handleException']);
        register_shutdown_function([$this, 'handleShutdown']);
        class_alias(Router::class, 'Router');

        try {
            // ── Stage 1: Load .env ──────────────────────────────────────────
            // Must come first so every subsequent stage can read env values.
            $this->loadEnvironment();

            // ── Stage 2: Configure PHP runtime ─────────────────────────────
            // CRITICAL: ini_set() calls for session.* MUST happen before any
            // output is sent (echo, header(), or even whitespace before <?php).
            // Placing this immediately after loadEnvironment() — before security
            // headers, before session start, before everything — is the safest
            // position. Moving it later would risk the "cannot change session
            // ini settings after headers have been sent" error on PHP 8.4+.
            $this->configurePHP();

            // ── Stage 2.5: Maintenance mode ─────────────────────────────────
            $this->checkMaintenanceMode();

            // ── Stage 3: Security headers ───────────────────────────────────
            $this->sendSecurityHeaders();

            // ── Stage 4: Session ────────────────────────────────────────────
            $this->startSession();

            // ── Stage 5: CSRF ───────────────────────────────────────────────
            $this->verifyCsrf();

            // ── Stage 6: Services ───────────────────────────────────────────
            $this->bootServices();

            // ── Stage 7: Dispatch ───────────────────────────────────────────
            $this->dispatch();

        } catch (\Throwable $e) {
            // Best-effort logging — failure here must not mask the original error
            try {
                Log::exception($e);
            } catch (\Throwable) {
                // Silently ignored to prevent recursive error loops
            }

            $this->errorHandler->handleException($e);
        }
    }

    // -------------------------------------------------------------------------
    // Shutdown handler
    // -------------------------------------------------------------------------

    /**
     * Catches fatal errors (E_ERROR, E_PARSE, etc.) that bypass set_error_handler.
     *
     * @return void
     */
    public function handleShutdown(): void
    {
        if (RedisConnection::isConnected()) {
            RedisConnection::disconnect();
        }

        $error = error_get_last();

        $fatalTypes = [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE];

        if ($error !== null && in_array($error['type'], $fatalTypes, true)) {
            $this->errorHandler->handleShutdownError($error);
        }
    }

    // -------------------------------------------------------------------------
    // Stage 1 — Environment
    // -------------------------------------------------------------------------

    /**
     * Loads the .env file from the project root.
     *
     * @return void
     */
    private function loadEnvironment(): void
    {
        try {
            EnvLoader::load($this->basePath('.env'));
        } catch (\Throwable $e) {
            $this->errorHandler->handleEnvError($e);
        }
    }

    // -------------------------------------------------------------------------
    // Stage 2 — PHP Runtime Configuration
    // -------------------------------------------------------------------------

    /**
     * Adjusts PHP ini settings based on environment variables.
     *
     *     This method MUST be called before any output (echo, header, whitespace)
     *     to ensure ini_set() for session.* settings is accepted by PHP.
     *
     * PHP 8.4 note: E_STRICT was removed. The error_reporting() bitmask no
     * longer includes ~E_STRICT to avoid "Undefined constant" warnings.
     *
     * @return void
     */
    private function configurePHP(): void
    {
        $debug = $this->isDebug();

        // Error visibility
        ini_set('display_errors', $debug ? '1' : '0');

        // E_STRICT was removed in PHP 8.4; guard with defined() to stay compatible
        // with PHP 8.1–8.3 as well.
        $errorMask = $debug
            ? E_ALL
            : E_ALL & ~E_DEPRECATED & ~(defined('E_STRICT') ? E_STRICT : 0);

        error_reporting($errorMask);

        // Hide PHP version from response headers
        ini_set('expose_php', '0');

        // Timezone (validated) + Locale
        $this->configureTimezone();
        $this->configureLocale();

        // ── Session hardening ──────────────────────────────────────────────
        // All ini_set('session.*') calls must happen before session_start().
        // They must also happen before any output or header(), which is why
        // configurePHP() is always the FIRST stage after loadEnvironment().
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');

        if ($this->isHttps()) {
            ini_set('session.cookie_secure', '1');
        }
    }

    /**
     * Applies APP_TIMEZONE from .env, validating it against PHP's known
     * timezone database first. Falls back to UTC (with a logged warning)
     * if the configured value is invalid, instead of letting
     * date_default_timezone_set() silently emit a PHP warning.
     *
     * @return void
     */
    private function configureTimezone(): void
    {
        $timezone = (string) EnvLoader::get('APP_TIMEZONE', 'UTC');

        if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            try {
                Log::warning("Invalid APP_TIMEZONE '{$timezone}' in .env — falling back to UTC.");
            } catch (\Throwable) {
                // Logging not booted yet this early — safe to ignore.
            }
            $timezone = 'UTC';
        }

        date_default_timezone_set($timezone);
    }

    /**
     * Applies APP_LOCALE from .env via setlocale(), used by native PHP
     * locale-aware functions (strftime-style formatting, number_format
     * defaults on some platforms, etc). Also stores the resolved locale
     * as a global constant so other parts of the framework (e.g. a future
     * Date/translation helper) can read it without re-parsing .env.
     *
     * APP_LOCALE is expected to be a short code ('en', 'pt', 'pt_BR', ...).
     * Since installed system locales vary by OS/server, several common
     * variants are attempted in order; if none are available, setlocale()
     * simply keeps the platform default — this never throws.
     *
     * @return void
     */
    private function configureLocale(): void
    {
        $locale = (string) EnvLoader::get('APP_LOCALE', 'en');

        defined('APP_LOCALE') or define('APP_LOCALE', $locale);

        $variants = $this->localeVariants($locale);

        if (!empty($variants)) {
            setlocale(LC_ALL, ...$variants);
        }
    }

    /**
     * Builds a list of candidate system locale strings for a short locale
     * code, covering common Linux/macOS and Windows naming conventions.
     *
     * @param  string $locale Short code, e.g. 'en', 'pt', 'pt_BR'.
     * @return string[] Ordered list of candidates to pass to setlocale().
     */
    private function localeVariants(string $locale): array
    {
        $locale = str_replace('-', '_', $locale);

        $map = [
            'en' => ['en_US.UTF-8', 'en_US', 'English_United States.1252', 'en'],
            'en_US' => ['en_US.UTF-8', 'en_US', 'English_United States.1252'],
            'en_GB' => ['en_GB.UTF-8', 'en_GB', 'English_United Kingdom.1252'],
            'pt' => ['pt_PT.UTF-8', 'pt_PT', 'Portuguese_Portugal.1252', 'pt'],
            'pt_PT' => ['pt_PT.UTF-8', 'pt_PT', 'Portuguese_Portugal.1252'],
            'pt_BR' => ['pt_BR.UTF-8', 'pt_BR', 'Portuguese_Brazil.1252'],
            'es' => ['es_ES.UTF-8', 'es_ES', 'Spanish_Spain.1252', 'es'],
            'fr' => ['fr_FR.UTF-8', 'fr_FR', 'French_France.1252', 'fr'],
        ];

        return $map[$locale] ?? [$locale . '.UTF-8', $locale];
    }

    // -------------------------------------------------------------------------
    // Stage 3 — Security Headers
    // -------------------------------------------------------------------------

    /**
     * Emits HTTP security headers before any body output is sent.
     *
     * @return void
     */
    private function sendSecurityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        $appName = EnvLoader::get('APP_NAME', 'Slenix');

        header("X-Frame-Options: SAMEORIGIN");
        header("X-Content-Type-Options: nosniff");
        header("X-XSS-Protection: 1; mode=block");
        header("Referrer-Policy: strict-origin-when-cross-origin");
        header("X-Powered-By: {$appName}");

        // HSTS — production + HTTPS only + never on local/loopback hosts.
        // Prevents accidentally poisoning the browser's HSTS cache for
        // localhost/127.0.0.1 during local development (php celestial serve),
        // which would force HTTPS on a server that only speaks HTTP.
        if (!$this->isDebug() && $this->isHttps() && !$this->isLocalHost()) {
            header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
        }

        // Content-Security-Policy (opt-in via .env)
        if (EnvLoader::get('CSP_ENABLED', false)) {
            $policy = EnvLoader::get('CSP_POLICY', "default-src 'self';");
            header("Content-Security-Policy: {$policy}");
        }

        // Force HTTPS redirect
        if (EnvLoader::get('FORCE_HTTPS', false) && !$this->isHttps()) {
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $uri = $_SERVER['REQUEST_URI'] ?? '/';
            header("Location: https://{$host}{$uri}", true, 301);
            exit;
        }
    }

    /**
     * Checks whether the current request host is a loopback/local development
     * address, where HSTS should never be sent regardless of scheme detection.
     *
     * @return bool
     */
    private function isLocalHost(): bool
    {
        $host = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]);

        return in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.test');
    }

    // -------------------------------------------------------------------------
    // Stage 4 — Session
    // -------------------------------------------------------------------------

    /**
     * Starts the session, ages flash data, and registers the Session alias.
     *
     * @return void
     */
    private function startSession(): void
    {
        Session::start();
        Session::age();

        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $input = $_POST;
            unset($input['password'], $input['password_confirmation'], $input['_csrf_token']);
            Session::flashInput($input);
        }

        if (!class_exists('Session', false)) {
            class_alias(Session::class, 'Session');
        }
    }

    // -------------------------------------------------------------------------
    // Stage 5 — CSRF Protection
    // -------------------------------------------------------------------------

    /**
     * Verifies CSRF tokens on state-changing requests.
     *
     * @return void
     */
    private function verifyCsrf(): void
    {
        if (CSRF::isSafeMethod()) {
            return;
        }

        CSRF::except($this->csrfExcept);

        if (CSRF::isExcluded()) {
            return;
        }

        CSRF::token();
        CSRF::verifyOrFail();
    }

    // -------------------------------------------------------------------------
    // Stage 6 — Service Boot
    // -------------------------------------------------------------------------

    /**
     * Initialises static support services: Logging, Cache, and Storage.
     *
     * @return void
     */
    private function bootServices(): void
    {
        $storagePath = $this->basePath('storage');

        Log::setPath($storagePath . '/logs');
        Log::setChannel(EnvLoader::get('LOG_CHANNEL', 'slenix'));

        Cache::setPath($storagePath . '/cache');
        Cache::setPrefix(EnvLoader::get('CACHE_PREFIX', 'slenix_'));

        Storage::setDisk('public', $storagePath . '/app/public');
        Storage::setDisk('local', $storagePath . '/app/private');
        Storage::setDefaultDisk(EnvLoader::get('STORAGE_DISK', 'public'));

        Luna::setContainer(
            static fn(string $abstract) => \Slenix\Core\AppFactory::make($abstract)
        );

        $this->bootRedisIfNeeded();
    }

    /**
     * If any subsystem is configured to use Redis, verify connectivity
     * eagerly and fail with a clear error instead of an obscure exception
     * mid-request.
     *
     * @return void
     */
    private function bootRedisIfNeeded(): void
    {
        $usesRedis = strtolower((string) EnvLoader::get('CACHE_DRIVER', 'file')) === 'redis'
            || strtolower((string) EnvLoader::get('SESSION_DRIVER', 'native')) === 'redis';

        if (!$usesRedis) {
            return;
        }

        if (!RedisConnection::ping()) {
            throw new \RuntimeException(
                'Redis is configured (CACHE_DRIVER or SESSION_DRIVER) but the server is unreachable. ' .
                'Check REDIS_HOST/REDIS_PORT in .env and confirm the Redis server is running.'
            );
        }
    }

    // -------------------------------------------------------------------------
    // Stage 7 — Request Dispatching
    // -------------------------------------------------------------------------

    /**
     * Loads the web route file and dispatches the request to the Router.
     *
     * @throws \RuntimeException If routes/web.php does not exist.
     * @return void
     */
    private function dispatch(): void
    {
        $routes = $this->basePath('routes/web.php');

        if (!is_file($routes)) {
            throw new \RuntimeException("Route file not found at: {$routes}");
        }

        require_once $routes;
        Router::dispatch();
    }

    // -------------------------------------------------------------------------
    // Public utilities
    // -------------------------------------------------------------------------

    /**
     * Adds one or more routes/patterns to the CSRF exclusion list at runtime.
     *
     * @param  array<string> $patterns
     * @return static
     */
    public function addCsrfExcept(array $patterns): static
    {
        $this->csrfExcept = array_merge($this->csrfExcept, $patterns);
        return $this;
    }

    /**
     * Returns elapsed time in milliseconds since application boot.
     *
     * @return float
     */
    public function elapsedMs(): float
    {
        return round((microtime(true) - $this->startTime) * 1000, 3);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * @return bool
     */
    public function isDebug(): bool
    {
        return (bool) EnvLoader::get('APP_DEBUG', false);
    }

    /**
     * @return bool
     */
    public function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }

    /**
     * Halts the request with 503 if the application is in maintenance mode.
     *
     * @return void
     */
    private function checkMaintenanceMode(): void
    {
        $downFile = MaintenanceCommand::downFilePath();

        if (!is_file($downFile)) {
            return;
        }

        $payload = json_decode((string) file_get_contents($downFile), true) ?: [];
        $message = $payload['message'] ?? 'Service Unavailable.';
        $retryAfter = $payload['retry_after'] ?? null;

        http_response_code(503);

        if ($retryAfter !== null) {
            header('Retry-After: ' . $retryAfter);
        }

        header('Content-Type: text/html; charset=UTF-8');

        echo '<!DOCTYPE html><html lang="pt"><head><meta charset="UTF-8"><title>503 — Manutenção</title></head>'
            . '<body style="font-family: system-ui; text-align: center; padding: 4rem;">'
            . '<h1> Em manutenção</h1><p>' . htmlspecialchars($message) . '</p>'
            . '</body></html>';

        exit;
    }

    /**
     * Resolves a project-relative path to an absolute filesystem path.
     * Assumes Kernel.php lives at src/Core/Kernel.php (2 levels deep).
     *
     * @param  string $relative
     * @return string
     */
    private function basePath(string $relative): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . ltrim($relative, '/\\');
    }
}