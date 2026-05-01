<?php

/*
|--------------------------------------------------------------------------
| WebSocketHandler Class — Slenix Framework
|--------------------------------------------------------------------------
|
| Abstract base class for WebSocket route handlers.
| Real-time logic should be implemented by extending this class and 
| overriding the provided lifecycle hooks.
|
*/

declare(strict_types=1);

namespace Slenix\Core\WebSocket;

abstract class WebSocketHandler
{
    /** @var Connection[] Active connections for this specific handler */
    protected array $connections = [];

    /** @var WebSocketServer|null Reference to the parent server instance */
    protected ?WebSocketServer $server = null;

    /**
     * Hook: Triggered when a new client connects successfully.
     * * @param Connection $conn
     */
    public function onOpen(Connection $conn): void {}

    /**
     * Hook: Triggered when a new text message is received.
     * * @param Connection $conn
     * @param mixed      $data Decoded JSON array or raw string.
     */
    public function onMessage(Connection $conn, mixed $data): void {}

    /**
     * Hook: Triggered when a client disconnects.
     * * @param Connection $conn
     */
    public function onClose(Connection $conn): void {}

    /**
     * Hook: Triggered when an error occurs within the handler context.
     * * @param Connection $conn
     * @param \Throwable $e
     */
    public function onError(Connection $conn, \Throwable $e): void {}

    /**
     * Sends a message to all connected clients in this handler.
     * * @param string|array    $data   The data to send.
     * @param Connection|null $except Optional connection to exclude.
     * @return void
     */
    public function broadcast(string|array $data, ?Connection $except = null): void
    {
        foreach ($this->connections as $conn) {
            if ($except && $conn->getId() === $except->getId()) {
                continue;
            }
            $conn->send($data);
        }
    }

    /**
     * Sends a message to a specific connection by its ID.
     * * @param string       $connectionId
     * @param string|array $data
     * @return void
     */
    public function sendTo(string $connectionId, string|array $data): void
    {
        foreach ($this->connections as $conn) {
            if ($conn->getId() === $connectionId) {
                $conn->send($data);
                return;
            }
        }
    }

    /**
     * Returns the list of active connections for this handler.
     * * @return Connection[]
     */
    public function getConnections(): array
    {
        return $this->connections;
    }

    /**
     * Returns the total count of active connections for this handler.
     * * @return int
     */
    public function connectionCount(): int
    {
        return count($this->connections);
    }

    /**
     * Sets the server instance for this handler.
     * * @internal
     * @param WebSocketServer $server
     * @return void
     */
    public function setServer(WebSocketServer $server): void
    {
        $this->server = $server;
    }

    /**
     * Adds a connection to the handler's pool.
     * * @internal
     * @param Connection $conn
     * @return void
     */
    public function addConnection(Connection $conn): void
    {
        $this->connections[$conn->getId()] = $conn;
    }

    /**
     * Removes a connection from the handler's pool.
     * * @internal
     * @param Connection $conn
     * @return void
     */
    public function removeConnection(Connection $conn): void
    {
        unset($this->connections[$conn->getId()]);
    }
}