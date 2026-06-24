<?php

/*
|--------------------------------------------------------------------------
| Kernel Class — Slenix Framework
|--------------------------------------------------------------------------
|
| The application core. Orchestrates the full request lifecycle:
|
|   1. loadEnvironment()   — Parses the .env file via EnvLoader
|   2. configurePHP()      — Overrides PHP runtime settings
|   3. sendSecurityHeaders() — Emits HTTP security headers
|   4. startSession()      — Initializes session management
|   5. verifyCsrf()        — Validates CSRF token on unsafe methods
|   6. bootServices()      — Initializes Log, Cache, and Storage
|   7. dispatch()          — Loads routes and dispatches to Router
|
*/

declare(strict_types=1);

namespace Slenix\Core;

use Slenix\Core\EnvLoader;
use Slenix\Http\Routing\Router;
use Slenix\Supports\Logging\Log;
use Slenix\Supports\Cache\Cache;
use Slenix\Supports\Security\CSRF;
use Slenix\Supports\Storage\Storage;
use Slenix\Supports\Security\Session;
use Slenix\Core\Exceptions\ErrorHandler;

class Kernel
{
    /** @var float Application boot timestamp (set in public/index.php). */
    private float $startTime;

    /** @var ErrorHandler Centralized error and exception handler. */
    private ErrorHandler $errorHandler;

