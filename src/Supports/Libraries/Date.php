<?php

/*
|--------------------------------------------------------------------------
| Date Class — Slenix Framework
|--------------------------------------------------------------------------
|
| Immutable, locale-aware date/time wrapper around DateTimeImmutable.
| Provides a fluent, professional API for creating, manipulating,
| comparing, diffing, and formatting dates — without ever relying on
| strtotime() for calculations. All arithmetic is performed through
| DateTimeImmutable's native interval methods, which are calendar-aware
| (correctly handles month/year length, leap years, DST, etc).
|
| Locale-aware formatting (month/day names, long-form dates) reads from
| app_locale() (APP_LOCALE in .env) and falls back to English when a
| locale has no translation table registered.
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Libraries;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

class Date
{
    /** @var DateTimeImmutable Underlying immutable date/time instance. */
    private readonly DateTimeImmutable $dt;

    /**
     * Date/time formats attempted by parse() when no explicit format is given,
     * tried in order until one successfully parses the input string.
     *
     * @var string[]
     */
    private const PARSE_FORMATS = [
        'Y-m-d H:i:s',
        'Y-m-d\TH:i:s',
        'Y-m-d',
        'd/m/Y H:i:s',
        'd/m/Y',
        'd-m-Y',
        'Y/m/d',
    ];

    /**
     * Locale translation tables for month/day names.
     * Add new locales here to support additional languages.
     *
     * @var array<string, array{months: string[], months_short: string[], days: string[], days_short: string[]}>
     */
    private static array $locales = [
        'en' => [
            'months' => ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
            'months_short' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
            'days' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'],
            'days_short' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
        ],
        'pt' => [
            'months' => ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'],
            'months_short' => ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'],
            'days' => ['segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado', 'domingo'],
            'days_short' => ['seg', 'ter', 'qua', 'qui', 'sex', 'sáb', 'dom'],
        ],
    ];

    // =========================================================================
    // Construction
    // =========================================================================

    /**
     * Date is always created through a named constructor (now(), parse(), etc).
     *
     * @param DateTimeImmutable $dt
     */
    private function __construct(DateTimeImmutable $dt)
    {
        $this->dt = $dt;
    }

    /**
     * Returns a Date instance representing the current moment, in the
     * application's configured timezone.
     *
     * @param  DateTimeZone|null $timezone Defaults to the app timezone.
     * @return static
     */
    public static function now(?DateTimeZone $timezone = null): static
    {
        return new static(new DateTimeImmutable('now', $timezone ?? self::appTimezone()));
    }

    /**
     * Returns a Date instance representing today at midnight (00:00:00).
     *
     * @param  DateTimeZone|null $timezone
     * @return static
     */
    public static function today(?DateTimeZone $timezone = null): static
    {
        return static::now($timezone)->startOfDay();
    }

    /**
     * Returns a Date instance representing yesterday at midnight.
     *
     * @param  DateTimeZone|null $timezone
     * @return static
     */
    public static function yesterday(?DateTimeZone $timezone = null): static
    {
        return static::today($timezone)->subDays(1);
    }

    /**
     * Returns a Date instance representing tomorrow at midnight.
     *
     * @param  DateTimeZone|null $timezone
     * @return static
     */
    public static function tomorrow(?DateTimeZone $timezone = null): static
    {
        return static::today($timezone)->addDays(1);
    }

    /**
     * Parses a date/time string into a Date instance.
     *
     * When $format is omitted, a series of common formats is attempted in
     * order (see PARSE_FORMATS). This never falls back to strtotime() —
     * an unparseable string always throws, keeping behaviour predictable.
     *
     * @param  string             $value    Date/time string to parse.
     * @param  string|null        $format   Explicit format (PHP date() tokens). Optional.
     * @param  DateTimeZone|null  $timezone Defaults to the app timezone.
     * @return static
     *
     * @throws InvalidArgumentException If the string cannot be parsed by any known format.
     */
    public static function parse(string $value, ?string $format = null, ?DateTimeZone $timezone = null): static
    {
        $tz = $timezone ?? self::appTimezone();

        if ($format !== null) {
            return static::createFromFormat($format, $value, $tz);
        }

        foreach (self::PARSE_FORMATS as $candidate) {
            $dt = DateTimeImmutable::createFromFormat($candidate, $value, $tz);
            if ($dt instanceof DateTimeImmutable) {
                return new static($dt);
            }
        }

        throw new InvalidArgumentException(
            "Unable to parse '{$value}' as a date. Provide an explicit format via Date::createFromFormat()."
        );
    }

    /**
     * Creates a Date instance from an explicit PHP date() format.
     *
     * @param  string            $format   PHP date() format tokens, e.g. 'd/m/Y'.
     * @param  string            $value    Value to parse against the format.
     * @param  DateTimeZone|null $timezone Defaults to the app timezone.
     * @return static
     *
     * @throws InvalidArgumentException If the value does not match the given format.
     */
    public static function createFromFormat(string $format, string $value, ?DateTimeZone $timezone = null): static
    {
        $dt = DateTimeImmutable::createFromFormat($format, $value, $timezone ?? self::appTimezone());

        if (!$dt instanceof DateTimeImmutable) {
            throw new InvalidArgumentException(
                "Value '{$value}' does not match format '{$format}'."
            );
        }

        return new static($dt);
    }

    /**
     * Creates a Date instance from a Unix timestamp.
     *
     * @param  int               $timestamp
     * @param  DateTimeZone|null $timezone Defaults to the app timezone.
     * @return static
     */
    public static function createFromTimestamp(int $timestamp, ?DateTimeZone $timezone = null): static
    {
        $dt = (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone ?? self::appTimezone());
        return new static($dt);
    }

    /**
     * Wraps an existing DateTimeInterface (DateTime or DateTimeImmutable)
     * instance into a Date, for interoperability with native PHP code.
     *
     * @param  DateTimeInterface $dateTime
     * @return static
     */
    public static function fromDateTime(DateTimeInterface $dateTime): static
    {
        return new static(
            $dateTime instanceof DateTimeImmutable
                ? $dateTime
                : DateTimeImmutable::createFromInterface($dateTime)
        );
    }

    // =========================================================================
    // Manipulation (immutable — every call returns a new instance)
    // =========================================================================

    public function addSeconds(int $seconds): static
    {
        return $this->withDateTime($this->dt->modify("{$seconds} seconds"));
    }

    public function subSeconds(int $seconds): static
    {
        return $this->addSeconds(-$seconds);
    }

    public function addMinutes(int $minutes): static
    {
        return $this->withDateTime($this->dt->modify("{$minutes} minutes"));
    }

    public function subMinutes(int $minutes): static
    {
        return $this->addMinutes(-$minutes);
    }

    public function addHours(int $hours): static
    {
        return $this->withDateTime($this->dt->modify("{$hours} hours"));
    }

    public function subHours(int $hours): static
    {
        return $this->addHours(-$hours);
    }

    public function addDays(int $days): static
    {
        return $this->withDateTime($this->dt->modify("{$days} days"));
    }

    public function subDays(int $days): static
    {
        return $this->addDays(-$days);
    }

    public function addWeeks(int $weeks): static
    {
        return $this->addDays($weeks * 7);
    }

    public function subWeeks(int $weeks): static
    {
        return $this->addDays(-$weeks * 7);
    }

    /**
     * Adds calendar months. Calendar-aware: adding 1 month to Jan 31 lands
     * on the last valid day of February rather than overflowing into March.
     *
     * @param  int $months
     * @return static
     */
    public function addMonths(int $months): static
{
    return $this->withDateTime($this->clampedMonthDate($months));
}

    public function subMonths(int $months): static
{
    return $this->withDateTime($this->clampedMonthDate(-$months));
}

    public function addYears(int $years): static
    {
        return $this->withDateTime($this->dt->modify("{$years} years"));
    }

    public function subYears(int $years): static
    {
        return $this->addYears(-$years);
    }

    public function startOfDay(): static
    {
        return $this->withDateTime($this->dt->setTime(0, 0, 0));
    }

    public function endOfDay(): static
    {
        return $this->withDateTime($this->dt->setTime(23, 59, 59));
    }

    public function startOfWeek(): static
    {
        return $this->withDateTime($this->dt->modify('monday this week')->setTime(0, 0, 0));
    }

    public function endOfWeek(): static
    {
        return $this->withDateTime($this->dt->modify('sunday this week')->setTime(23, 59, 59));
    }

    public function startOfMonth(): static
    {
        return $this->withDateTime($this->dt->modify('first day of this month')->setTime(0, 0, 0));
    }

    public function endOfMonth(): static
    {
        return $this->withDateTime($this->dt->modify('last day of this month')->setTime(23, 59, 59));
    }

    public function startOfYear(): static
    {
        return $this->withDateTime($this->dt->setDate((int) $this->dt->format('Y'), 1, 1)->setTime(0, 0, 0));
    }

    public function endOfYear(): static
    {
        return $this->withDateTime($this->dt->setDate((int) $this->dt->format('Y'), 12, 31)->setTime(23, 59, 59));
    }

    /**
 * Computes a target date after adding/subtracting whole calendar months,
 * clamping the day-of-month to the last valid day of the target month
 * instead of overflowing (e.g. Jan 31 + 1 month → Feb 28/29, not Mar 2/3).
 *
 * @param  int $months
 * @return DateTimeImmutable
 */
