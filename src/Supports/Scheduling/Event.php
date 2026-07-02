<?php

/*
|--------------------------------------------------------------------------
| Event Class — Slenix Framework
|--------------------------------------------------------------------------
|
| Represents a single scheduled task. Wraps either a shell command
| (executed via exec()) or a PHP callback, paired with a cron-style
| frequency expression that determines when it is due to run.
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Scheduling;

class Event
{
    /** @var string|null Shell command to execute, if any. */
    private ?string $command = null;

    /** @var callable|null PHP callback to execute, if any. */
    private $callback = null;

    /** @var string Cron-style expression: "minute hour day month weekday". */
    private string $expression = '* * * * *';

    /** @var string|null Human-readable label shown in CLI output. */
    private ?string $description = null;

    /**
     * @param string|callable $action Shell command string or PHP callable.
     */
    public function __construct(string|callable $action)
    {
        if (is_string($action)) {
            $this->command = $action;
        } else {
            $this->callback = $action;
        }
    }

    // -------------------------------------------------------------------------
    // Frequency helpers
    // -------------------------------------------------------------------------

    /**
     * Sets a raw cron expression: "minute hour day month weekday".
     *
     * @param string $expression
     * @return self
     */
    public function cron(string $expression): self
    {
        $this->expression = trim($expression);
        return $this;
    }

    public function everyMinute(): self
    {
        return $this->cron('* * * * *');
    }

    public function everyFiveMinutes(): self
    {
        return $this->cron('*/5 * * * *');
    }

    public function everyTenMinutes(): self
    {
        return $this->cron('*/10 * * * *');
    }

    public function everyFifteenMinutes(): self
    {
        return $this->cron('*/15 * * * *');
    }

    public function everyThirtyMinutes(): self
    {
        return $this->cron('0,30 * * * *');
    }

    public function hourly(): self
    {
        return $this->cron('0 * * * *');
    }

    public function daily(): self
    {
        return $this->cron('0 0 * * *');
    }

    /**
     * Runs daily at a specific time.
     *
     * @param string $time Format "HH:MM", e.g. "03:30".
     * @return self
     */
    public function dailyAt(string $time): self
    {
        [$hour, $minute] = array_pad(explode(':', $time), 2, '0');
        return $this->cron(((int) $minute) . ' ' . ((int) $hour) . ' * * *');
    }

    public function weekly(): self
    {
        return $this->cron('0 0 * * 0');
    }

    public function monthly(): self
    {
        return $this->cron('0 0 1 * *');
    }

    // -------------------------------------------------------------------------
    // Metadata
    // -------------------------------------------------------------------------

    /**
     * Sets a human-readable label shown in CLI output.
     *
     * @param string $description
     * @return self
     */
    public function description(string $description): self
    {
        $this->description = $description;
        return $this;
    }

    /**
     * Returns the event's label — the explicit description if set,
     * otherwise the command string or "[closure]".
     *
     * @return string
     */
    public function label(): string
    {
        return $this->description ?? $this->command ?? '[closure]';
    }

    // -------------------------------------------------------------------------
    // Execution
    // -------------------------------------------------------------------------

    /**
     * Determines whether this event is due to run at the given moment.
     *
     * @param \DateTimeImmutable $now
     * @return bool
     */
    public function isDue(\DateTimeImmutable $now): bool
    {
        $parts = preg_split('/\s+/', $this->expression);

        if ($parts === false || count($parts) !== 5) {
            return false;
        }

        [$minute, $hour, $day, $month, $weekday] = $parts;

        return self::matchesField($minute, (int) $now->format('i'))
            && self::matchesField($hour, (int) $now->format('G'))
            && self::matchesField($day, (int) $now->format('j'))
            && self::matchesField($month, (int) $now->format('n'))
            && self::matchesField($weekday, (int) $now->format('w'));
    }

    /**
     * Executes the underlying command or callback.
     *
     * @return void
     */
    public function run(): void
    {
        if ($this->command !== null) {
            exec($this->command . ' > /dev/null 2>&1 &');
            return;
        }

        if ($this->callback !== null) {
            ($this->callback)();
        }
    }

    /**
     * Evaluates a single cron field against a value.
     * Supports: "*", "n", "n,m,o", "n-m", "* /n" (step), "n-m/s".
     *
     * @param string $field
     * @param int    $value
     * @return bool
     */
    private static function matchesField(string $field, int $value): bool
    {
        if ($field === '*') {
            return true;
        }

        foreach (explode(',', $field) as $part) {
            if (str_contains($part, '/')) {
                [$range, $step] = explode('/', $part, 2);
                $step = (int) $step;

                if ($step <= 0) {
                    continue;
                }

                if ($range === '*') {
                    if ($value % $step === 0) return true;
                    continue;
                }

                if (str_contains($range, '-')) {
                    [$start, $end] = array_map('intval', explode('-', $range, 2));
                    if ($value >= $start && $value <= $end && ($value - $start) % $step === 0) return true;
                    continue;
                }
            }

            if (str_contains($part, '-')) {
                [$start, $end] = array_map('intval', explode('-', $part, 2));
                if ($value >= $start && $value <= $end) return true;
                continue;
            }

            if (ctype_digit($part) && (int) $part === $value) {
                return true;
            }
        }

        return false;
    }
}