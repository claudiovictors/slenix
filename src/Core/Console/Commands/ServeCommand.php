<?php

/*
|--------------------------------------------------------------------------
| ServeCommand Class
|--------------------------------------------------------------------------
|
| Starts the Slenix HTTP development server using PHP's built-in server.
| When the --ws flag is provided it also starts the WebSocket server
| in a child process.
|
|
| 2026-05-23 07:17:00 GET /users ............................................~ 2ms
| 2026-05-23 07:17:01 POST /api/login .......................................~ 12ms
| 2026-05-23 07:17:02 GET /favicon.ico .....................................~ 0.4ms
|
*/

declare(strict_types=1);

namespace Slenix\Core\Console\Commands;

use Slenix\Http\Routing\Router;
use Slenix\Core\Console\Command;
use Slenix\Core\Foundation\EnvLoader;
use Slenix\Core\WebSocket\WebSocketServer;

class ServeCommand extends Command
{
    /** @var array CLI arguments received from the Celestial entry point. */
    private array $args;

    private const DEFAULT_PORT = 3000;
    private const DEFAULT_WS_PORT = 3001;

    /**
     * @param array $args Raw argv array forwarded from the Celestial CLI.
     */
    public function __construct(array $args)
    {
        $this->args = $args;
    }

    /**
     * Parses flags, renders the header and starts the development server.
     *
     * @return void
     */
    public function execute(): void
    {
        $c = self::console();

        $withWs = in_array('--ws', $this->args, true);
        $host   = $this->resolveHost();
        $port   = $this->resolvePort('--port=', self::DEFAULT_PORT);
        $wsPort = $this->resolvePort('--ws-port=', self::DEFAULT_WS_PORT);

        if ($port < 1 || $port > 65535) {
            self::error('Invalid HTTP port (1-65535).');
            exit(1);
        }

        $publicDir = PUBLIC_PATH;

        // ── Header ────────────────────────────────────────────────────────────
        self::newLine();

        $c->separator();
        echo ' ' . $c->white('Slenix') . $c->muted(' Development Server') . PHP_EOL;
        $c->separator();

        self::newLine();

        if (!is_dir($publicDir)) {
            mkdir($publicDir, 0755, true);
        }

        echo '  ' . $c->badge('INFO', 'primary')
            . ' ' . $c->muted('Server running on ')
            . $c->colorize("http://{$host}:{$port}", 'success', true)
            . PHP_EOL;

        if ($withWs) {
            echo '  ' . $c->badge('INFO', 'primary')
                . ' ' . $c->muted('WebSocket active on ')
                . $c->colorize("ws://{$host}:{$wsPort}", 'purple', true)
                . PHP_EOL;
        }

        if ($this->isHostedEnv()) {
            echo '  ' . $c->badge('WARN', 'warning')
                . ' ' . $c->muted("PHP's built-in server is single-threaded — fine for light traffic,")
                . PHP_EOL;
            echo '       ' . $c->muted('consider nginx+php-fpm behind it if you outgrow it.')
                . PHP_EOL;
        }

        self::newLine();

        echo '  ' . $c->muted('Press ')
            . $c->colorize('Ctrl+C', 'warning')
            . $c->muted(' to stop the server.')
            . PHP_EOL;

        self::newLine();

        // ── Router ────────────────────────────────────────────────────────────
        if ($withWs) {
            $this->startWithWebSocket($host, $port, $wsPort, $publicDir);
            return;
        }

        $this->startHttp($host, $port, $publicDir);
    }

    /**
     * Resolve the bind address: --host= flag, then APP_HOST/HOST env,
     * then a platform-aware default.
     *
     * @return string
     */
    private function resolveHost(): string
    {
        foreach ($this->args as $arg) {
            if (str_starts_with($arg, '--host=')) {
                return substr($arg, 7);
            }
        }

        $configured = EnvLoader::get('APP_HOST') ?? EnvLoader::get('HOST');

        if ($configured !== null && $configured !== '') {
            return (string) $configured;
        }

        // A PORT env var is the de-facto signal used by Render, Railway,
        // Heroku and Fly.io to tell the app which port to bind — its mere
        // presence means we're not on a developer's laptop, so default to
        // every interface rather than loopback-only.
        return $this->isHostedEnv() ? '0.0.0.0' : '127.0.0.1';
    }

