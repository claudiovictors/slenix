<?php

/*
|--------------------------------------------------------------------------
| Command Class
|--------------------------------------------------------------------------
|
| Base class for the Slenix CLI.
| Provides standardised terminal output methods using ANSI colours
| and symbols for improved readability.
|
*/

declare(strict_types=1);

namespace Slenix\Core\Console;

abstract class Command
{
    /**
     * @var string The current version of the CLI tool.
     */
    protected static string $version = '2.8';

    /**
     * Get an instance of the Console helper.
     * * @return Console An instance of the Console class.
     */
    protected static function console(): Console
    {
        return new Console();
    }

    /**
     * Prints a modern formatted CLI message line.
     *
     * Uses colored badge labels inspired by modern CLI tools
     * Bun and Composer.
     *
     * @param string $label   Badge label text.
     * @param string $message Message content.
     * @param string $color   Registered palette color.
     *
     * @return void
     */
    protected static function line(
        string $label,
        string $message,
        string $color
    ): void {
        $c = self::console();

        echo $c->badge(strtoupper($label), $color)
            . ' '
            . $c->white($message)
            . PHP_EOL;
    }

    /**
     * Outputs an informational CLI message.
     *
     * Intended for generic execution updates and progress logs.
     *
     * Example:
     *  INFO  Running migrations...
     *
     * @param string $message The information message.
     *
     * @return void
     */
    public static function info(string $message): void
    {
        self::line('INFO', $message, 'primary');
    }

    /**
     * Outputs a success CLI message.
     *
     * Intended for completed operations and successful tasks.
     *
     * Example:
     *  DONE  Migration executed successfully.
     *
     * @param string $message The success message.
     *
     * @return void
     */
    public static function success(string $message): void
    {
        self::line('DONE', $message, 'success');
    }

    /**
     * Outputs a warning CLI message.
     *
     * Intended for non-fatal alerts and user attention notices.
     *
     * Example:
     *  WARN  No migrations found.
     *
     * @param string $message The warning message.
     *
     * @return void
     */
    public static function warning(string $message): void
    {
        self::line('WARN', $message, 'warning');
    }

    /**
     * Outputs an error CLI message.
     *
     * Intended for fatal errors and failed operations.
     *
     * Example:
     *  FAIL  Database connection failed.
     *
     * @param string $message The error message.
     *
     * @return void
     */
    public static function error(string $message): void
    {
        self::line('ERROR', $message, 'error');
    }

    /**
     * Displays the current installed Slenix CLI version.
     *
     * @return void
     */
    public static function version(): void
    {
        $version = env('APP_VERSION') ?? self::$version;

        self::info("Slenix CLI v{$version}");
    }

    /**
     * Renders a modern Unicode table in the terminal.
     *
     * Uses UTF-8 box-drawing characters for improved readability
     * and professional CLI presentation.
     *
     * @param array<int, string>              $headers Table headers.
     * @param array<int, array<int, string>>  $rows    Table rows.
     *
     * @return void
     */
    public static function table(array $headers, array $rows): void
    {
        $c = self::console();

        $widths = array_map('strlen', $headers);

        foreach ($rows as $row) {
            foreach (array_values($row) as $i => $cell) {
                $widths[$i] = max(
                    $widths[$i] ?? 0,
                    strlen((string) $cell)
                );
            }
        }

        $top = '┌';

        foreach ($widths as $i => $width) {
            $top .= str_repeat('─', $width + 2);

            $top .= $i === array_key_last($widths)
                ? '┐'
                : '┬';
        }

        $middle = '├';

        foreach ($widths as $i => $width) {
            $middle .= str_repeat('─', $width + 2);

            $middle .= $i === array_key_last($widths)
                ? '┤'
                : '┼';
        }

        $bottom = '└';

        foreach ($widths as $i => $width) {
            $bottom .= str_repeat('─', $width + 2);

            $bottom .= $i === array_key_last($widths)
                ? '┘'
                : '┴';
        }

        echo $c->muted($top) . PHP_EOL;

        $headerLine = '│';

        foreach ($headers as $i => $header) {
            $headerLine .= ' '
                . $c->white(str_pad($header, $widths[$i]))
                . ' │';
        }

        echo $headerLine . PHP_EOL;

        echo $c->muted($middle) . PHP_EOL;

        foreach ($rows as $row) {
            $line = '│';

            foreach (array_values($row) as $i => $cell) {
                $line .= ' '
                    . str_pad((string) $cell, $widths[$i])
                    . ' │';
            }

            echo $line . PHP_EOL;
        }

        echo $c->muted($bottom) . PHP_EOL;
    }


