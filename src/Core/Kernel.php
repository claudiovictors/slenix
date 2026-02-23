<?php

/*
 |--------------------------------------------------------------------------
 | Classe Kernel
 |--------------------------------------------------------------------------
 |
 | Núcleo da aplicação: inicializa o ambiente, configura segurança
 | e despacha a requisição.
 |
 */

declare(strict_types=1);

namespace Slenix\Core;

use Slenix\Core\EnvLoader;
use Slenix\Http\Routing\Router;
use Slenix\Supports\Security\Session;
use Slenix\Supports\Security\CSRF;
use Slenix\Core\Exceptions\ErrorHandler;

class Kernel
{
    private float $startTime;
    private ErrorHandler $errorHandler;

    /**
     * Rotas/padrões excluídos da verificação CSRF.
     * Suporta wildcard *: '/api/*', '/webhook/stripe'
     */
    private array $csrfExcept = [
        '/api/*',
    ];

    public function __construct(float $startTime)
    {
        $this->startTime = $startTime;
        $this->errorHandler = new ErrorHandler();
    }

    // -------------------------------------------------------------------------
    // Ponto de entrada
    // -------------------------------------------------------------------------

    public function run(): void
    {
        set_error_handler([$this->errorHandler, 'handleError']);
        set_exception_handler([$this->errorHandler, 'handleException']);
        register_shutdown_function([$this, 'handleShutdown']);

        try {
            $this->loadEnvironment();
            $this->configurePHP();
            $this->sendSecurityHeaders();
            $this->startSession();
            $this->verifyCsrf();
            $this->dispatch();
        } catch (\Throwable $e) {
            $this->errorHandler->handleException($e);
        }
    }

    // -------------------------------------------------------------------------
    // Shutdown
    // -------------------------------------------------------------------------

    public function handleShutdown(): void
    {
        $error = error_get_last();

        if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE], true)) {
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
    // Ambiente
    // -------------------------------------------------------------------------

    private function loadEnvironment(): void
    {
        try {
            EnvLoader::load($this->basePath('.env'));
        } catch (\Exception $e) {
            $this->errorHandler->handleEnvError($e);
        }
    }

    // -------------------------------------------------------------------------
    // Configuração PHP
    // -------------------------------------------------------------------------

    private function configurePHP(): void
    {
        $debug = $this->isDebug();

        ini_set('display_errors', $debug ? '1' : '0');
        error_reporting($debug ? E_ALL : E_ALL & ~E_DEPRECATED & ~E_STRICT);
        ini_set('expose_php', '0');

        date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'UTC');

        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.use_strict_mode', '1');

        if ($this->isHttps()) {
            ini_set('session.cookie_secure', '1');
        }
    }

    // -------------------------------------------------------------------------
    // Headers HTTP de segurança
    // -------------------------------------------------------------------------

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

        if (($_ENV['FORCE_HTTPS'] ?? 'false') === 'true' && !$this->isHttps()) {
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $uri = $_SERVER['REQUEST_URI'] ?? '/';
            header("Location: https://{$host}{$uri}", true, 301);
            exit;
        }
    }

    // -------------------------------------------------------------------------
    // Sessão
    // -------------------------------------------------------------------------

    private function startSession(): void
    {
        Session::start();
        class_alias(Session::class, 'Session');
    }

    // -------------------------------------------------------------------------
    // CSRF — verificação automática em POST/PUT/PATCH/DELETE
    // -------------------------------------------------------------------------

    private function verifyCsrf(): void
    {
        // Métodos seguros não precisam de verificação
        if (CSRF::isSafeMethod()) {
            return;
        }

        // Registra exclusões (webhooks, APIs externas, etc.)
        CSRF::except($this->csrfExcept);

        // Pula rotas excluídas
        if (CSRF::isExcluded()) {
            return;
        }

        // Garante token na sessão e verifica
        CSRF::token();
        CSRF::verifyOrFail();
    }

    // -------------------------------------------------------------------------
    // Despacho de rotas
    // -------------------------------------------------------------------------

    private function dispatch(): void
    {
        $routes = $this->basePath('routes/web.php');

        if (!is_file($routes)) {
            throw new \RuntimeException("Arquivo de rotas não encontrado: {$routes}");
        }

        require_once $routes;
        Router::dispatch();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function isDebug(): bool
    {
        return ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
    }

    private function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }

    private function basePath(string $relative): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . ltrim($relative, '/\\');
    }
}