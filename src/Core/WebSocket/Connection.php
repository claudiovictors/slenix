<?php

/*
|--------------------------------------------------------------------------
| Connection Class — Slenix Framework
|--------------------------------------------------------------------------
|
| Represents a single WebSocket client connection.
| Wraps the raw socket resource and provides helpers for
| reading, writing, and storing per-connection attributes.
|
*/

declare(strict_types=1);

namespace Slenix\Core\WebSocket;

class Connection
{
    /** @var resource Raw socket resource */
    private $socket;

    /** @var string Unique connection identifier */
    private string $id;

    /** @var array<string, mixed> Per-connection attribute bag */
    private array $attributes = [];

    /** @var bool Whether the WebSocket handshake has been completed */
    private bool $handshaked = false;

    /** @var string Pending read buffer */
    private string $buffer = '';

    /**
     * Connection constructor.
     * * @param resource $socket
     */
    public function __construct($socket)
    {
        $this->socket = $socket;
        $this->id     = bin2hex(random_bytes(8));
    }

    // -------------------------------------------------------------------------
    // Identity
    // -------------------------------------------------------------------------

    /**
     * Returns the unique connection ID.
     * * @return string
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Returns the underlying socket resource.
     *
     * @return resource
     */
    public function getSocket()
    {
        return $this->socket;
    }

    // -------------------------------------------------------------------------
    // Handshake
    // -------------------------------------------------------------------------

    /**
     * Checks if the connection has completed the WebSocket handshake.
     * * @return bool
     */
    public function isHandshaked(): bool
    {
        return $this->handshaked;
    }

    /**
     * Marks the connection as successfully handshaked.
     * * @return void
     */
    public function markHandshaked(): void
    {
        $this->handshaked = true;
    }

    // -------------------------------------------------------------------------
    // Sending data
    // -------------------------------------------------------------------------

    /**
     * Sends a text frame to this client.
     *
     * @param string|array $data String or array (auto JSON-encoded).
     * @return void
     */
    public function send(string|array $data): void
    {
        $payload = is_array($data) ? json_encode($data) : $data;
        $frame   = $this->encodeFrame($payload);

        @socket_write($this->socket, $frame, strlen($frame));
    }

    // -------------------------------------------------------------------------
    // Closing
    // -------------------------------------------------------------------------

    /**
     * Sends a close frame and shuts down the socket.
     * * @return void
     */
    public function close(): void
    {
        $closeFrame = $this->encodeFrame('', 0x08); // opcode 8 = close
        @socket_write($this->socket, $closeFrame, strlen($closeFrame));
        @socket_close($this->socket);
    }

    // -------------------------------------------------------------------------
    // Attribute bag
    // -------------------------------------------------------------------------

    /**
     * Stores a value in the connection attribute bag.
     *
     * @param string $key
     * @param mixed  $value
     * @return void
     */
    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    /**
     * Retrieves a value from the attribute bag.
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * Checks whether an attribute exists.
     * * @param string $key
     * @return bool
     */
    public function hasAttribute(string $key): bool
    {
        return array_key_exists($key, $this->attributes);
    }

    // -------------------------------------------------------------------------
    // Frame encoding / decoding
    // -------------------------------------------------------------------------

    /**
     * Encodes a payload into a WebSocket frame according to RFC 6455.
     *
     * @param string $payload The data to be sent.
     * @param int    $opcode  0x01 = text, 0x08 = close.
     * @return string
     */
    public function encodeFrame(string $payload, int $opcode = 0x01): string
    {
        $length = strlen($payload);
        $frame  = chr(0x80 | $opcode); // FIN bit set + opcode

        if ($length <= 125) {
            $frame .= chr($length);
        } elseif ($length <= 65535) {
            $frame .= chr(126) . pack('n', $length);
        } else {
            $frame .= chr(127) . pack('J', $length);
        }

        return $frame . $payload;
    }

    /**
     * Decodes an incoming WebSocket frame.
     *
     * Returns the decoded text payload, or null if the frame is incomplete
     * or is a control frame (ping/pong/close).
     *
     * @param string $data Raw bytes read from the socket.
     * @return string|null
     */
    public function decodeFrame(string $data): ?string
    {
        if (strlen($data) < 2) {
            return null;
        }

        $firstByte  = ord($data[0]);
        $secondByte = ord($data[1]);

        $opcode = $firstByte & 0x0F;
        $masked = ($secondByte & 0x80) !== 0;
        $length = $secondByte & 0x7F;

        $offset = 2;

        if ($length === 126) {
            if (strlen($data) < $offset + 2) return null;
            $length = unpack('n', substr($data, $offset, 2))[1];
            $offset += 2;
        } elseif ($length === 127) {
            if (strlen($data) < $offset + 8) return null;
            $length = unpack('J', substr($data, $offset, 8))[1];
            $offset += 8;
        }

        $mask = '';
        if ($masked) {
            if (strlen($data) < $offset + 4) return null;
            $mask    = substr($data, $offset, 4);
            $offset += 4;
        }

        if (strlen($data) < $offset + $length) {
            return null;
        }

        $payload = substr($data, $offset, $length);

        if ($masked) {
            $decoded = '';
            for ($i = 0; $i < $length; $i++) {
                $decoded .= $payload[$i] ^ $mask[$i % 4];
            }
            $payload = $decoded;
        }

        // Ignore control frames silently for now
        if ($opcode === 0x08) return null; // close
        if ($opcode === 0x09) return null; // ping
        if ($opcode === 0x0A) return null; // pong

        return $payload;
    }

    /**
     * Performs the HTTP→WebSocket upgrade handshake.
     *
     * @param string $request Raw HTTP request headers.
     * @return bool True if the handshake response was sent successfully.
     */
    public function performHandshake(string $request): bool
    {
        if (!preg_match('/Sec-WebSocket-Key:\s*(.+)\r\n/i', $request, $matches)) {
            return false;
        }

        $key      = trim($matches[1]);
        $accept   = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));

        $response = implode("\r\n", [
            'HTTP/1.1 101 Switching Protocols',
            'Upgrade: websocket',
            'Connection: Upgrade',
            "Sec-WebSocket-Accept: {$accept}",
            '', '',
        ]);

        $written = @socket_write($this->socket, $response, strlen($response));

        if ($written !== false) {
            $this->markHandshaked();
            return true;
        }

        return false;
    }
}