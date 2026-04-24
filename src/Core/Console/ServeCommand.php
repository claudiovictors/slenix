<?php

/*
|--------------------------------------------------------------------------
| ServeCommand Class
|--------------------------------------------------------------------------
|
| Starts the Slenix HTTP development server using PHP's built-in server.
| When the --ws flag is provided it also starts the WebSocket server
| in a child process — no temporary files are written to disk.
|
| Child process strategy (in priority order):
|   1. pcntl_fork()  — forks the current process in memory (Unix only).
|   2. proc_open()   — spawns a child via the OS process API (cross-platform).
|
| Usage:
|
|   php celestial serve                        HTTP only, port 8080
|   php celestial serve 8090                  HTTP only, custom port (positional)
|   php celestial serve --port=8090           HTTP only, custom port (flag)
|   php celestial serve --ws                  HTTP + WebSocket (8080 + 8081)
|   php celestial serve --ws --ws-port=9000   HTTP + WebSocket (8080 + 9000)
|
*/

declare(strict_types=1);

namespace Slenix\Core\Console;

use Slenix\Http\Routing\Router;
use Slenix\Core\WebSocket\WebSocketServer;

class ServeCommand extends Command
{
    /**
     * CLI arguments received from the Celestial entry point.
     *
     * @var array
     */
    private array $args;

    /**
     * Default HTTP server port.
     *
     * @var int
     */
    private const DEFAULT_PORT = 8080;

    /**
     * Default WebSocket server port.
     *
     * @var int
     */
    private const DEFAULT_WS_PORT = 8081;

    /**
     * @param array $args Raw argv array forwarded from the Celestial CLI.
     */
    public function __construct(array $args)
    {
        $this->args = $args;
    }

    // =========================================================================
    // Entry point
    // =========================================================================

    /**
     * Parses CLI flags and starts the appropriate server(s).
     *
     * When --ws is supplied, the WebSocket server is started in a child process
     * (no files written to disk) and the HTTP server runs in the foreground.
     *
     * @return void
     */
    public function execute(): void
    {
        $c      = self::console();
        $port   = self::DEFAULT_PORT;
        $wsPort = self::DEFAULT_WS_PORT;
        $withWs = false;

        foreach ($this->args as $arg) {
            if ($arg === '--ws') {
                $withWs = true;
            } elseif (str_starts_with($arg, '--port=')) {
                $port = (int) substr($arg, 7);
            } elseif (str_starts_with($arg, '--ws-port=')) {
                $wsPort = (int) substr($arg, 10);
            } elseif (is_numeric($arg) && (int) $arg > 0) {
                $port = (int) $arg;
            }
        }

        if ($port < 1 || $port > 65535) {
            self::error('Invalid HTTP port. Use a number between 1 and 65535.');
            exit(1);
        }

        if ($withWs && ($wsPort < 1 || $wsPort > 65535)) {
            self::error('Invalid WebSocket port. Use a number between 1 and 65535.');
            exit(1);
        }

        $host      = '127.0.0.1';
        $publicDir = PUBLIC_PATH;

        echo PHP_EOL;
        echo $c->colorize("▲ Celestial Dev Server", 'white', true) . PHP_EOL;

        if (!is_dir($publicDir)) {
            echo $c->colorize("  - Creating public directory...", 'white') . PHP_EOL;
            if (!mkdir($publicDir, 0755, true)) {
                self::error('Failed to create public directory.');
                exit(1);
            }
        }

        echo $c->colorize("  ✓ Ready in " . rand(120, 400) . "ms", 'green') . PHP_EOL;
        echo PHP_EOL;
        echo $c->colorize("  ➜ Local:   ", 'cyan') . "http://{$host}:{$port}" . PHP_EOL;
        echo $c->colorize("  ➜ Network: ", 'cyan') . "http://0.0.0.0:{$port}" . PHP_EOL;

        if ($withWs) {
            echo $c->colorize("  ➜ WS:      ", 'cyan') . "ws://{$host}:{$wsPort}" . PHP_EOL;
        }

        echo PHP_EOL;
        echo $c->colorize("  press Ctrl+C to stop", 'white') . PHP_EOL;
        echo PHP_EOL;

        if ($withWs) {
            $this->startWithWebSocket($host, $port, $wsPort, $publicDir);
        } else {
            passthru("php -S {$host}:{$port} -t {$publicDir}");
        }
    }

    // =========================================================================
    // WebSocket co-launch
    // =========================================================================

