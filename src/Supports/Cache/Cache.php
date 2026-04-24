<?php

/*
|--------------------------------------------------------------------------
| Cache Class — Slenix Framework
|--------------------------------------------------------------------------
|
| File-based cache system using JSON storage. This class provides a 
| simple API to store, retrieve, and manage temporary data in the 
| storage/cache/ directory with TTL (Time To Live) support.
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Cache;

class Cache
{
    /** @var string Custom storage path for cache files. */
    protected static string $path = '';

    /** @var string Prefix to avoid key collisions. */
    protected static string $prefix = '';

    // =========================================================
    // CONFIGURATION
    // =========================================================

    /**
     * Sets the base path for cache storage.
     * * @param string $path
     * @return void
     */
    public static function setPath(string $path): void
    {
        static::$path = $path;
    }

    /**
     * Sets the global cache prefix.
     * * @param string $prefix
     * @return void
     */
    public static function setPrefix(string $prefix): void
    {
        static::$prefix = $prefix;
    }

    // =========================================================
    // PUBLIC API
    // =========================================================

    /**
     * Stores a value in the cache.
     * * @param string $key   Unique identifier.
     * @param mixed  $value Data to store (any serializable type).
     * @param int    $ttl   Time To Live in seconds (0 = forever).
     * @return void
     */
    public static function put(string $key, mixed $value, int $ttl = 3600): void
    {
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
     * * @param string $key
     * @param mixed  $value
     * @return void
     */
    public static function forever(string $key, mixed $value): void
    {
        static::put($key, $value, 0);
    }

    /**
     * Retrieves an item from the cache.
     * * @param string $key
     * @param mixed  $default Default value if key is not found or expired.
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $file = static::filePath($key);
        if (!file_exists($file)) return $default;

        $raw = file_get_contents($file);
        if ($raw === false) return $default;

        $data = json_decode($raw, true);
        if (!is_array($data)) return $default;

        // Check if the item has expired
        if ($data['expires_at'] > 0 && time() > $data['expires_at']) {
            unlink($file);
            return $default;
        }

        return $data['value'];
    }

    /**
     * Retrieves an item from cache, or executes callback and stores the result.
     * * @param string   $key
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
     * * @param string $key
     * @return bool
     */
    public static function has(string $key): bool
    {
        return static::get($key) !== null;
    }

    /**
     * Removes an item from the cache.
     * * @param string $key
     * @return bool
     */
    public static function forget(string $key): bool
    {
        $file = static::filePath($key);
        if (file_exists($file)) {
            return unlink($file);
        }
        return false;
    }

    /**
     * Deletes all items from the cache storage.
     * * @return int Number of files removed.
     */
    public static function flush(): int
    {
        $dir     = static::cacheDir();
        $files   = glob($dir . '/*.json') ?: [];
        $removed = 0;

        foreach ($files as $file) {
            if (unlink($file)) $removed++;
        }

        return $removed;
    }

    /**
     * Removes all expired entries from the cache directory.
     * * @return int Number of purged files.
     */
    public static function purgeExpired(): int
    {
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
     * * @param string $key
     * @param int    $by
     * @return int The new value.
     */
    public static function increment(string $key, int $by = 1): int
    {
        $current = (int) static::get($key, 0);
        $new     = $current + $by;
        
        // Attempt to preserve original TTL
        $file = static::filePath($key);
        $ttl  = 3600;
        if (file_exists($file)) {
            $data = json_decode(file_get_contents($file), true);
            if (isset($data['expires_at']) && $data['expires_at'] > 0) {
                $ttl = max(1, $data['expires_at'] - time());
            }
        }
        
        static::put($key, $new, $ttl);
        return $new;
    }

    /**
     * Decrements the value of an item in the cache.
     * * @param string $key
     * @param int    $by
     * @return int The new value.
     */
    public static function decrement(string $key, int $by = 1): int
    {
        return static::increment($key, -$by);
    }

    // =========================================================
    // INTERNAL HELPERS
    // =========================================================

    /**
     * Resolves the full path to a cache file based on the key.
     * * @param string $key
     * @return string
     */
    protected static function filePath(string $key): string
    {
        $hash = md5(static::$prefix . $key);
        return static::cacheDir() . '/' . $hash . '.json';
    }

    /**
     * Resolves the directory where cache files are stored.
     * * @return string
     */
    protected static function cacheDir(): string
    {
        if (!empty(static::$path)) return static::$path;
        return (defined('STORAGE_PATH') ? STORAGE_PATH : dirname(__DIR__, 4) . '/storage') . '/cache';
    }

    /**
     * Ensures that the cache directory exists.
     * * @return void
     */
    protected static function ensureDir(): void
    {
        $dir = static::cacheDir();
        if (!is_dir($dir)) mkdir($dir, 0755, true);
    }
}