private function clampedMonthDate(int $months): DateTimeImmutable
{
    $firstOfTarget = $this->dt->modify('first day of ' . ($months >= 0 ? "+{$months} month" : "{$months} month"));
    $lastDay = (int) $firstOfTarget->format('t');
    $originalDay = min((int) $this->dt->format('j'), $lastDay);

    return $firstOfTarget->setDate(
        (int) $firstOfTarget->format('Y'),
        (int) $firstOfTarget->format('n'),
        $originalDay
    )->setTime(
        (int) $this->dt->format('H'),
        (int) $this->dt->format('i'),
        (int) $this->dt->format('s')
    );
}

    // =========================================================================
    // Comparison
    // =========================================================================

    public function isBefore(Date $other): bool
    {
        return $this->dt < $other->dt;
    }

    public function isAfter(Date $other): bool
    {
        return $this->dt > $other->dt;
    }

    public function isSameDay(Date $other): bool
    {
        return $this->dt->format('Y-m-d') === $other->dt->format('Y-m-d');
    }

    public function isSameMonth(Date $other): bool
    {
        return $this->dt->format('Y-m') === $other->dt->format('Y-m');
    }

    public function isSameYear(Date $other): bool
    {
        return $this->dt->format('Y') === $other->dt->format('Y');
    }

    public function isPast(): bool
    {
        return $this->dt < static::now()->dt;
    }

    public function isFuture(): bool
    {
        return $this->dt > static::now()->dt;
    }

    public function isToday(): bool
    {
        return $this->isSameDay(static::now());
    }

    public function isTomorrow(): bool
    {
        return $this->isSameDay(static::tomorrow());
    }

    public function isYesterday(): bool
    {
        return $this->isSameDay(static::yesterday());
    }

    public function isWeekend(): bool
    {
        return in_array((int) $this->dt->format('N'), [6, 7], true);
    }

    public function isWeekday(): bool
    {
        return !$this->isWeekend();
    }

    // =========================================================================
    // Diffing
    // =========================================================================

    public function diffInSeconds(Date $other): int
    {
        return abs($other->dt->getTimestamp() - $this->dt->getTimestamp());
    }

    public function diffInMinutes(Date $other): int
    {
        return intdiv($this->diffInSeconds($other), 60);
    }

    public function diffInHours(Date $other): int
    {
        return intdiv($this->diffInSeconds($other), 3600);
    }

    public function diffInDays(Date $other): int
    {
        return (int) $this->dt->diff($other->dt)->days;
    }

    public function diffInWeeks(Date $other): int
    {
        return intdiv($this->diffInDays($other), 7);
    }

    public function diffInMonths(Date $other): int
    {
        $interval = $this->dt->diff($other->dt);
        return ($interval->y * 12) + $interval->m;
    }

    public function diffInYears(Date $other): int
    {
        return (int) $this->dt->diff($other->dt)->y;
    }

    /**
     * Returns a human-readable, relative description of the time distance
     * between this Date and now — e.g. "3 days ago", "in 2 hours", "just now".
     *
     * Uses the largest applicable unit (years > months > weeks > days >
     * hours > minutes), mirroring the granularity behaviour of the
     * existing human_date() global helper, but locale-aware and returned
     * as part of this class rather than a standalone function.
     *
     * @param  string $locale Defaults to app_locale().
     * @return string
     */
    public function diffForHumans(?string $locale = null): string
    {
        $now = static::now();
        $isPast = $this->isBefore($now);
        $interval = $this->dt->diff($now->dt);

        $unit = match (true) {
            $interval->y > 0 => [$interval->y, $interval->y === 1 ? 'year' : 'years'],
            $interval->m > 0 => [$interval->m, $interval->m === 1 ? 'month' : 'months'],
            $interval->d >= 7 => [(int) ($interval->d / 7), (int) ($interval->d / 7) === 1 ? 'week' : 'weeks'],
            $interval->d > 0 => [$interval->d, $interval->d === 1 ? 'day' : 'days'],
            $interval->h > 0 => [$interval->h, $interval->h === 1 ? 'hour' : 'hours'],
            $interval->i > 0 => [$interval->i, $interval->i === 1 ? 'minute' : 'minutes'],
            default => null,
        };

        if ($unit === null) {
            return $this->translate('just_now', $locale) ?? 'just now';
        }

        [$amount, $unitLabel] = $unit;
        $phrase = "{$amount} {$unitLabel}";

        return $isPast
            ? $phrase . ' ' . ($this->translate('ago', $locale) ?? 'ago')
            : ($this->translate('in', $locale) ?? 'in') . ' ' . $phrase;
    }

    // =========================================================================
    // Formatting
    // =========================================================================

    /**
     * Formats using native PHP date() format tokens. Direct passthrough —
     * not locale-aware (use the named formatters below for that).
     *
     * @param  string $format
     * @return string
     */
    public function format(string $format): string
    {
        return $this->dt->format($format);
    }

    public function toDateString(): string
    {
        return $this->dt->format('Y-m-d');
    }

    public function toTimeString(): string
    {
        return $this->dt->format('H:i:s');
    }

    public function toDateTimeString(): string
    {
        return $this->dt->format('Y-m-d H:i:s');
    }

    /**
     * Short, locale-aware date — e.g. English: "Jan 1, 2026".
     *
     * @param  string|null $locale Defaults to app_locale().
     * @return string
     */
    public function toFormattedDate(?string $locale = null): string
    {
        $month = $this->monthNameShort($locale);
        $day = $this->dt->format('j');
        $year = $this->dt->format('Y');

        return $this->isPortuguese($locale)
            ? "{$day} {$month} {$year}"
            : "{$month} {$day}, {$year}";
    }

    /**
     * Full-word, locale-aware date — e.g. English: "January 1, 2026",
     * Portuguese: "1 de janeiro de 2026".
     *
     * @param  string|null $locale Defaults to app_locale().
     * @return string
     */
    public function toLongDate(?string $locale = null): string
    {
        $month = $this->monthName($locale);
        $day = $this->dt->format('j');
        $year = $this->dt->format('Y');

        return $this->isPortuguese($locale)
            ? "{$day} de {$month} de {$year}"
            : "{$month} {$day}, {$year}";
    }

    /**
     * Full-word date including the weekday name — e.g. English:
     * "Thursday, January 1, 2026", Portuguese: "quinta-feira, 1 de janeiro de 2026".
     *
     * @param  string|null $locale Defaults to app_locale().
     * @return string
     */
    public function toFullDate(?string $locale = null): string
    {
        $weekday = $this->dayName($locale);
        $longDate = $this->toLongDate($locale);

        return "{$weekday}, {$longDate}";
    }

    public function monthName(?string $locale = null): string
    {
        $index = (int) $this->dt->format('n') - 1;
        return $this->localeTable($locale)['months'][$index];
    }

    public function monthNameShort(?string $locale = null): string
    {
        $index = (int) $this->dt->format('n') - 1;
        return $this->localeTable($locale)['months_short'][$index];
    }

    public function dayName(?string $locale = null): string
    {
        $index = (int) $this->dt->format('N') - 1;
        return $this->localeTable($locale)['days'][$index];
    }

    public function dayNameShort(?string $locale = null): string
    {
        $index = (int) $this->dt->format('N') - 1;
        return $this->localeTable($locale)['days_short'][$index];
    }

    // =========================================================================
    // Accessors
    // =========================================================================

    public function year(): int
    {
        return (int) $this->dt->format('Y');
    }

    public function month(): int
    {
        return (int) $this->dt->format('n');
    }

    public function day(): int
    {
        return (int) $this->dt->format('j');
    }

    public function hour(): int
    {
        return (int) $this->dt->format('G');
    }

    public function minute(): int
    {
        return (int) $this->dt->format('i');
    }

    public function second(): int
    {
        return (int) $this->dt->format('s');
    }

    public function timestamp(): int
    {
        return $this->dt->getTimestamp();
    }

    // =========================================================================
    // Interoperability
    // =========================================================================

    /**
     * Returns the underlying native DateTimeImmutable instance.
     *
     * @return DateTimeImmutable
     */
    public function toDateTimeImmutable(): DateTimeImmutable
    {
        return $this->dt;
    }

    public function __toString(): string
    {
        return $this->toDateTimeString();
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /**
     * Creates a new Date wrapping the given DateTimeImmutable, preserving
     * immutability (this Date instance itself is never mutated).
     *
     * @param  DateTimeImmutable $dt
     * @return static
     */
    private function withDateTime(DateTimeImmutable $dt): static
    {
        return new static($dt);
    }

    /**
     * Resolves the translation table for the given (or app-configured)
     * locale, falling back to English when unregistered.
     *
     * @param  string|null $locale
     * @return array{months: string[], months_short: string[], days: string[], days_short: string[]}
     */
    private function localeTable(?string $locale): array
    {
        $resolved = $this->resolveLocale($locale);
        return self::$locales[$resolved] ?? self::$locales['en'];
    }

    /**
     * Resolves the effective locale code, defaulting to app_locale()
     * and normalizing regional variants (pt_BR, pt_PT) to their base
     * language table (pt) when no exact match is registered.
     *
     * @param  string|null $locale
     * @return string
     */
    private function resolveLocale(?string $locale): string
    {
        $locale = $locale ?? (function_exists('app_locale') ? app_locale() : 'en');
        $locale = strtolower($locale);

        if (isset(self::$locales[$locale])) {
            return $locale;
        }

        $base = explode('_', $locale)[0];
        return isset(self::$locales[$base]) ? $base : 'en';
    }

    private function isPortuguese(?string $locale): bool
    {
        return $this->resolveLocale($locale) === 'pt';
    }

    /**
     * Translates small connector phrases used by diffForHumans() ('ago',
     * 'in', 'just_now'). Returns null when no translation exists, letting
     * the caller fall back to the English default.
     *
     * @param  string      $key
     * @param  string|null $locale
     * @return string|null
     */
    private function translate(string $key, ?string $locale): ?string
    {
        $phrases = [
            'pt' => ['ago' => 'atrás', 'in' => 'em', 'just_now' => 'agora mesmo'],
        ];

        $resolved = $this->resolveLocale($locale);
        return $phrases[$resolved][$key] ?? null;
    }

    /**
     * Resolves the application's configured timezone as a DateTimeZone.
     *
     * @return DateTimeZone
     */
    private static function appTimezone(): DateTimeZone
    {
        return new DateTimeZone(function_exists('app_timezone') ? app_timezone() : 'UTC');
    }

    /**
     * Registers or overrides a locale's translation table at runtime.
     * Useful for adding languages beyond the built-in 'en' and 'pt'.
     *
     * @example
     * Date::registerLocale('es', [
     *     'months' => ['enero', 'febrero', ...],
     *     'months_short' => ['ene', 'feb', ...],
     *     'days' => ['lunes', 'martes', ...],
     *     'days_short' => ['lun', 'mar', ...],
     * ]);
     *
     * @param  string $locale
     * @param  array{months: string[], months_short: string[], days: string[], days_short: string[]} $table
     * @return void
     */
    public static function registerLocale(string $locale, array $table): void
    {
        self::$locales[strtolower($locale)] = $table;
    }
}