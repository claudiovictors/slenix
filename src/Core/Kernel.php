<?php

/*
|--------------------------------------------------------------------------
| Kernel Class — Slenix Framework
|--------------------------------------------------------------------------
|
| The application core. It orchestrates the entire lifecycle: initializing 
| the environment, configuring security, starting sessions, and finally 
| dispatching the incoming request to the router.
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
    /** @var float Application start timestamp */
    private float $startTime;

    /** @var ErrorHandler Centralized error handling instance */
    private ErrorHandler $errorHandler;

    /**
     * Routes or patterns excluded from CSRF verification.
     * Supports wildcard *: '/api/*', '/webhook/stripe'
     * * @var array<string>
     */
    private array $csrfExcept = [
        '/api/*',
    ];

    /**
     * Kernel constructor.
     * * @param float $startTime Execution start time.
     */
    public function __construct(float $startTime)
    {
        $this->startTime    = $startTime;
        $this->errorHandler = new ErrorHandler();
    }

    // -------------------------------------------------------------------------
    // Entry Point
    // -------------------------------------------------------------------------

    /**
     * Boots the application and dispatches the request.
     * * @return void
     */
    public function run(): void
    {
        // Set low-level PHP error handlers
        set_error_handler([$this->errorHandler, 'handleError']);
        set_exception_handler([$this->errorHandler, 'handleException']);
        register_shutdown_function([$this, 'handleShutdown']);

        try {
            $this->loadEnvironment();
            $this->configurePHP();
            $this->sendSecurityHeaders();
            $this->startSession();
            $this->verifyCsrf();
            $this->bootServices();
            $this->dispatch();
        } catch (\Throwable $e) {
            // Log the exception before passing it to the visual handler
            try { 
                Log::exception($e); 
            } catch (\Throwable) {
                // Ignore logging failures to prevent infinite loops
            }
            $this->errorHandler->handleException($e);
        }
    }

    // -------------------------------------------------------------------------
    // Shutdown Handler
    // -------------------------------------------------------------------------

    /**
     * Captures fatal errors that cannot be caught by set_error_handler.
     * * @return void
     */
    public function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE], true)) {
            $this->errorHandler->handleException(
                new \ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line'])
            );
        }
    }

    // -------------------------------------------------------------------------
    // Environment
    // -------------------------------------------------------------------------

    /**
     * Loads the .env file into the system.
     * * @return void
     */
    private function loadEnvironment(): void
    {
        try {
            EnvLoader::load($this->basePath('.env'));
        } catch (\Exception $e) {
            $this->errorHandler->handleEnvError($e);
        }
    }

    // -------------------------------------------------------------------------
    // Service Booting
    // -------------------------------------------------------------------------

    /**
     * Configures static support services like Logging, Cache, and Storage.
     * * @return void
     */
    private function bootServices(): void
    {
        $storagePath = $this->basePath('storage');

        // Configure Logging
        Log::setPath($storagePath . '/logs');
        Log::setChannel($_ENV['LOG_CHANNEL'] ?? 'slenix');

        // Configure Cache
        Cache::setPath($storagePath . '/cache');
        Cache::setPrefix($_ENV['CACHE_PREFIX'] ?? 'slenix_');

        // Configure Storage Disks
        Storage::setDisk('public',  $storagePath . '/app/public');
        Storage::setDisk('local',   $storagePath . '/app/private');
        Storage::setDefaultDisk($_ENV['STORAGE_DISK'] ?? 'public');
    }

    // -------------------------------------------------------------------------
    // PHP Runtime Configuration
    // -------------------------------------------------------------------------

    /**
     * Overrides default PHP settings based on environment.
     * * @return void
     */
    private function configurePHP(): void
    {
        $debug = $this->isDebug();

        ini_set('display_errors', $debug ? '1' : '0');
        error_reporting($debug ? E_ALL : E_ALL & ~E_DEPRECATED & ~E_STRICT);
        ini_set('expose_php', '0');

        date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'UTC');

        // Secure Session Settings
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.use_strict_mode', '1');

        if ($this->isHttps()) {
            ini_set('session.cookie_secure', '1');
        }
    }

    // -------------------------------------------------------------------------
    // Security Headers
    // -------------------------------------------------------------------------

    /**
     * Sends essential security headers to the browser.
     * * @return void
     */
    private function sendSecurityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        $app = $_ENV['APP_NAME'] ?? 'Slenix';

        header("X-Frame-Options: SAMEORIGIN");
        header("X-Content-Type-Options: nosniff");
        header("X-XSS-Protection: 1; mode=block");
        header("Referrer-Policy: strict-origin-when-cross-origin");
        header("X-Powered-By: {$app}");

        if (!$this->isDebug() && $this->isHttps()) {
            header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
        }

        // Handle Force HTTPS redirection
        if (($_ENV['FORCE_HTTPS'] ?? 'false') === 'true' && !$this->isHttps()) {
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $uri  = $_SERVER['REQUEST_URI'] ?? '/';
            header("Location: https://{$host}{$uri}", true, 301);
            exit;
        }
    }

    // -------------------------------------------------------------------------
    // Session Management
    // -------------------------------------------------------------------------

    /**
     * Starts the session and registers the helper alias.
     * * @return void
     */
    private function startSession(): void
    {
        Session::start();
        
        // Expose Session class globally for easier access
        if (!class_exists('Session')) {
            class_alias(Session::class, 'Session');
        }
    }

    // -------------------------------------------------------------------------
    // CSRF Protection
    // -------------------------------------------------------------------------

    /**
     * Verifies CSRF tokens for state-changing requests (POST, PUT, DELETE, etc).
     * * @return void
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
    // Request Dispatching
    // -------------------------------------------------------------------------

    /**
     * Loads routes and dispatches the request to the Router.
     * * @return void
     */
    private function dispatch(): void
    {
        $routes = $this->basePath('routes/web.php');

        if (!is_file($routes)) {
            throw new \RuntimeException("Main route file not found at: {$routes}");
        }

        require_once $routes;
        Router::dispatch();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Checks if the application is in debug mode.
     * * @return bool
     */
    private function isDebug(): bool
    {
        return ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
    }

    /**
     * Checks if the current request is served over HTTPS.
     * * @return bool
     */
    private function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }

    /**
     * Resolves a relative path to the absolute base directory of the project.
     * * @param string $relative
     * @return string
     */
    private function basePath(string $relative): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . ltrim($relative, '/\\');
    }
}