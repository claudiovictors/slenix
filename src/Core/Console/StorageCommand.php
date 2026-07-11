<?php

declare(strict_types=1);

namespace Slenix\Core\Console;

class StorageCommand extends Command
{
    /**
     * Creates a symlink from public/storage to storage/app/public.
     *
     * @return void
     */
    public static function link(): void
    {
        $target = self::basePath('storage/app/public');
        $link   = self::basePath('public/storage');

        if (!is_dir($target) && !mkdir($target, 0755, true)) {
            echo PHP_EOL;
            self::error("Could not create target directory: {$target}");
            echo PHP_EOL;
            return;
        }

        if (file_exists($link) || is_link($link)) {
            echo PHP_EOL;
            self::warning('The [public/storage] link already exists.');
            echo PHP_EOL;
            return;
        }

        $success = @symlink($target, $link);

        echo PHP_EOL;

        if ($success) {
            self::success('The [public/storage] link has been created.');
        } else {
            self::error('Could not create the symlink.');
            self::info('On Windows, try running the terminal as Administrator, or use: mklink /D "public\storage" "storage\app\public"');
        }

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