<?php

/*
|--------------------------------------------------------------------------
| Log Class
|--------------------------------------------------------------------------
|
| Slenix logging system. Records daily files in storage/logs/.
| Levels: debug, info, warning, error, critical.
| Each line: [2026-04-01 12:00:00] LEVEL: message {context JSON}.
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Logging;

class Log
{
    const DEBUG    = 'DEBUG';
    const INFO     = 'INFO';
    const WARNING  = 'WARNING';
    const ERROR    = 'ERROR';
    const CRITICAL = 'CRITICAL';

    /** @var string Custom storage path for log files */
    protected static string $path = '';

    /** @var string Default channel name used as filename prefix */
    protected static string $channel = 'slenix';

    /**
     * Sets the directory path where logs will be stored.
     * @param string $path
     * @return void
     */
    public static function setPath(string $path): void
    {
        static::$path = $path;
    }

    /**
     * Sets the log channel name.
     * @param string $channel
     * @return void
     */
    public static function setChannel(string $channel): void
    {
        static::$channel = $channel;
    }

    /**
     * Log a debug level message.
     * @param string $message
     * @param array $context
     * @return void
     */
    public static function debug(string $message, array $context = []): void
    {
        static::write(static::DEBUG, $message, $context);
    }

    /**
     * Log an info level message.
     * @param string $message
     * @param array $context
     * @return void
     */
    public static function info(string $message, array $context = []): void
    {
        static::write(static::INFO, $message, $context);
    }

    /**
     * Log a warning level message.
     * @param string $message
     * @param array $context
     * @return void
     */
    public static function warning(string $message, array $context = []): void
    {
        static::write(static::WARNING, $message, $context);
    }

    /**
     * Log an error level message.
     * @param string $message
     * @param array $context
     * @return void
     */
    public static function error(string $message, array $context = []): void
    {
        static::write(static::ERROR, $message, $context);
    }

    /**
     * Log a critical level message.
     * @param string $message
     * @param array $context
     * @return void
     */
    public static function critical(string $message, array $context = []): void
    {
        static::write(static::CRITICAL, $message, $context);
    }

    /**
     * Logs an exception with its stack trace and file information.
     * @param \Throwable $e
     * @param string $level
     * @return void
     */
    public static function exception(\Throwable $e, string $level = self::ERROR): void
    {
        static::write($level, $e->getMessage(), [
            'class'   => get_class($e),
            'code'    => $e->getCode(),
            'file'    => $e->getFile(),
            'line'    => $e->getLine(),
            'trace'   => array_slice(
                array_map(fn($f) => ($f['file'] ?? '?') . ':' . ($f['line'] ?? '?'), $e->getTrace()),
                0, 8
            ),
        ]);
    }

    /**
     * Retrieves the last N lines from the log file.
     * @param int $lines
     * @param string|null $date
     * @return array
     */
    public static function tail(int $lines = 50, ?string $date = null): array
    {
        $file = static::filePath($date ?? date('Y-m-d'));
        if (!file_exists($file)) return [];

        $all = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        return array_slice($all, -$lines);
    }

    /**
     * Reads and parses daily logs into an associative array.
     * @param string|null $date
     * @return array
     */
    public static function read(?string $date = null): array
    {
        $file = static::filePath($date ?? date('Y-m-d'));
        if (!file_exists($file)) return [];

        $lines  = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $result = [];

        foreach ($lines as $line) {
            if (preg_match('/^\[(.+?)\] (\w+): (.+?)( \{.+\})?$/', $line, $m)) {
                $result[] = [
                    'datetime' => $m[1],
                    'level'    => $m[2],
                    'message'  => $m[3],
                    'context'  => isset($m[4]) ? json_decode(trim($m[4]), true) : [],
                ];
            }
        }

        return $result;
    }

    /**
     * Lists all log files available in the storage directory.
     * @return array
     */
    public static function files(): array
    {
        $dir   = static::logsDir();
        $files = glob($dir . '/*.log') ?: [];
        return array_map('basename', $files);
    }

    /**
     * Removes log files older than the specified number of days.
     * @param int $days
     * @return int Count of removed files
     */
    public static function prune(int $days = 30): int
    {
        $dir     = static::logsDir();
        $files   = glob($dir . '/*.log') ?: [];
        $cutoff  = time() - ($days * 86400);
        $removed = 0;

        foreach ($files as $file) {
            if (filemtime($file) < $cutoff) {
                unlink($file);
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Internal method to format and write the log entry to the filesystem.
     * @param string $level
     * @param string $message
     * @param array $context
     * @return void
     */
    protected static function write(string $level, string $message, array $context): void
    {
        $dir  = static::logsDir();
        $file = static::filePath(date('Y-m-d'));

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $timestamp = date('Y-m-d H:i:s');
        $ctx       = empty($context) ? '' : ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $line      = "[{$timestamp}] {$level}: {$message}{$ctx}" . PHP_EOL;

        file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Resolves the directory path for logs, defaulting to storage/logs.
     * @return string
     */
    protected static function logsDir(): string
    {
        if (!empty(static::$path)) return static::$path;
        return (defined('STORAGE_PATH') ? STORAGE_PATH : dirname(__DIR__, 4) . '/storage') . '/logs';
    }

    /**
     * Generates the absolute file path for a specific date.
     * @param string $date
     * @return string
     */
    protected static function filePath(string $date): string
    {
        return static::logsDir() . '/' . static::$channel . '-' . $date . '.log';
    }
}