    /**
     * Resolve a port: a matching --flag=, then a bare numeric arg (only
     * checked for --port=, to keep --ws-port= from grabbing an unrelated
     * numeric argument), then the PORT env var (HTTP port only), then the
     * given default.
     *
     * @param string $flag    e.g. "--port=" or "--ws-port="
     * @param int    $default
     * @return int
     */
    private function resolvePort(string $flag, int $default): int
    {
        foreach ($this->args as $arg) {
            if (str_starts_with($arg, $flag)) {
                return (int) substr($arg, strlen($flag));
            }
        }

        if ($flag === '--port=') {
            foreach ($this->args as $arg) {
                if (is_numeric($arg) && (int) $arg > 0) {
                    return (int) $arg;
                }
            }

            $fromEnv = EnvLoader::get('PORT');
            if ($fromEnv !== null) {
                return (int) $fromEnv;
            }
        }

        return $default;
    }

    /**
     * Whether this looks like a hosted/container environment rather than
     * a developer's own machine. Used to pick a sane default bind address
     * and to show the single-threaded-server warning.
     *
     * @return bool
     */
    private function isHostedEnv(): bool
    {
        return EnvLoader::get('PORT') !== null;
    }

    /**
     * Starts the PHP built-in server with a custom router script that logs
     * each request in the Laravel style before dispatching to the public dir.
     *
     * Format:
     *   2026-05-23 07:17:00 GET /path ..............................~ 2ms
     *
     * @param string $host      Bind address.
     * @param int    $port      HTTP port.
     * @param string $publicDir Document root.
     *
     * @return void
     */
    private function startHttp(string $host, int $port, string $publicDir): void
    {
        $router = $this->writeRouterScript($publicDir);

        // -t sets the document root so PHP finds static files (CSS, SVG, JS…)
        // relative to public/. The router intercepts every request first; when
        // it returns false the server serves the static file directly from -t.
        passthru(
            PHP_BINARY
            . ' -S ' . escapeshellarg("{$host}:{$port}")
            . ' -t ' . escapeshellarg($publicDir)
            . ' ' . escapeshellarg($router)
        );
    }

    /**
     * Writes a temporary router PHP script that logs requests then serves
     * static files or falls back to index.php.
     *
     * The public directory path is interpolated directly into the script source
     * so the router never relies on __DIR__ (which would point to /tmp).
     *
     * The file is placed in the system temp directory and auto-deleted on
     * server shutdown via register_shutdown_function.
     *
     * @param string $publicDir Absolute path to the document root.
     *
     * @return string Absolute path to the router script.
     */
    private function writeRouterScript(string $publicDir): string
    {
        // Escape once for safe embedding inside single-quoted PHP strings.
        $safeDir = str_replace("'", "\\'", rtrim($publicDir, '/'));

        // The script is built with a regular double-quoted heredoc so that
        // $safeDir is interpolated at generation time — no post-processing.
        $script = <<<PHP
<?php
// Slenix development server router — auto-generated, do not edit.
// Public directory: {$safeDir}

\$_SLENIX_PUBLIC = '{$safeDir}';

\$start  = microtime(true);
\$uri    = \$_SERVER['REQUEST_URI'] ?? '/';
\$method = \$_SERVER['REQUEST_METHOD'] ?? 'GET';
\$path   = parse_url(\$uri, PHP_URL_PATH);
\$file   = \$_SLENIX_PUBLIC . \$path;

// ── Static file passthrough ───────────────────────────────────────────────────
// Return false so PHP's built-in server handles the file itself (correct MIME,
// range requests, ETags). Only skip when the path is exactly '/' or the file
// genuinely does not exist (application routes).
if (\$path !== '/' && file_exists(\$file) && !is_dir(\$file)) {
    return false;
}

// ── Boot the application ──────────────────────────────────────────────────────
\$index = \$_SLENIX_PUBLIC . '/index.php';
if (file_exists(\$index)) {
    require \$index;
}

// ── Request log ───────────────────────────────────────────────────────────────
\$elapsed = round((microtime(true) - \$start) * 1000, 2);
\$date    = date('Y-m-d H:i:s');
\$status  = http_response_code() ?: 200;

\$statusColor = match (true) {
    \$status >= 500 => "\033[1;38;2;255;85;85m",
    \$status >= 400 => "\033[1;38;2;255;184;108m",
    \$status >= 300 => "\033[1;38;2;80;170;255m",
    default         => "\033[1;38;2;80;250;123m",
};

\$methodColor = match (\$method) {
    'POST'          => "\033[38;2;255;184;108m",
    'PUT', 'PATCH'  => "\033[38;2;189;147;249m",
    'DELETE'        => "\033[38;2;255;85;85m",
    default         => "\033[38;2;80;170;255m",
};

\$reset     = "\033[0m";
\$muted     = "\033[38;2;120;120;120m";
\$methodPad = str_pad(\$method, 7);
\$right     = "~ {\$elapsed}ms";
\$left      = "{\$date} {\$methodPad} {\$path}";
\$dots      = max(3, 72 - strlen(\$left) - strlen(\$right));

\$line = \$muted . \$date . ' '
      . \$reset . \$methodColor . \$methodPad . \$reset
      . ' ' . \$muted . \$path . ' ' . str_repeat('.', \$dots) . ' '
      . \$statusColor . \$right . \$reset
      . PHP_EOL;

file_put_contents('php://stderr', \$line);
PHP;

        $tmp = sys_get_temp_dir() . '/slenix_router_' . getmypid() . '.php';

        file_put_contents($tmp, $script);

        // Clean up on exit so /tmp does not accumulate stale scripts.
        register_shutdown_function(static function () use ($tmp): void {
            if (file_exists($tmp)) {
                @unlink($tmp);
            }
        });

        return $tmp;
    }

