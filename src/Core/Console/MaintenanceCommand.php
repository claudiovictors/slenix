<?php

declare(strict_types=1);

namespace Slenix\Core\Console;

class MaintenanceCommand extends Command
{
    /**
     * Puts the application into maintenance mode by writing a marker file.
     * Kernel::run() checks for this file and returns 503 before any
     * route dispatch happens.
     *
     * @param array $args CLI args — supports --message="..." and --retry=N.
     * @return void
     */
    public static function down(array $args = []): void
    {
        $path = self::downFilePath();

        if (file_exists($path)) {
            echo PHP_EOL;
            self::warning('Application is already in maintenance mode.');
            echo PHP_EOL;
            return;
        }

        $message = self::findFlag($args, '--message=')
            ?? 'We are performing scheduled maintenance. Please check back soon.';
        $retryAfter = self::findFlag($args, '--retry=');

        $payload = [
            'message'     => $message,
            'retry_after' => $retryAfter !== null ? (int) $retryAfter : null,
            'created_at'  => time(),
        ];

        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            echo PHP_EOL;
            self::error("Could not create directory: {$dir}");
            echo PHP_EOL;
            return;
        }

        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        echo PHP_EOL;
        self::success('Application is now in maintenance mode.');
        echo PHP_EOL;
    }

    /**
     * Removes the maintenance marker, bringing the application back online.
     *
     * @return void
     */
    public static function up(): void
    {
        $path = self::downFilePath();

        if (!file_exists($path)) {
            echo PHP_EOL;
            self::warning('Application is not in maintenance mode.');
            echo PHP_EOL;
            return;
        }

        unlink($path);

        echo PHP_EOL;
        self::success('Application is now live.');
        echo PHP_EOL;
    }

    /**
     * @return string
     */
    public static function downFilePath(): string
    {
        return self::basePath('storage/framework/down');
    }

    /**
     * @param array  $args
     * @param string $prefix
     * @return string|null
     */
    private static function findFlag(array $args, string $prefix): ?string
    {
        foreach ($args as $arg) {
            if (str_starts_with($arg, $prefix)) {
                return substr($arg, strlen($prefix));
            }
        }

        return null;
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