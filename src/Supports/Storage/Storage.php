<?php

/*
|--------------------------------------------------------------------------
| Storage Class
|--------------------------------------------------------------------------
|
| Slenix local filesystem abstraction.
| Available disks: local (storage/app/private) and public (storage/app/public).
|
| 'public' disk files are served via public/storage/ (symlink or copy).
| 'local' disk files are never accessible via browser.
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Storage;

class Storage
{
    /** @var string The default disk name to be used */
    protected static string $defaultDisk = 'public';

    /** @var array Registered custom disk paths */
    protected static array $disks = [];

    /**
     * Registers a custom disk path.
     * @param string $name
     * @param string $path
     * @return void
     */
    public static function setDisk(string $name, string $path): void
    {
        static::$disks[$name] = rtrim($path, '/\\');
    }

    /**
     * Sets the default disk name.
     * @param string $disk
     * @return void
     */
    public static function setDefaultDisk(string $disk): void
    {
        static::$defaultDisk = $disk;
    }

    /**
     * Returns an instance of a specific storage disk.
     * @param string $disk
     * @return StorageDisk
     */
    public static function disk(string $disk): StorageDisk
    {
        return new StorageDisk(static::resolvePath($disk), $disk);
    }

    /**
     * Stores content into a file on the default disk.
     * @param string $path
     * @param mixed $contents
     * @return bool
     */
    public static function put(string $path, mixed $contents): bool
    {
        return static::disk(static::$defaultDisk)->put($path, $contents);
    }

    /**
     * Retrieves the contents of a file.
     * @param string $path
     * @return string|false
     */
    public static function get(string $path): string|false
    {
        return static::disk(static::$defaultDisk)->get($path);
    }

    /**
     * Checks if a file exists.
     * @param string $path
     * @return bool
     */
    public static function exists(string $path): bool
    {
        return static::disk(static::$defaultDisk)->exists($path);
    }

    /**
     * Deletes a file from the default disk.
     * @param string $path
     * @return bool
     */
    public static function delete(string $path): bool
    {
        return static::disk(static::$defaultDisk)->delete($path);
    }

    /**
     * Generates a public URL for the given path (Public disk only).
     * @param string $path
     * @return string
     */
    public static function url(string $path): string
    {
        return static::disk(static::$defaultDisk)->url($path);
    }

    /**
     * Returns the absolute path of a file.
     * @param string $path
     * @return string
     */
    public static function path(string $path): string
    {
        return static::disk(static::$defaultDisk)->path($path);
    }

    /**
     * Lists files in a directory on the default disk.
     * @param string|null $directory
     * @return array
     */
    public static function files(?string $directory = null): array
    {
        return static::disk(static::$defaultDisk)->files($directory);
    }

    /**
     * Creates a directory recursively.
     * @param string $path
     * @return bool
     */
    public static function makeDirectory(string $path): bool
    {
        return static::disk(static::$defaultDisk)->makeDirectory($path);
    }

    /**
     * Returns the size of a file in bytes.
     * @param string $path
     * @return int
     */
    public static function size(string $path): int
    {
        return static::disk(static::$defaultDisk)->size($path);
    }

    /**
     * Copies a file to a new location.
     * @param string $from
     * @param string $to
     * @return bool
     */
    public static function copy(string $from, string $to): bool
    {
        return static::disk(static::$defaultDisk)->copy($from, $to);
    }

    /**
     * Moves a file to a new location.
     * @param string $from
     * @param string $to
     * @return bool
     */
    public static function move(string $from, string $to): bool
    {
        return static::disk(static::$defaultDisk)->move($from, $to);
    }

    /**
     * Internal helper to resolve the absolute root path of a given disk.
     * @param string $disk
     * @return string
     */
    public static function resolvePath(string $disk): string
    {
        if (isset(static::$disks[$disk])) {
            return static::$disks[$disk];
        }

        $base = defined('STORAGE_PATH') ? STORAGE_PATH : dirname(__DIR__, 4) . '/storage';

        return match ($disk) {
            'public'  => $base . '/app/public',
            'local'   => $base . '/app/private',
            'logs'    => $base . '/logs',
            'cache'   => $base . '/cache',
            default   => $base . '/app/' . $disk,
        };
    }
}