<?php

/*
|--------------------------------------------------------------------------
| Classe Cache
|--------------------------------------------------------------------------
|
| Cache baseado em ficheiros JSON em storage/cache/.
| API: get, put, remember, forget, flush, has, forever
| TTL em segundos. forever() guarda sem expiração.
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Cache;

class Cache
{
    protected static string $path = '';
    protected static string $prefix = '';

    // =========================================================
    // CONFIGURAÇÃO
    // =========================================================

    public static function setPath(string $path): void
    {
        static::$path = $path;
    }

    public static function setPrefix(string $prefix): void
    {
        static::$prefix = $prefix;
    }

    // =========================================================
    // API PÚBLICA
    // =========================================================

    /**
     * Guarda um valor no cache.
     *
     * @param string $key   Chave única
     * @param mixed  $value Valor a guardar (qualquer tipo serializável)
     * @param int    $ttl   Tempo de vida em segundos (0 = forever)
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
     * Guarda um valor sem expiração.
     */
    public static function forever(string $key, mixed $value): void
    {
        static::put($key, $value, 0);
    }

    /**
     * Obtém um valor do cache ou null se não existir / expirado.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $file = static::filePath($key);
        if (!file_exists($file)) return $default;

        $raw = file_get_contents($file);
        if ($raw === false) return $default;

        $data = json_decode($raw, true);
        if (!is_array($data)) return $default;

        // Expirado
        if ($data['expires_at'] > 0 && time() > $data['expires_at']) {
            unlink($file);
            return $default;
        }

        return $data['value'];
    }

    /**
     * Obtém do cache ou executa o callback e guarda o resultado.
     *
     * @example Cache::remember('users.all', 3600, fn() => User::all()->toArray())
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
     * Verifica se a chave existe e não está expirada.
     */
    public static function has(string $key): bool
    {
        return static::get($key) !== null;
    }

    /**
     * Remove uma entrada do cache.
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
     * Remove todas as entradas do cache.
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
     * Remove entradas expiradas (limpeza periódica).
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
     * Incrementa um valor numérico no cache.
     */
    public static function increment(string $key, int $by = 1): int
    {
        $current = (int) static::get($key, 0);
        $new     = $current + $by;
        // Mantém o TTL original se existir
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
     * Decrementa um valor numérico no cache.
     */
    public static function decrement(string $key, int $by = 1): int
    {
        return static::increment($key, -$by);
    }

    // =========================================================
    // INTERNOS
    // =========================================================

    protected static function filePath(string $key): string
    {
        $hash = md5(static::$prefix . $key);
        return static::cacheDir() . '/' . $hash . '.json';
    }

    protected static function cacheDir(): string
    {
        if (!empty(static::$path)) return static::$path;
        return (defined('STORAGE_PATH') ? STORAGE_PATH : dirname(__DIR__, 4) . '/storage') . '/cache';
    }

    protected static function ensureDir(): void
    {
        $dir = static::cacheDir();
        if (!is_dir($dir)) mkdir($dir, 0755, true);
    }
}