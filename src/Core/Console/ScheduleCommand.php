<?php

declare(strict_types=1);

namespace Slenix\Core\Console;

use Slenix\Supports\Scheduling\Schedule;

class ScheduleCommand extends Command
{
    /**
     * Loads routes/console.php, evaluates all registered events, and
     * runs whichever are due at the current minute.
     *
     * @return void
     */
    public static function run(): void
    {
        $consoleFile = self::basePath('routes/console.php');

        if (!file_exists($consoleFile)) {
            echo PHP_EOL;
            self::warning('No routes/console.php found — nothing to schedule.');
            self::info('Create it and register tasks with Schedule::command(...) or Schedule::call(...).');
            echo PHP_EOL;
            return;
        }

        Schedule::clear();
        require $consoleFile;

        $events = Schedule::events();

        if (empty($events)) {
            echo PHP_EOL;
            self::warning('No scheduled tasks registered.');
            echo PHP_EOL;
            return;
        }

        $now = new \DateTimeImmutable('now');
        $ran = 0;

        foreach ($events as $event) {
            if (!$event->isDue($now)) {
                continue;
            }

            $label = $event->label();

            try {
                $event->run();
                self::success("Ran: {$label}");
            } catch (\Throwable $e) {
                self::error("Failed: {$label} — {$e->getMessage()}");
            }

            $ran++;
        }

        echo PHP_EOL;

        if ($ran === 0) {
            self::info('No tasks due at ' . $now->format('Y-m-d H:i') . '.');
        } else {
            self::success("{$ran} task(s) executed.");
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