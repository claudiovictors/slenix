<?php

declare(strict_types=1);

namespace Slenix\Core\Console;

use Slenix\Core\EnvLoader;
use Slenix\Supports\Cache\Cache;

class CacheCommand extends Command
{
    /**
     * Flushes the application cache — works for both the "file" and
     * "redis" drivers, since Cache::flush() is driver-agnostic.
     *
     * @return void
     */
    public static function clear(): void
    {
        $storagePath = self::basePath('storage');

        Cache::setPath($storagePath . '/cache');
        Cache::setPrefix((string) EnvLoader::get('CACHE_PREFIX', 'slenix_'));

        $removed = Cache::flush();
        $driver  = strtolower((string) EnvLoader::get('CACHE_DRIVER', 'file'));

        echo PHP_EOL;
        self::success("Cache cleared ({$driver} driver) — {$removed} " . ($removed === 1 ? 'entry' : 'entries') . ' removed.');
        echo PHP_EOL;
    }

    /**
     * @param string $relative
     * @return string
     */
    private static function basePath(string $relative): string
    {
        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . ltrim($relative, '/\\');
    }
}