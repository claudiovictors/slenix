<?php

/*
|--------------------------------------------------------------------------
| WebSocketServer Class — Slenix Framework
|--------------------------------------------------------------------------
|
| Pure-PHP WebSocket server built on PHP's socket extension.
| Uses a non-blocking select() loop to handle multiple concurrent clients 
| efficiently without external dependencies.
|
*/

declare(strict_types=1);

namespace Slenix\Core\WebSocket;

class WebSocketServer
{
    /** @var resource Master server socket */
    private $socket;

    /** @var string Bind host (e.g., 127.0.0.1) */
    private string $host;

    /** @var int Bind port (e.g., 8081) */
    private int $port;

    /** @var Connection[] Storage for active connections: [id => Connection] */
    private array $connections = [];

    /** @var WebSocketHandler[] Route-to-handler mapping: [path => handler instance] */
    private array $handlers = [];

    /** @var bool Server status flag */
    private bool $running = false;

    /**
     * WebSocketServer constructor.
     * * @param string $host Bind address.
     * @param int    $port Bind port.
     */
    public function __construct(string $host = '127.0.0.1', int $port = 8081)
    {
        $this->host = $host;
        $this->port = $port;
    }

    /**
     * Registers a WebSocketHandler for a specific URI path.
     * * @param string           $path    The URI path (e.g., '/chat').
     * @param WebSocketHandler $handler The handler instance.
     * @return void
     */
    public function addHandler(string $path, WebSocketHandler $handler): void
    {
        $handler->setServer($this);
        $this->handlers[$path] = $handler;
    }

    /**
     * Initializes the socket and starts the main event loop.
     * * @throws \RuntimeException If the socket cannot be created, bound, or listened to.
     * @return void
     */
    public function start(): void
    {
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        if ($this->socket === false) {
            throw new \RuntimeException('Failed to create socket: ' . socket_strerror(socket_last_error()));
        }

        socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1);
        socket_set_nonblock($this->socket);

        if (!socket_bind($this->socket, $this->host, $this->port)) {
            throw new \RuntimeException(
                "Failed to bind {$this->host}:{$this->port} — " . socket_strerror(socket_last_error($this->socket))
            );
        }

        if (!socket_listen($this->socket, 128)) {
            throw new \RuntimeException('Failed to listen: ' . socket_strerror(socket_last_error($this->socket)));
        }

        $this->running = true;
        $this->loop();
    }

    /**
     * Gracefully terminates the server and all active connections.
     * * @return void
     */
    public function stop(): void
    {
        $this->running = false;

        foreach ($this->connections as $conn) {
            $conn->close();
        }

        @socket_close($this->socket);
    }

    /**
     * The main non-blocking event loop using socket_select.
     * * @return void
     */
    private function loop(): void
    {
        while ($this->running) {
            $read   = [$this->socket];
            $write  = null;
            $except = null;

            foreach ($this->connections as $conn) {
                $read[] = $conn->getSocket();
            }

            // 200ms timeout to keep the loop responsive to the $running flag
            $changed = @socket_select($read, $write, $except, 0, 200000);

            if ($changed === false || $changed === 0) {
                continue;
            }

            // Handle new incoming connections
            if (in_array($this->socket, $read, true)) {
                $clientSocket = @socket_accept($this->socket);
                if ($clientSocket !== false) {
                    $this->acceptConnection($clientSocket);
                }
                unset($read[array_search($this->socket, $read, true)]);
            }

            // Handle activity on existing connections
            foreach ($read as $clientSocket) {
                $conn = $this->findConnectionBySocket($clientSocket);
                if ($conn === null) continue;

                $data = @socket_read($clientSocket, 65536, PHP_BINARY_READ);

                if ($data === false || $data === '') {
                    $this->handleClose($conn);
                    continue;
                }

                if (!$conn->isHandshaked()) {
                    $this->handleHandshake($conn, $data);
                } else {
                    $this->handleMessage($conn, $data);
                }
            }
        }
    }

    /**
     * Prepares a new client socket for non-blocking I/O.
     * * @param resource $socket
     * @return void
     */
    private function acceptConnection($socket): void
    {
        socket_set_nonblock($socket);
        $conn = new Connection($socket);
        $this->connections[$conn->getId()] = $conn;
    }

    /**
     * Maps a raw socket resource to its Connection wrapper.
     * * @param resource $socket
     * @return Connection|null
     */
    private function findConnectionBySocket($socket): ?Connection
    {
        foreach ($this->connections as $conn) {
            if ($conn->getSocket() === $socket) {
                return $conn;
            }
        }
        return null;
    }

    /**
     * Processes the HTTP Upgrade request and routes to the appropriate handler.
     * * @param Connection $conn
     * @param string     $data
     * @return void
     */
    private function handleHandshake(Connection $conn, string $data): void
    {
        $path = '/';
        if (preg_match('/GET\s+([^\s]+)\s+HTTP/i', $data, $m)) {
            $path = parse_url($m[1], PHP_URL_PATH) ?? '/';
        }

        if (!$conn->performHandshake($data)) {
            $this->handleClose($conn);
            return;
        }

        $conn->setAttribute('_path', $path);

        $handler = $this->resolveHandler($path);
        if ($handler !== null) {
            $handler->addConnection($conn);
            try {
                $handler->onOpen($conn);
            } catch (\Throwable $e) {
                $handler->onError($conn, $e);
            }
        }
    }

    /**
     * Decodes incoming frames and triggers the onMessage event.
     * * @param Connection $conn
     * @param string     $data
     * @return void
     */
    private function handleMessage(Connection $conn, string $data): void
    {
        $payload = $conn->decodeFrame($data);

        if ($payload === null) {
            return;
        }

        $path    = $conn->getAttribute('_path', '/');
        $handler = $this->resolveHandler($path);

        if ($handler === null) {
            return;
        }

        // Auto-decode JSON payload if applicable
        $decoded = json_decode($payload, true);
        $message = (json_last_error() === JSON_ERROR_NONE) ? $decoded : $payload;

        try {
            $handler->onMessage($conn, $message);
        } catch (\Throwable $e) {
            $handler->onError($conn, $e);
        }
    }

    /**
     * Handles client disconnection and cleanup.
     * * @param Connection $conn
     * @return void
     */
    private function handleClose(Connection $conn): void
    {
        $path    = $conn->getAttribute('_path', '/');
        $handler = $this->resolveHandler($path);

        if ($handler !== null) {
            try {
                $handler->onClose($conn);
            } catch (\Throwable) {}
            $handler->removeConnection($conn);
        }

        $conn->close();
        unset($this->connections[$conn->getId()]);
    }

    /**
     * Resolves a handler based on the requested path.
     * * @param string $path
     * @return WebSocketHandler|null
     */
    private function resolveHandler(string $path): ?WebSocketHandler
    {
        return $this->handlers[$path] ?? null;
    }

    public function getHost(): string { return $this->host; }
    public function getPort(): int    { return $this->port; }

    /** @return Connection[] */
    public function getConnections(): array { return $this->connections; }
}