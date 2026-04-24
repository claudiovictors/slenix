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
    protected static string $version = '2.5';

    /**
     * Get an instance of the Console helper.
     * * @return Console An instance of the Console class.
     */
    protected static function console(): Console
    {
        return new Console();
    }

    /**
     * Base method for printing formatted lines to the console.
     * * @param string $symbol  The prefix symbol (e.g., '!', '✔').
     * @param string $message The message text to display.
     * @param string $color   The ANSI color name.
     * @return void
     */
    protected static function line(string $symbol, string $message, string $color): void
    {
        $c = self::console();
        echo $c->colorize("[$symbol] ", $color, true)
            . $c->colorize($message, $color)
            . PHP_EOL;
    }

    /**
     * Output an informational message.
     * * @param string $message The information to display.
     * @return void
     */
    public static function info(string $message): void
    {
        self::line('ℹ', $message, 'cyan');
    }

    /**
     * Output a success message.
     * * @param string $message The success message to display.
     * @return void
     */
    public static function success(string $message): void
    {
        self::line('✔', $message, 'green');
    }

    /**
     * Output a warning message.
     * * @param string $message The warning message to display.
     * @return void
     */
    public static function warning(string $message): void
    {
        self::line('!', $message, 'yellow');
    }

    /**
     * Output an error message.
     * * @param string $message The error message to display.
     * @return void
     */
    public static function error(string $message): void
    {
        self::line('✗', $message, 'red');
    }

    /**
     * Display the current version of the Celestial CLI.
     * * @return void
     */
    public static function version(): void
    {
        self::info("Slenix v" . self::$version);
    }

    /**
     * Displays a formatted table in the terminal.
     *
     * @param array $headers Table headers.
     * @param array $rows    Table rows (array of indexed arrays).
     * @return void
     */
    public static function table(array $headers, array $rows): void
    {
        $c = self::console();

        $widths = array_map('strlen', $headers);
        foreach ($rows as $row) {
            foreach (array_values($row) as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, strlen((string) $cell));
            }
        }

        $separator = '+' . implode('+', array_map(fn($w) => str_repeat('-', $w + 2), $widths)) . '+';

        echo $separator . PHP_EOL;

        $headerRow = '|';
        foreach ($headers as $i => $header) {
            $headerRow .= ' ' . $c->colorize(str_pad($header, $widths[$i]), 'cyan', true) . ' |';
        }
        echo $headerRow . PHP_EOL;
        echo $separator . PHP_EOL;

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
     * Outputs one or more blank lines to the terminal.
     * * @param int $count Number of new lines to output (default is 1).
     * @return void
     */
    public static function newLine(int $count = 1): void
    {
        echo str_repeat(PHP_EOL, $count);
    }

    /**
     * Displays CLI help with all available command groups and descriptions.
     * * @return void
     */
    public static function help(): void
    {
        $c       = self::console();
        $version = self::$version;

        echo PHP_EOL;
        echo $c->colorize("Celestial CLI {$version}", 'green') . PHP_EOL;
        echo PHP_EOL;

        echo $c->colorize("Usage:", 'yellow') . PHP_EOL;
        echo "  command [options] [arguments]" . PHP_EOL;
        echo PHP_EOL;

        $groups = [
            'Application' => [
                'key:generate'    => 'Generate the application key',
                'serve'           => 'Start the HTTP development server',
                'serve --ws'      => 'Start the HTTP + WebSocket server',
            ],
            'Generators' => [
                'make:controller' => 'Create a new controller class',
                'make:model'      => 'Create a new model class',
                'make:middleware' => 'Create a new middleware class',
                'make:migration'  => 'Create a new migration file',
                'make:seeder'     => 'Create a new seeder class',
                'make:factory'    => 'Create a new factory',
                'make:job'        => 'Create a new job class',
            ],
            'Security' => [
                'make:throttle'   => 'Generate the rate-limiting ThrottleMiddleware',
            ],
            'Database' => [
                'migrate'          => 'Run pending database migrations',
                'migrate:rollback' => 'Rollback the last migration batch',
                'migrate:reset'    => 'Rollback all migrations',
                'migrate:fresh'    => 'Drop all tables and re-run migrations',
                'migrate:status'   => 'Show migration status',
                'db:seed'          => 'Seed the database',
            ],
            'Queue' => [
                'queue:work'             => 'Start processing queued jobs',
                'queue:work --once'      => 'Process one job and exit',
                'queue:work --queue=X'   => 'Process a specific queue channel',
                'queue:failed'           => 'List all failed jobs',
                'queue:clear'            => 'Delete all pending jobs',
                'queue:clear --queue=X'  => 'Clear a specific queue channel',
            ],
            'WebSocket' => [
                'ws:serve'          => 'Start the standalone WebSocket server',
                'ws:serve --port=X' => 'Start WebSocket server on a custom port',
            ],
            'Other' => [
                'route:list' => 'List all registered routes',
                'view:clear' => 'Clear compiled view cache',
                'version'    => 'Display CLI version',
                'help'       => 'Display this help message',
            ],
        ];

        foreach ($groups as $group => $commands) {
            echo $c->colorize($group . ":", 'yellow') . PHP_EOL;

            foreach ($commands as $cmd => $desc) {
                $cmdFormatted = str_pad($cmd, 30);
                echo "  " . $c->colorize($cmdFormatted, 'green') . "  " . $desc . PHP_EOL;
            }

            echo PHP_EOL;
        }
    }
}