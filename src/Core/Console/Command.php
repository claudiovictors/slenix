<?php

/*
|--------------------------------------------------------------------------
| Classe Command
|--------------------------------------------------------------------------
|
| Classe base da CLI do Slenix.
| Fornece métodos padronizados para saída no terminal,
| utilizando cores e símbolos para melhor legibilidade.
|
*/

declare(strict_types=1);

namespace Slenix\Core\Console;

abstract class Command
{
    protected static string $version = '2.4';

    protected static function console(): Console
    {
        return new Console();
    }

    protected static function line(string $symbol, string $message, string $color): void
    {
        $c = self::console();
        echo $c->colorize("[$symbol] ", $color, true)
            . $c->colorize($message, $color)
            . PHP_EOL;
    }

    public static function info(string $message): void
    {
        self::line('ℹ', $message, 'cyan');
    }

    public static function success(string $message): void
    {
        self::line('✔', $message, 'green');
    }

    public static function warning(string $message): void
    {
        self::line('!', $message, 'yellow');
    }

    public static function error(string $message): void
    {
        self::line('✗', $message, 'red');
    }

    public static function version(): void
    {
        self::info("Celestial CLI v" . self::$version);
    }

    /**
     * Exibe uma tabela formatada no terminal.
     *
     * @param array $headers Cabeçalhos da tabela
     * @param array $rows    Linhas da tabela (arrays indexados)
     */
    public static function table(array $headers, array $rows): void
    {
        $c = self::console();

        // Calcula largura de cada coluna
        $widths = array_map('strlen', $headers);
        foreach ($rows as $row) {
            foreach (array_values($row) as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, strlen((string) $cell));
            }
        }

        // Linha separadora
        $separator = '+' . implode('+', array_map(fn($w) => str_repeat('-', $w + 2), $widths)) . '+';

        echo $separator . PHP_EOL;

        // Cabeçalho
        $headerRow = '|';
        foreach ($headers as $i => $header) {
            $headerRow .= ' ' . $c->colorize(str_pad($header, $widths[$i]), 'cyan', true) . ' |';
        }
        echo $headerRow . PHP_EOL;
        echo $separator . PHP_EOL;

        // Linhas
        foreach ($rows as $row) {
            $line = '|';
            foreach (array_values($row) as $i => $cell) {
                $line .= ' ' . str_pad((string) $cell, $widths[$i]) . ' |';
            }
            echo $line . PHP_EOL;
        }

        echo $separator . PHP_EOL;
    }

    /**
     * Exibe uma linha em branco.
     */
    public static function newLine(int $count = 1): void
    {
        echo str_repeat(PHP_EOL, $count);
    }

    /**
     * Exibe ajuda da CLI com todos os comandos disponíveis.
     */
    public static function help(): void
    {
        $c = self::console();
        $version = self::$version;

        echo PHP_EOL;

        // Título simples
        echo $c->colorize("Celestial CLI {$version}", 'green') . PHP_EOL;

        echo PHP_EOL;

        // Usage
        echo $c->colorize("Usage:", 'yellow') . PHP_EOL;
        echo "  command [options] [arguments]" . PHP_EOL;

        echo PHP_EOL;

        $groups = [
            'Application' => [
                'key:generate' => 'Generate the application key',
                'serve' => 'Serve the application',
            ],
            'Generators' => [
                'make:controller' => 'Create a new controller class',
                'make:model' => 'Create a new model class',
                'make:middleware' => 'Create a new middleware class',
                'make:migration' => 'Create a new migration file',
                'make:seeder' => 'Create a new seeder class',
                'make:factory' => 'Create a new factory',
            ],
            'Database' => [
                'migrate' => 'Run the database migrations',
                'migrate:rollback' => 'Rollback the last migration',
                'migrate:reset' => 'Rollback all migrations',
                'migrate:fresh' => 'Drop all tables and re-run migrations',
                'migrate:status' => 'Show migration status',
                'db:seed' => 'Seed the database',
            ],
            'Other' => [
                'route:list' => 'List all registered routes',
                'view:clear' => 'Clear compiled views',
                'version' => 'Display CLI version',
                'help' => 'Display this help message',
            ],
        ];

        foreach ($groups as $group => $commands) {
            echo $c->colorize($group . ":", 'yellow') . PHP_EOL;

            foreach ($commands as $cmd => $desc) {
                $cmdFormatted = str_pad($cmd, 25);
                echo "  " . $c->colorize($cmdFormatted, 'green') . "  " . $desc . PHP_EOL;
            }

            echo PHP_EOL;
        }
    }
}