    // =========================================================================
    // WebSocket
    // =========================================================================

    /**
     * Starts the WebSocket server in a child process and the HTTP server in
     * the foreground.
     *
     * @param string $host
     * @param int    $httpPort
     * @param int    $wsPort
     * @param string $publicDir
     *
     * @return void
     */
    private function startWithWebSocket(
        string $host,
        int $httpPort,
        int $wsPort,
        string $publicDir
    ): void {
        if (function_exists('pcntl_fork')) {
            $this->forkWebSocket($host, $wsPort);
        } else {
            self::warning(
                'pcntl extension not available (common in slim Docker images, '
                . 'e.g. php:*-cli-alpine without docker-php-ext-install pcntl) — '
                . 'running the WebSocket server as a subprocess instead.'
            );
            $this->spawnWebSocket($host, $wsPort);
        }

        $this->startHttp($host, $httpPort, $publicDir);
    }

    // ── Strategy 1 — pcntl_fork (Unix/Linux/macOS) ───────────────────────────

    /**
     * Forks the current process and runs the WebSocket server in the child.
     *
     * @param string $host
     * @param int    $wsPort
     *
     * @return void
     */
    private function forkWebSocket(string $host, int $wsPort): void
    {
        $projectRoot = dirname(__DIR__, 3);
        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->spawnWebSocket($host, $wsPort);
            return;
        }

        if ($pid === 0) {
            if (function_exists('posix_setsid')) {
                posix_setsid();
            }

            $routesFile = $projectRoot . '/routes/web.php';
            if (file_exists($routesFile)) {
                require_once $routesFile;
            }

            $server = new WebSocketServer($host, $wsPort);

            foreach (Router::getWebSocketRoutes() as $path => $handlerClass) {
                $server->addHandler($path, new $handlerClass());
            }

            $server->start();
            exit(0);
        }

        register_shutdown_function(static function () use ($pid): void {
            if (function_exists('posix_kill')) {
                posix_kill($pid, SIGTERM);
            }
        });
    }

    // ── Strategy 2 — proc_open (cross-platform fallback) ─────────────────────

    /**
     * Spawns the WebSocket server as a separate OS process.
     *
     * @param string $host
     * @param int    $wsPort
     *
     * @return void
     */
    private function spawnWebSocket(string $host, int $wsPort): void
    {
        $projectRoot = addslashes(dirname(__DIR__, 3));
        $phpBinary = PHP_BINARY;

        $inline = <<<PHP
require_once '{$projectRoot}/vendor/autoload.php';
try { (new Slenix\\Core\\EnvLoader)->load('{$projectRoot}/.env'); } catch (\\Throwable \$e) {}
\$routesFile = '{$projectRoot}/routes/web.php';
if (file_exists(\$routesFile)) { require_once \$routesFile; }
\$server = new Slenix\\Core\\WebSocket\\WebSocketServer('{$host}', {$wsPort});
foreach (Slenix\\Http\\Routing\\Router::getWebSocketRoutes() as \$path => \$cls) {
    \$server->addHandler(\$path, new \$cls());
}
\$server->start();
PHP;

        $escaped = escapeshellarg($inline);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', '/dev/null', 'a'],
            2 => ['file', '/dev/null', 'a'],
        ];

        $process = proc_open("{$phpBinary} -r {$escaped}", $descriptors, $pipes);

        if (!is_resource($process)) {
            return;
        }

        // Close the unused stdin pipe so the child never blocks waiting for input.
        fclose($pipes[0]);

        // IMPORTANT: never call proc_close() here — it blocks the parent until
        // the child process exits, and the WebSocket server runs forever by
        // design. Keep the process handle open and terminate it only when the
        // dev server itself shuts down (Ctrl+C / script end).
        register_shutdown_function(static function () use ($process): void {
            if (is_resource($process)) {
                proc_terminate($process);
            }
        });
    }
}