    /**
     * Displays a formatted CLI section title.
     *
     * Example:
     *
     * ────────────────────────────────────────────
     *  Database
     * ────────────────────────────────────────────
     *
     * @param string $title Section title.
     *
     * @return void
     */
    protected static function section(string $title): void
    {
        $c = self::console();

        echo PHP_EOL;

        $c->separator();

        echo ' '
            . $c->white($title)
            . PHP_EOL;

        $c->separator();

        echo PHP_EOL;
    }
    /**
     * Outputs one or more blank lines to the terminal.
     * * @param int $count Number of new lines to output (default is 1).
     * @return void
     */
    public static function newLine(int $count = 1): void
    {
        echo str_repeat(PHP_EOL, $count);
    }

    /**
     * Displays the CLI help screen with all available command groups.
     *
     * Commands are organised by category and rendered using the
     * modern Slenix terminal UI helpers for improved readability.
     *
     * @return void
     */
    public static function help(): void
    {
        $c = self::console();

        $version = env('APP_VERSION') ?? self::$version;

        echo PHP_EOL;

        // ── Header ────────────────────────────────────────────────

        $c->separator();

        echo ' '
            . $c->white("Slenix CLI v{$version}")
            . PHP_EOL;

        $c->separator();

        echo PHP_EOL;

        // ── Usage ─────────────────────────────────────────────────

        echo $c->badge('USAGE', 'primary')
            . ' command [options] [arguments]'
            . PHP_EOL;

        echo PHP_EOL;

        // ── Command Groups ────────────────────────────────────────

        $groups = [

            'Application' => [
                'key:generate' => 'Generate the application key',
                'serve' => 'Start the HTTP development server',
                'serve --ws' => 'Start the HTTP + WebSocket server',
            ],

            'Generators' => [
                'make:controller' => 'Create a new controller class',
                'make:model' => 'Create a new model class',
                'make:middleware' => 'Create a new middleware class',
                'make:migration' => 'Create a new migration file',
                'make:seeder' => 'Create a new seeder class',
                'make:factory' => 'Create a new factory',
                'make:job' => 'Create a new job class',
                'make:request' => 'Create a new form request class',
            ],

            'Security' => [
                'make:throttle' => 'Generate the rate-limiting middleware',
            ],

            'Database' => [
                'migrate' => 'Run pending database migrations',
                'migrate:rollback' => 'Rollback the last migration batch',
                'migrate:reset' => 'Rollback all migrations',
                'migrate:fresh' => 'Drop all tables and re-run migrations',
                'migrate:status' => 'Display migration execution status',
                'db:seed' => 'Seed the database',
            ],

            'Queue' => [
                'queue:work' => 'Start processing queued jobs',
                'queue:work --once' => 'Process a single job and exit',
                'queue:work --queue=X' => 'Process a specific queue channel',
                'queue:failed' => 'Display failed queued jobs',
                'queue:clear' => 'Delete all pending jobs',
                'queue:clear --queue=X' => 'Clear a specific queue channel',
            ],

            'WebSocket' => [
                'ws:serve' => 'Start the standalone WebSocket server',
                'ws:serve --port=X' => 'Start WebSocket server on a custom port',
            ],

            'Other' => [
                'route:list' => 'List all registered routes',
                'view:clear' => 'Clear compiled view cache',
                'version' => 'Display installed CLI version',
                'help' => 'Display this help screen',
            ],

        ];

        // ── Render Groups ─────────────────────────────────────────

        foreach ($groups as $group => $commands) {

            self::section($group);

            foreach ($commands as $command => $description) {

                $command = str_pad($command, 32);

                echo '  '
                    . $c->colorize($command, 'success', true)
                    . ' '
                    . $c->muted($description)
                    . PHP_EOL;
            }

            echo PHP_EOL;
        }
    }
}