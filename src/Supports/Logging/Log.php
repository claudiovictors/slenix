<?php

/*
|--------------------------------------------------------------------------
| Classe Log
|--------------------------------------------------------------------------
|
| Sistema de logging do Slenix. Grava ficheiros diários em storage/logs/.
| Níveis: debug, info, warning, error, critical
| Cada linha: [2026-04-01 12:00:00] LEVEL: mensagem {contexto JSON}
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

    protected static string $path = '';
    protected static string $channel = 'slenix';

    // =========================================================
    // CONFIGURAÇÃO
    // =========================================================

    public static function setPath(string $path): void
    {
        static::$path = $path;
    }

    public static function setChannel(string $channel): void
    {
        static::$channel = $channel;
    }

    // =========================================================
    // API PÚBLICA
    // =========================================================

    public static function debug(string $message, array $context = []): void
    {
        static::write(static::DEBUG, $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        static::write(static::INFO, $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        static::write(static::WARNING, $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        static::write(static::ERROR, $message, $context);
    }

    public static function critical(string $message, array $context = []): void
    {
        static::write(static::CRITICAL, $message, $context);
    }

    /**
     * Loga uma exceção automaticamente com stack trace.
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

    // =========================================================
    // LEITURA
    // =========================================================

    /**
     * Retorna as últimas N linhas do log.
     */
    public static function tail(int $lines = 50, ?string $date = null): array
    {
        $file = static::filePath($date ?? date('Y-m-d'));
        if (!file_exists($file)) return [];

        $all = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        return array_slice($all, -$lines);
    }

    /**
     * Retorna todas as entradas de um dia como array parseado.
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
     * Lista todos os ficheiros de log disponíveis.
     */
    public static function files(): array
    {
        $dir   = static::logsDir();
        $files = glob($dir . '/*.log') ?: [];
        return array_map('basename', $files);
    }

    /**
     * Apaga logs mais antigos que N dias.
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

    // =========================================================
    // INTERNOS
    // =========================================================

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

    protected static function logsDir(): string
    {
        if (!empty(static::$path)) return static::$path;
        return (defined('STORAGE_PATH') ? STORAGE_PATH : dirname(__DIR__, 4) . '/storage') . '/logs';
    }

    protected static function filePath(string $date): string
    {
        return static::logsDir() . '/' . static::$channel . '-' . $date . '.log';
    }
}