    /**
     * Routes excluded from CSRF verification.
     *
     * Supports wildcard (*) patterns:
     *   '/api/*'          matches /api/users, /api/login, etc.
     *   '/webhook/stripe' matches exactly that path
     *
     * Can also be extended at runtime via addCsrfExcept().
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
    // Entry Point
    // -------------------------------------------------------------------------

    /**
     * Boots the application and dispatches the incoming HTTP request.
     *
     * Registers PHP error handlers first, then runs each lifecycle stage
     * in order. Any uncaught Throwable is logged (best-effort) and then
     * forwarded to ErrorHandler for a user-facing response.
     *
     * @return void
     */
    public function run(): void
    {
        set_error_handler([$this->errorHandler, 'handleError']);
        set_exception_handler([$this->errorHandler, 'handleException']);
        register_shutdown_function([$this, 'handleShutdown']);
        class_alias(Router::class, 'Router');

        try {
            $this->loadEnvironment();
            $this->configurePHP();
            $this->sendSecurityHeaders();
            $this->startSession();
            $this->verifyCsrf();
            $this->bootServices();
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
    // Shutdown Handler
    // -------------------------------------------------------------------------

    /**
     * Catches fatal errors (E_ERROR, E_PARSE, etc.) that bypass set_error_handler.
     *
     * Registered via register_shutdown_function() in run().
     *
     * @return void
     */
    public function handleShutdown(): void
    {
        $error = error_get_last();

        $fatalTypes = [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE];

        if ($error !== null && in_array($error['type'], $fatalTypes, true)) {
            $this->errorHandler->handleException(
                new \ErrorException(
                    $error['message'],
                    0,
                    $error['type'],
                    $error['file'],
                    $error['line']
                )
            );
        }
    }

    // -------------------------------------------------------------------------
    // Stage 1 — Environment
    // -------------------------------------------------------------------------

    /**
     * Loads the .env file from the project root via EnvLoader.
     *
     * On failure, delegates to ErrorHandler::handleEnvError() so a
     * meaningful message is shown instead of a blank screen.
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
     * - Enables/disables error display according to APP_DEBUG
     * - Sets the application timezone from APP_TIMEZONE
     * - Hardens session cookie settings (HttpOnly, SameSite, strict mode)
     * - Marks session cookie as Secure when running over HTTPS
     *
     * @return void
     */
    private function configurePHP(): void
    {
        $debug = $this->isDebug();

        // Error visibility
        ini_set('display_errors', $debug ? '1' : '0');
        error_reporting($debug ? E_ALL : E_ALL & ~E_DEPRECATED & ~E_STRICT);

        // Hide PHP version from response headers
        ini_set('expose_php', '0');

        // Timezone
        date_default_timezone_set(EnvLoader::get('APP_TIMEZONE', 'UTC'));

        // Session hardening
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.use_strict_mode', '1');

        if ($this->isHttps()) {
            ini_set('session.cookie_secure', '1');
        }
    }

    // -------------------------------------------------------------------------
    // Stage 3 — Security Headers
    // -------------------------------------------------------------------------

    /**
     * Emits HTTP security headers before any output is sent.
     *
     * Headers emitted:
     *   - X-Frame-Options          Prevents clickjacking
     *   - X-Content-Type-Options   Blocks MIME sniffing
     *   - X-XSS-Protection         Legacy XSS filter hint
     *   - Referrer-Policy          Controls referrer leakage
     *   - X-Powered-By             Branded (not PHP version)
     *   - HSTS                     Only on HTTPS + production
     *   - Content-Security-Policy  If CSP_ENABLED=true in .env
     *
     * Also handles FORCE_HTTPS redirect (301) if configured.
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

        // HSTS — only in production over HTTPS
        if (!$this->isDebug() && $this->isHttps()) {
            header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
        }

        // Content-Security-Policy (configured in .env)
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

    // -------------------------------------------------------------------------
    // Stage 4 — Session
    // -------------------------------------------------------------------------

    /**
     * Starts the session, ages flash data, and registers the Session alias.
     *
     *
     * Old input is NOT flashed here anymore. It is the responsibility of
     * RedirectResponse::withInput() to flash it explicitly before redirecting.
     * This avoids the race condition where the Kernel flashes input on every
     * POST request regardless of whether a redirect with ->withInput() follows.
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
     * Verifies CSRF tokens for all state-changing HTTP methods.
     *
     * Safe methods (GET, HEAD, OPTIONS) are skipped automatically.
     * Routes matching $csrfExcept patterns are also skipped.
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
     * Initializes static support services: Logging, Cache, and Storage.
     *
     * All paths are resolved relative to the project storage/ directory.
     * Configuration values are read via EnvLoader::get() to benefit from
     * type casting and defaults.
     *
     * @return void
     */
    private function bootServices(): void
    {
        $storagePath = $this->basePath('storage');

        // Logging
        Log::setPath($storagePath . '/logs');
        Log::setChannel(EnvLoader::get('LOG_CHANNEL', 'slenix'));

        // Cache
        Cache::setPath($storagePath . '/cache');
        Cache::setPrefix(EnvLoader::get('CACHE_PREFIX', 'slenix_'));

        // Storage disks
        Storage::setDisk('public', $storagePath . '/app/public');
        Storage::setDisk('local', $storagePath . '/app/private');
        Storage::setDefaultDisk(EnvLoader::get('STORAGE_DISK', 'public'));
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
    // Public Utilities
    // -------------------------------------------------------------------------

    /**
     * Adds one or more routes/patterns to the CSRF exclusion list at runtime.
     *
     * Useful for service providers or packages that register their own
     * webhook or API routes.
     *
     * Example:
     *   $kernel->addCsrfExcept(['/webhook/*', '/payment/ipn']);
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
     * Useful for performance logging or debug toolbars.
     *
     * @return float
     */
    public function elapsedMs(): float
    {
        return round((microtime(true) - $this->startTime) * 1000, 3);
    }

    // -------------------------------------------------------------------------
    // Private Helpers
    // -------------------------------------------------------------------------

    /**
     * Checks whether the application is running in debug mode.
     *
     * Uses EnvLoader::get() which returns a native bool after EnvLoader
     * auto-casts APP_DEBUG=true from the .env file.
     *
     * @return bool
     */
    public function isDebug(): bool
    {
        return (bool) EnvLoader::get('APP_DEBUG', false);
    }

    /**
     * Checks whether the current request is being served over HTTPS.
     *
     * Handles both direct HTTPS and reverse-proxy setups that forward
     * the original protocol via X-Forwarded-Proto.
     *
     * @return bool
     */
    public function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }

    /**
     * Resolves a project-relative path to an absolute filesystem path.
     *
     * Assumes the Kernel lives at src/Core/Kernel.php (2 levels deep).
     *
     * Examples:
     *   basePath('.env')        → /var/www/project/.env
     *   basePath('routes/web.php') → /var/www/project/routes/web.php
     *
     * @param  string $relative Path relative to the project root.
     * @return string Absolute path.
     */
    private function basePath(string $relative): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . ltrim($relative, '/\\');
    }
}