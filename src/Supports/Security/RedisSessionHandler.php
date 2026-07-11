<?php

/*
|--------------------------------------------------------------------------
| RedisSessionHandler Class — Slenix Framework
|--------------------------------------------------------------------------
|
| Implements PHP's native SessionHandlerInterface, backing session
| storage with Redis instead of the filesystem. Registered by
| Session::start() when SESSION_DRIVER=redis.
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Security;

use Slenix\Core\EnvLoader;
use Slenix\Supports\Redis\RedisConnection;

class RedisSessionHandler implements \SessionHandlerInterface
{
    /** @var string Key prefix to namespace session entries in Redis. */
    protected string $prefix;

    /** @var int Session TTL in seconds. */
    protected int $lifetime;

    public function __construct()
    {
        $this->prefix   = (string) EnvLoader::get('SESSION_PREFIX', 'session:');
        $this->lifetime = (int) EnvLoader::get('SESSION_LIFETIME', 7200);
    }

    /**
     * @param string $path
     * @param string $name
     * @return bool
     */
    public function open(string $path, string $name): bool
    {
        return true;
    }

    /**
     * @return bool
     */
    public function close(): bool
    {
        return true;
    }

    /**
     * @param string $id
     * @return string|false
     */
    public function read(string $id): string|false
    {
        $data = RedisConnection::get($this->key($id));
        return $data ?? '';
    }

    /**
     * @param string $id
     * @param string $data
     * @return bool
     */
    public function write(string $id, string $data): bool
    {
        return RedisConnection::setex($this->key($id), $this->lifetime, $data);
    }

    /**
     * @param string $id
     * @return bool
     */
    public function destroy(string $id): bool
    {
        RedisConnection::del($this->key($id));
        return true;
    }

    /**
     * Redis expires session keys natively via TTL — nothing to sweep.
     *
     * @param int $max_lifetime
     * @return int|false
     */
    public function gc(int $max_lifetime): int|false
    {
        return 0;
    }

    /**
     * Builds the namespaced Redis key for a session ID.
     *
     * @param string $id
     * @return string
     */
    protected function key(string $id): string
    {
        return $this->prefix . $id;
    }
}