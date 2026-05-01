<?php

/*
|--------------------------------------------------------------------------
| StorageDisk Class
|--------------------------------------------------------------------------
|
| Instance-based file operations for a specific disk root.
| Handles path sanitization and directory management.
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Storage;

class StorageDisk
{
    /**
     * StorageDisk constructor.
     * @param string $root Absolute root path of the disk.
     * @param string $diskName Friendly name of the disk.
     */
    public function __construct(
        protected string $root,
        protected string $diskName = 'public'
    ) {}

    /**
     * Writes content to a file. Supports strings and resources.
     * @param string $path
     * @param mixed $contents
     * @return bool
     */
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

    /**
     * Reads a file's contents.
     * @param string $path
     * @return string|false
     */
    public function get(string $path): string|false
    {
        $full = $this->fullPath($path);
        return file_exists($full) ? file_get_contents($full) : false;
    }

    /**
     * Verifies if a file exists in the disk.
     * @param string $path
     * @return bool
     */
    public function exists(string $path): bool
    {
        return file_exists($this->fullPath($path));
    }

    /**
     * Inverse of exists().
     * @param string $path
     * @return bool
     */
    public function missing(string $path): bool
    {
        return !$this->exists($path);
    }

    /**
     * Deletes a file.
     * @param string $path
     * @return bool
     */
    public function delete(string $path): bool
    {
        $full = $this->fullPath($path);
        return file_exists($full) && unlink($full);
    }

    /**
     * Returns the full physical path.
     * @param string $path
     * @return string
     */
    public function path(string $path): string
    {
        return $this->fullPath($path);
    }

    /**
     * Resolves the public URL if using the 'public' disk.
     * @param string $path
     * @throws \RuntimeException If disk is private.
     * @return string
     */
    public function url(string $path): string
    {
        if ($this->diskName === 'public') {
            $baseUrl = rtrim(env('APP_BASE_URL', ''), '/');
            $path    = ltrim(str_replace('\\', '/', $path), '/');
            return "{$baseUrl}/storage/{$path}";
        }

        throw new \RuntimeException("The disk '{$this->diskName}' is not publicly accessible.");
    }

    /**
     * Gets file size in bytes.
     * @param string $path
     * @return int
     */
    public function size(string $path): int
    {
        $full = $this->fullPath($path);
        return file_exists($full) ? (int) filesize($full) : 0;
    }

    /**
     * Gets the last modified timestamp.
     * @param string $path
     * @return int
     */
    public function lastModified(string $path): int
    {
        $full = $this->fullPath($path);
        return file_exists($full) ? (int) filemtime($full) : 0;
    }

    /**
     * Detects the MIME type of a file.
     * @param string $path
     * @return string
     */
    public function mimeType(string $path): string
    {
        $full  = $this->fullPath($path);
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $full);
        return $mime ?: 'application/octet-stream';
    }

    /**
     * Copies a file from one path to another within the disk.
     * @param string $from
     * @param string $to
     * @return bool
     */
    public function copy(string $from, string $to): bool
    {
        $fullFrom = $this->fullPath($from);
        $fullTo   = $this->fullPath($to);
        $this->ensureDir(dirname($fullTo));
        return copy($fullFrom, $fullTo);
    }

    /**
     * Renames or moves a file.
     * @param string $from
     * @param string $to
     * @return bool
     */
    public function move(string $from, string $to): bool
    {
        $fullFrom = $this->fullPath($from);
        $fullTo   = $this->fullPath($to);
        $this->ensureDir(dirname($fullTo));
        return rename($fullFrom, $fullTo);
    }

    /**
     * Creates a directory if it does not exist.
     * @param string $path
     * @return bool
     */
    public function makeDirectory(string $path): bool
    {
        $full = $this->fullPath($path);
        if (is_dir($full)) return true;
        return mkdir($full, 0755, true);
    }

    /**
     * Deletes a directory and all its contents recursively.
     * @param string $path
     * @return bool
     */
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

    /**
     * Returns an array of files in a directory (non-recursive).
     * @param string|null $directory
     * @return array
     */
    public function files(?string $directory = null): array
    {
        $dir   = $directory ? $this->fullPath($directory) : $this->root;
        $files = glob($dir . '/*') ?: [];
        return array_values(array_filter($files, 'is_file'));
    }

    /**
     * Returns an array of all files in a directory recursively.
     * @param string|null $directory
     * @return array
     */
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

    /**
     * Lists directories in a specific path.
     * @param string|null $directory
     * @return array
     */
    public function directories(?string $directory = null): array
    {
        $dir  = $directory ? $this->fullPath($directory) : $this->root;
        $dirs = glob($dir . '/*', GLOB_ONLYDIR) ?: [];
        return array_values($dirs);
    }

    /**
     * Normalizes and secures a file path against traversal.
     * @param string $path
     * @return string
     */
    protected function fullPath(string $path): string
    {
        return $this->root . DIRECTORY_SEPARATOR . ltrim(str_replace(['../', './', '..\\'], '', $path), '/\\');
    }

    /**
     * Internal helper to create directories on the fly.
     * @param string $dir
     */
    protected function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) mkdir($dir, 0755, true);
    }
}