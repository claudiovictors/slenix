<?php

/*
|--------------------------------------------------------------------------
| Schedule Class — Slenix Framework
|--------------------------------------------------------------------------
|
| Static registry for scheduled tasks. Tasks are declared in
| routes/console.php and evaluated by `celestial schedule:run`,
| which should be invoked once per minute via a single system cron entry.
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Scheduling;

class Schedule
{
    /** @var Event[] */
    private static array $events = [];

    /**
     * Registers a shell command as a scheduled task.
     *
     * @param string $command
     * @return Event
     */
    public static function command(string $command): Event
    {
        $event = new Event($command);
        self::$events[] = $event;
        return $event;
    }

    /**
     * Registers a PHP callback as a scheduled task.
     *
     * @param callable $callback
     * @return Event
     */
    public static function call(callable $callback): Event
    {
        $event = new Event($callback);
        self::$events[] = $event;
        return $event;
    }

    /**
     * Returns all registered events.
     *
     * @return Event[]
     */
    public static function events(): array
    {
        return self::$events;
    }

    /**
     * Clears all registered events. Useful for tests.
     *
     * @return void
     */
    public static function clear(): void
    {
        self::$events = [];
    }
}