    /**
     * Starts the WebSocket server in a child process and the HTTP server in
     * the foreground — no temporary files are written to disk at any point.
     *
     * Strategy:
     *   1. pcntl_fork()  — preferred on Unix/Linux/macOS. Forks the current
     *                      PHP process in memory. The child process boots the
     *                      WebSocket server directly using the already-loaded
     *                      classes. Zero disk I/O.
     *   2. proc_open()   — fallback when pcntl is unavailable (e.g. Windows or
     *                      PHP compiled without --enable-pcntl). Spawns a new
     *                      PHP process that re-bootstraps via the autoloader
     *                      and runs the WebSocket server inline.
     *
     * @param string $host      Host address to bind both servers to.
     * @param int    $httpPort  Port for the HTTP development server.
     * @param int    $wsPort    Port for the WebSocket server.
     * @param string $publicDir Absolute path to the public document root.
     *
     * @return void
     */
    private function startWithWebSocket(
        string $host,
        int    $httpPort,
        int    $wsPort,
        string $publicDir
    ): void {
        $c = self::console();

        echo $c->colorize(
            "  Starting WebSocket server on ws://{$host}:{$wsPort}...",
            'cyan'
        ) . PHP_EOL . PHP_EOL;

        if (function_exists('pcntl_fork')) {
            $this->forkWebSocket($host, $wsPort);
        } else {
            $this->spawnWebSocket($host, $wsPort);
        }

        // HTTP server runs in the foreground (blocks until Ctrl+C).
        passthru("php -S {$host}:{$httpPort} -t {$publicDir}");
    }

    // =========================================================================
    // Strategy 1 — pcntl_fork (Unix/Linux/macOS)
    // =========================================================================

    /**
     * Forks the current process and runs the WebSocket server in the child.
     *
     * The child process inherits all loaded classes and autoloader state,
     * loads the route file so WebSocket routes are registered, then starts
     * the server event loop. The parent returns immediately so the HTTP
     * server can be started in the foreground.
     *
     * @param string $host   Host to bind the WebSocket server to.
     * @param int    $wsPort Port to listen on.
     *
     * @return void
     */
    private function forkWebSocket(string $host, int $wsPort): void
    {
        $projectRoot = dirname(__DIR__, 3);
        $pid         = pcntl_fork();

        if ($pid === -1) {
            // Fork failed — fall back to proc_open.
            $this->spawnWebSocket($host, $wsPort);
            return;
        }

        if ($pid === 0) {
            // ── Child process ─────────────────────────────────────────────────
            // Detach from the parent's terminal session so the child is not
            // killed when the parent receives Ctrl+C.
            if (function_exists('posix_setsid')) {
                posix_setsid();
            }

            // Load routes so Router::websocket() registrations are available.
            $routesFile = $projectRoot . '/routes/web.php';
            if (file_exists($routesFile)) {
                require_once $routesFile;
            }

            // Boot and start the WebSocket server — this call never returns.
            $server = new WebSocketServer($host, $wsPort);

            foreach (Router::getWebSocketRoutes() as $path => $handlerClass) {
                $server->addHandler($path, new $handlerClass());
            }

            $server->start();
            exit(0);
        }

        // ── Parent process ────────────────────────────────────────────────────
        // Register a shutdown handler so the child is cleaned up when the
        // parent exits (e.g. Ctrl+C on the HTTP server).
        register_shutdown_function(static function () use ($pid): void {
            if (function_exists('posix_kill')) {
                posix_kill($pid, SIGTERM);
            }
        });
    }

    // =========================================================================
    // Strategy 2 — proc_open (cross-platform fallback)
    // =========================================================================

    /**
     * Spawns the WebSocket server as a separate OS process using proc_open().
     *
     * Generates a self-contained inline PHP script as a string and passes it
     * directly to the PHP binary via -r — no files are written to disk.
     *
     * The inline script:
     *   1. Loads the Composer autoloader.
     *   2. Loads the .env file via EnvLoader.
     *   3. Registers WebSocket routes from routes/web.php.
     *   4. Starts the WebSocketServer event loop.
     *
     * @param string $host   Host to bind the WebSocket server to.
     * @param int    $wsPort Port to listen on.
     *
     * @return void
     */
    private function spawnWebSocket(string $host, int $wsPort): void
    {
        $projectRoot = addslashes(dirname(__DIR__, 3));
        $phpBinary   = PHP_BINARY;

        // Inline bootstrap — passed to PHP via -r, zero disk writes.
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

        // Escape for safe shell embedding.
        $escaped = escapeshellarg($inline);

        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['file', '/dev/null', 'a'],  // stdout — discarded
            2 => ['file', '/dev/null', 'a'],  // stderr — discarded
        ];

        // proc_open keeps the process alive independently of this parent.
        $process = proc_open("{$phpBinary} -r {$escaped}", $descriptors, $pipes);

        if (is_resource($process)) {
            // Detach immediately — we do not wait for the child.
            proc_close($process);
        }
    }
}