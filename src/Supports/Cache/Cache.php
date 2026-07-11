<?php

/*
|--------------------------------------------------------------------------
| Cache Class — Slenix Framework
|--------------------------------------------------------------------------
|
| Cache system with pluggable drivers. Supports:
|   - "file"  → JSON files in storage/cache/ (default, zero setup)
|   - "redis" → key/value store over a raw RESP socket connection
|
| Driver is selected via the CACHE_DRIVER env var. Public API is
| identical regardless of driver.
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Cache;

use Slenix\Core\EnvLoader;
use Slenix\Supports\Redis\RedisConnection;

class Cache
{
    /** @var string Custom storage path for cache files. */
    protected static string $path = '';

    /** @var string Prefix to avoid key collisions. */
    protected static string $prefix = '';

    /**
     * Sets the base path for cache storage (file driver only).
     *
     * @param string $path
     * @return void
     */
    public static function setPath(string $path): void
    {
        static::$path = $path;
    }

    /**
     * Sets the global cache prefix.
     *
     * @param string $prefix
     * @return void
     */
    public static function setPrefix(string $prefix): void
    {
        static::$prefix = $prefix;
    }

    /**
     * Stores a value in the cache.
     *
     * @param string $key   Unique identifier.
     * @param mixed  $value Data to store (any serializable type).
     * @param int    $ttl   Time To Live in seconds (0 = forever).
     * @return void
     */
    public static function put(string $key, mixed $value, int $ttl = 3600): void
    {
        if (static::driver() === 'redis') {
            static::putRedis($key, $value, $ttl);
            return;
        }

        static::ensureDir();

        $data = [
            'value'      => $value,
            'expires_at' => $ttl > 0 ? time() + $ttl : 0,
        ];

        file_put_contents(
            static::filePath($key),
            json_encode($data, JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }

    /**
     * Stores a value in the cache indefinitely.
     *
     * @param string $key
     * @param mixed  $value
     * @return void
     */
    public static function forever(string $key, mixed $value): void
    {
        static::put($key, $value, 0);
    }

    /**
     * Retrieves an item from the cache.
     *
     * @param string $key
     * @param mixed  $default Default value if key is not found or expired.
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (static::driver() === 'redis') {
            return static::getRedis($key, $default);
        }

        $file = static::filePath($key);
        if (!file_exists($file)) return $default;

        $raw = file_get_contents($file);
        if ($raw === false) return $default;

        $data = json_decode($raw, true);
        if (!is_array($data)) return $default;

        if ($data['expires_at'] > 0 && time() > $data['expires_at']) {
            unlink($file);
            return $default;
        }

        return $data['value'];
    }

    /**
     * Retrieves an item from cache, or executes callback and stores the result.
     *
     * @param string   $key
     * @param int      $ttl
     * @param callable $callback
     * @return mixed
     */
    public static function remember(string $key, int $ttl, callable $callback): mixed
    {
        $cached = static::get($key);
        if ($cached !== null) return $cached;

        $value = $callback();
        static::put($key, $value, $ttl);
        return $value;
    }

    /**
     * Checks if a key exists and is not expired.
     *
     * @param string $key
     * @return bool
     */
    public static function has(string $key): bool
    {
        if (static::driver() === 'redis') {
            return RedisConnection::exists(static::redisKey($key));
        }

        return static::get($key) !== null;
    }

    /**
     * Removes an item from the cache.
     *
     * @param string $key
     * @return bool
     */
    public static function forget(string $key): bool
    {
        if (static::driver() === 'redis') {
            return RedisConnection::del(static::redisKey($key)) > 0;
        }

        $file = static::filePath($key);
        if (file_exists($file)) {
            return unlink($file);
        }
        return false;
    }

    /**
     * Deletes all items from the cache.
     *
     * @return int Number of entries removed.
     */
    public static function flush(): int
    {
        if (static::driver() === 'redis') {
            return RedisConnection::flushByPattern(static::redisKey('*'));
        }

        $dir     = static::cacheDir();
        $files   = glob($dir . '/*.json') ?: [];
        $removed = 0;

        foreach ($files as $file) {
            if (unlink($file)) $removed++;
        }

        return $removed;
    }

    /**
     * Removes all expired entries from the cache.
     *
     * Redis handles expiration natively via TTL — this is a no-op
     * for the redis driver and only matters for the file driver.
     *
     * @return int Number of purged files.
     */
    public static function purgeExpired(): int
    {
        if (static::driver() === 'redis') {
            return 0;
        }

        $dir     = static::cacheDir();
        $files   = glob($dir . '/*.json') ?: [];
        $removed = 0;
        $now     = time();

        foreach ($files as $file) {
            $raw  = file_get_contents($file);
            $data = $raw ? json_decode($raw, true) : null;

            if (is_array($data) && $data['expires_at'] > 0 && $now > $data['expires_at']) {
                unlink($file);
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Increments the value of an item in the cache.
     *
     * Note: not atomic (read-then-write) on either driver, to preserve
     * consistent behavior — arbitrary serialized values, not just ints,
     * can be stored under a key.
     *
     * @param string $key
     * @param int    $by
     * @return int The new value.
     */
    public static function increment(string $key, int $by = 1): int
    {
        $current = (int) static::get($key, 0);
        $new     = $current + $by;

        $ttl = static::remainingTtl($key);
        static::put($key, $new, $ttl);

        return $new;
    }

    /**
     * Decrements the value of an item in the cache.
     *
     * @param string $key
     * @param int    $by
     * @return int The new value.
     */
    public static function decrement(string $key, int $by = 1): int
    {
        return static::increment($key, -$by);
    }

    // -------------------------------------------------------------------------
    // Driver resolution
    // -------------------------------------------------------------------------

    /**
     * Resolves the active cache driver from the environment.
     *
     * @return string "file" or "redis"
     */
    protected static function driver(): string
    {
        return strtolower((string) EnvLoader::get('CACHE_DRIVER', 'file'));
    }

    // -------------------------------------------------------------------------
    // Redis driver
    // -------------------------------------------------------------------------

    /**
     * Stores a value under the redis driver.
     *
     * @param string $key
     * @param mixed  $value
     * @param int    $ttl
     * @return void
     */
    protected static function putRedis(string $key, mixed $value, int $ttl): void
    {
        $redisKey = static::redisKey($key);
        $payload  = serialize($value);

        if ($ttl > 0) {
            RedisConnection::setex($redisKey, $ttl, $payload);
        } else {
            RedisConnection::set($redisKey, $payload);
        }
    }

    /**
     * Retrieves a value under the redis driver.
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    protected static function getRedis(string $key, mixed $default): mixed
    {
        $raw = RedisConnection::get(static::redisKey($key));
        if ($raw === null) return $default;

        $value = @unserialize($raw);

        // unserialize() returns false both on failure and for a legitimately
        // stored `false` — disambiguate via a strict serialized-form check.
        if ($value === false && $raw !== serialize(false)) {
            return $default;
        }

        return $value;
    }

    /**
     * Returns the remaining TTL for a key, or the default TTL if unknown.
     * Used by increment()/decrement() to preserve expiration across writes.
     *
     * @param string $key
     * @return int
     */
    protected static function remainingTtl(string $key): int
    {
        if (static::driver() === 'redis') {
            $ttl = RedisConnection::ttl(static::redisKey($key));
            return $ttl > 0 ? $ttl : 3600;
        }

        $file = static::filePath($key);
        if (file_exists($file)) {
            $data = json_decode(file_get_contents($file), true);
            if (isset($data['expires_at']) && $data['expires_at'] > 0) {
                return max(1, $data['expires_at'] - time());
            }
        }

        return 3600;
    }

    /**
     * Builds the namespaced Redis key for a cache entry.
     *
     * @param string $key
     * @return string
     */
    protected static function redisKey(string $key): string
    {
        return 'cache:' . static::$prefix . $key;
    }

    // -------------------------------------------------------------------------
    // File driver
    // -------------------------------------------------------------------------

    /**
     * Resolves the full path to a cache file based on the key.
     *
     * @param string $key
     * @return string
     */
    protected static function filePath(string $key): string
    {
        $hash = md5(static::$prefix . $key);
        return static::cacheDir() . '/' . $hash . '.json';
    }

    /**
     * Resolves the directory where cache files are stored.
     *
     * @return string
     */
    protected static function cacheDir(): string
    {
        if (!empty(static::$path)) return static::$path;
        return (defined('STORAGE_PATH') ? STORAGE_PATH : dirname(__DIR__, 4) . '/storage') . '/cache';
    }

    /**
     * Ensures that the cache directory exists.
     *
     * @return void
     */
    protected static function ensureDir(): void
    {
        $dir = static::cacheDir();
        if (!is_dir($dir)) mkdir($dir, 0755, true);
    }
}