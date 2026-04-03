<?php

/*
|--------------------------------------------------------------------------
| Classe Storage
|--------------------------------------------------------------------------
|
| Abstração para sistema de ficheiros local do Slenix.
| Discos disponíveis: local (storage/app/private) e public (storage/app/public)
|
| Os ficheiros do disco 'public' são servidos via public/storage/ (symlink
| ou cópia). Os do disco 'local' nunca são acessíveis via browser.
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Storage;

class Storage
{
    protected static string $defaultDisk = 'public';
    protected static array $disks = [];

    // =========================================================
    // CONFIGURAÇÃO
    // =========================================================

    public static function setDisk(string $name, string $path): void
    {
        static::$disks[$name] = rtrim($path, '/\\');
    }

    public static function setDefaultDisk(string $disk): void
    {
        static::$defaultDisk = $disk;
    }

    /**
     * Muda de disco temporariamente.
     * @example Storage::disk('local')->put('file.txt', 'content')
     */
    public static function disk(string $disk): StorageDisk
    {
        return new StorageDisk(static::resolvePath($disk), $disk);
    }

    // =========================================================
    // API ESTÁTICA (usa o disco padrão)
    // =========================================================

    public static function put(string $path, mixed $contents): bool
    {
        return static::disk(static::$defaultDisk)->put($path, $contents);
    }

    public static function get(string $path): string|false
    {
        return static::disk(static::$defaultDisk)->get($path);
    }

    public static function exists(string $path): bool
    {
        return static::disk(static::$defaultDisk)->exists($path);
    }

    public static function delete(string $path): bool
    {
        return static::disk(static::$defaultDisk)->delete($path);
    }

    public static function url(string $path): string
    {
        return static::disk(static::$defaultDisk)->url($path);
    }

    public static function path(string $path): string
    {
        return static::disk(static::$defaultDisk)->path($path);
    }

    public static function files(?string $directory = null): array
    {
        return static::disk(static::$defaultDisk)->files($directory);
    }

    public static function makeDirectory(string $path): bool
    {
        return static::disk(static::$defaultDisk)->makeDirectory($path);
    }

    public static function size(string $path): int
    {
        return static::disk(static::$defaultDisk)->size($path);
    }

    public static function copy(string $from, string $to): bool
    {
        return static::disk(static::$defaultDisk)->copy($from, $to);
    }

    public static function move(string $from, string $to): bool
    {
        return static::disk(static::$defaultDisk)->move($from, $to);
    }

    // =========================================================
    // HELPER INTERNO
    // =========================================================

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

// =========================================================
// StorageDisk — instância com disco específico
// =========================================================

class StorageDisk
{
    public function __construct(
        protected string $root,
        protected string $diskName = 'public'
    ) {}

    public function put(string $path, mixed $contents): bool
    {
        $full = $this->fullPath($path);
        $this->ensureDir(dirname($full));

        if (is_resource($contents)) {
            $target = fopen($full, 'wb');
            if (!$target) return false;
            stream_copy_to_stream($contents, $target);
            fclose($target);
            return true;
        }

        return file_put_contents($full, $contents, LOCK_EX) !== false;
    }

    public function get(string $path): string|false
    {
        $full = $this->fullPath($path);
        return file_exists($full) ? file_get_contents($full) : false;
    }

    public function exists(string $path): bool
    {
        return file_exists($this->fullPath($path));
    }

    public function missing(string $path): bool
    {
        return !$this->exists($path);
    }

    public function delete(string $path): bool
    {
        $full = $this->fullPath($path);
        return file_exists($full) && unlink($full);
    }

    public function path(string $path): string
    {
        return $this->fullPath($path);
    }

    public function url(string $path): string
    {
        if ($this->diskName === 'public') {
            $baseUrl = rtrim(env('APP_BASE_URL', ''), '/');
            $path    = ltrim(str_replace('\\', '/', $path), '/');
            return "{$baseUrl}/storage/{$path}";
        }

        // Disco privado não tem URL pública
        throw new \RuntimeException("O disco '{$this->diskName}' não é acessível publicamente.");
    }

    public function size(string $path): int
    {
        $full = $this->fullPath($path);
        return file_exists($full) ? (int) filesize($full) : 0;
    }

    public function lastModified(string $path): int
    {
        $full = $this->fullPath($path);
        return file_exists($full) ? (int) filemtime($full) : 0;
    }

    public function mimeType(string $path): string
    {
        $full  = $this->fullPath($path);
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $full);
        finfo_close($finfo);
        return $mime ?: 'application/octet-stream';
    }

    public function copy(string $from, string $to): bool
    {
        $fullFrom = $this->fullPath($from);
        $fullTo   = $this->fullPath($to);
        $this->ensureDir(dirname($fullTo));
        return copy($fullFrom, $fullTo);
    }

    public function move(string $from, string $to): bool
    {
        $fullFrom = $this->fullPath($from);
        $fullTo   = $this->fullPath($to);
        $this->ensureDir(dirname($fullTo));
        return rename($fullFrom, $fullTo);
    }

    public function makeDirectory(string $path): bool
    {
        $full = $this->fullPath($path);
        if (is_dir($full)) return true;
        return mkdir($full, 0755, true);
    }

    public function deleteDirectory(string $path): bool
    {
        $full = $this->fullPath($path);
        if (!is_dir($full)) return false;
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($full, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        return rmdir($full);
    }

    public function files(?string $directory = null): array
    {
        $dir   = $directory ? $this->fullPath($directory) : $this->root;
        $files = glob($dir . '/*') ?: [];
        return array_values(array_filter($files, 'is_file'));
    }

    public function allFiles(?string $directory = null): array
    {
        $dir    = $directory ? $this->fullPath($directory) : $this->root;
        $result = [];
        $it     = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile()) $result[] = $file->getPathname();
        }
        return $result;
    }

    public function directories(?string $directory = null): array
    {
        $dir  = $directory ? $this->fullPath($directory) : $this->root;
        $dirs = glob($dir . '/*', GLOB_ONLYDIR) ?: [];
        return array_values($dirs);
    }

    // =========================================================
    // INTERNOS
    // =========================================================

    protected function fullPath(string $path): string
    {
        return $this->root . DIRECTORY_SEPARATOR . ltrim(str_replace(['../', './', '..\\'], '', $path), '/\\');
    }

    protected function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) mkdir($dir, 0755, true);
    }
}