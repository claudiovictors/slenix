<?php

/*
|--------------------------------------------------------------------------
| RedisConnection Class — Slenix Framework
|--------------------------------------------------------------------------
|
| Zero-dependency Redis client. Speaks the RESP (REdis Serialization
| Protocol) directly over a TCP socket — no ext-redis, no Composer
| packages. Provides low-level string/key operations; higher-level
| concerns (serialization, TTL policy, key namespacing) live in the
| classes that consume this one (Cache, RedisSessionHandler, etc).
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Redis;

use Slenix\Core\EnvLoader;

class RedisConnection
{
    /** @var resource|null Active socket connection. */
    protected static $socket = null;

    /**
     * Opens the connection to Redis if not already connected.
     * Handles AUTH and SELECT based on .env configuration.
     *
     * @throws \RuntimeException On connection or auth failure.
     * @return void
     */
    public static function connect(): void
    {
        if (self::$socket !== null && is_resource(self::$socket)) {
            return;
        }

        $host    = (string) EnvLoader::get('REDIS_HOST', '127.0.0.1');
        $port    = (int) EnvLoader::get('REDIS_PORT', 6379);
        $timeout = (float) EnvLoader::get('REDIS_TIMEOUT', 2.5);

        $errno  = 0;
        $errstr = '';

        $socket = @stream_socket_client(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT
        );

        if ($socket === false) {
            throw new \RuntimeException("Redis: unable to connect to {$host}:{$port} — {$errstr} ({$errno})");
        }

        stream_set_timeout($socket, (int) max(1, $timeout));
        self::$socket = $socket;

        $password = (string) EnvLoader::get('REDIS_PASSWORD', '');
        if ($password !== '') {
            self::command(['AUTH', $password]);
        }

        $database = (int) EnvLoader::get('REDIS_DATABASE', 0);
        if ($database > 0) {
            self::command(['SELECT', $database]);
        }
    }

    /**
     * Closes the socket connection, if open.
     *
     * @return void
     */
    public static function disconnect(): void
    {
        if (self::$socket !== null && is_resource(self::$socket)) {
            fclose(self::$socket);
        }
        self::$socket = null;
    }

    /**
     * Sends a raw command to Redis and returns the parsed reply.
     *
     * @param  array<int, scalar> $args e.g. ['SET', 'foo', 'bar']
     * @throws \RuntimeException On write failure or Redis-side error.
     * @return mixed
     */
    public static function command(array $args): mixed
    {
        self::connect();

        $payload = self::buildCommand($args);
        $written = @fwrite(self::$socket, $payload);

        if ($written === false) {
            self::disconnect();
            throw new \RuntimeException('Redis: failed to write to socket');
        }

        return self::readReply();
    }

    // -------------------------------------------------------------------------
    // High-level string/key operations
    // -------------------------------------------------------------------------

    /**
     * Retrieves a raw string value.
     *
     * @param  string $key
     * @return string|null Null if the key does not exist.
     */
    public static function get(string $key): ?string
    {
        $result = self::command(['GET', $key]);
        return $result === null ? null : (string) $result;
    }

    /**
     * Stores a raw string value with no expiration.
     *
     * @param  string $key
     * @param  string $value
     * @return bool
     */
    public static function set(string $key, string $value): bool
    {
        return self::command(['SET', $key, $value]) === true;
    }

    /**
     * Stores a raw string value with an expiration in seconds.
     *
     * @param  string $key
     * @param  int    $ttl
     * @param  string $value
     * @return bool
     */
    public static function setex(string $key, int $ttl, string $value): bool
    {
        if ($ttl <= 0) {
            return self::set($key, $value);
        }
        return self::command(['SETEX', $key, $ttl, $value]) === true;
    }

    /**
     * Deletes one or more keys.
     *
     * @param  string ...$keys
     * @return int Number of keys removed.
     */
    public static function del(string ...$keys): int
    {
        if (empty($keys)) return 0;
        return (int) self::command(['DEL', ...$keys]);
    }

    /**
     * Checks whether a key exists.
     *
     * @param  string $key
     * @return bool
     */
    public static function exists(string $key): bool
    {
        return (int) self::command(['EXISTS', $key]) > 0;
    }

    /**
     * Sets/refreshes the TTL on an existing key.
     *
     * @param  string $key
     * @param  int    $ttl
     * @return bool
     */
    public static function expire(string $key, int $ttl): bool
    {
        return (int) self::command(['EXPIRE', $key, $ttl]) === 1;
    }

    /**
     * Returns the remaining TTL of a key, in seconds.
     *
     * @param  string $key
     * @return int -1 if no TTL set, -2 if key does not exist.
     */
    public static function ttl(string $key): int
    {
        return (int) self::command(['TTL', $key]);
    }

    /**
     * Atomically increments an integer value stored at key.
     *
     * @param  string $key
     * @param  int    $by
     * @return int New value.
     */
    public static function incrBy(string $key, int $by = 1): int
    {
        return (int) self::command(['INCRBY', $key, $by]);
    }

    /**
     * Atomically decrements an integer value stored at key.
     *
     * @param  string $key
     * @param  int    $by
     * @return int New value.
     */
    public static function decrBy(string $key, int $by = 1): int
    {
        return (int) self::command(['DECRBY', $key, $by]);
    }

    /**
     * Finds keys matching a glob-style pattern using non-blocking SCAN.
     * Safe on large keyspaces (unlike KEYS, which blocks the server).
     *
     * @param  string $pattern e.g. "slenix_cache:*"
     * @param  int    $count   Hint for keys scanned per iteration.
     * @return string[]
     */
    public static function keys(string $pattern, int $count = 500): array
    {
        $cursor = '0';
        $found  = [];

        do {
            $reply = self::command(['SCAN', $cursor, 'MATCH', $pattern, 'COUNT', $count]);
            $cursor = (string) $reply[0];
            foreach ((array) $reply[1] as $key) {
                $found[] = (string) $key;
            }
        } while ($cursor !== '0');

        return $found;
    }

    /**
     * Deletes all keys matching a pattern.
     *
     * @param  string $pattern
     * @return int Number of keys removed.
     */
    public static function flushByPattern(string $pattern): int
    {
        $keys = self::keys($pattern);
        if (empty($keys)) return 0;
        return self::del(...$keys);
    }

    /**
     * Pings the server to check connectivity.
     *
     * @return bool
     */
    public static function ping(): bool
    {
        try {
            return self::command(['PING']) === 'PONG';
        } catch (\Throwable) {
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // RESP protocol internals
    // -------------------------------------------------------------------------

    /**
     * Builds a RESP-encoded command array.
     *
     * @param  array<int, scalar> $args
     * @return string
     */
    protected static function buildCommand(array $args): string
    {
        $cmd = '*' . count($args) . "\r\n";

        foreach ($args as $arg) {
            $arg = (string) $arg;
            $cmd .= '$' . strlen($arg) . "\r\n" . $arg . "\r\n";
        }

        return $cmd;
    }

    /**
     * Reads and parses a single RESP reply, recursing for arrays.
     *
     * @throws \RuntimeException On protocol error or connection loss.
     * @return mixed
     */
    protected static function readReply(): mixed
    {
        $line = self::readLine();

        if ($line === false || $line === '') {
            self::disconnect();
            throw new \RuntimeException('Redis: connection closed unexpectedly');
        }

        $type    = $line[0];
        $payload = substr($line, 1);

        return match ($type) {
            '+' => $payload === 'OK' ? true : $payload,          // Simple String
            '-' => throw new \RuntimeException('Redis error: ' . $payload), // Error
            ':' => (int) $payload,                                // Integer
            '$' => self::readBulkString((int) $payload),          // Bulk String
            '*' => self::readArray((int) $payload),               // Array
            default => throw new \RuntimeException("Redis: unknown reply type '{$type}'"),
        };
    }

    /**
     * Reads a bulk string payload of the given length.
     *
     * @param  int $length
     * @return string|null Null represents a Redis nil bulk string ($-1).
     */
    protected static function readBulkString(int $length): ?string
    {
        if ($length === -1) return null;

        $data = self::readBytes($length + 2); // +2 for trailing \r\n
        return substr($data, 0, $length);
    }

    /**
     * Reads an array reply of the given count, recursing per element.
     *
     * @param  int $count
     * @return array<int, mixed>|null Null represents a Redis nil array (*-1).
     */
    protected static function readArray(int $count): ?array
    {
        if ($count === -1) return null;

        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $items[] = self::readReply();
        }

        return $items;
    }

    /**
     * Reads a single line terminated by \r\n, with the terminator stripped.
     *
     * @return string|false
     */
    protected static function readLine(): string|false
    {
        $line = fgets(self::$socket);
        if ($line === false) return false;
        return rtrim($line, "\r\n");
    }

    /**
     * Reads an exact number of bytes from the socket, looping until complete.
     *
     * @param  int $length
     * @return string
     */
    protected static function readBytes(int $length): string
    {
        $data      = '';
        $remaining = $length;

        while ($remaining > 0) {
            $chunk = fread(self::$socket, $remaining);
            if ($chunk === false || $chunk === '') break;
            $data      .= $chunk;
            $remaining -= strlen($chunk);
        }

        return $data;
    }
}