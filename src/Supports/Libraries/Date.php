<?php

declare(strict_types=1);

namespace Slenix\Supports\Libraries;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use IntlDateFormatter;
use InvalidArgumentException;

/**
 * Date — JavaScript-style Date API for PHP.
 *
 * An immutable-under-the-hood, mutable-in-API date/time class that mirrors
 * the ECMAScript Date object: the constructor accepts the same argument
 * shapes, getters are zero-based where the JS getters are zero-based,
 * setters mutate the instance and return the new timestamp, and invalid
 * input produces an "Invalid Date" state instead of throwing.
 *
 * Examples:
 *
 *      $date = new Date();
 *      $date = new Date(1727895720000);
 *      $date = new Date('2026-10-02 20:02:00');
 *      $date = new Date(2026, 9, 2, 20, 2, 0, 0);
 *
 *      $date->getFullYear();   // 2026
 *      $date->getMonth();      // 9 (October, zero-based)
 *      $date->getDay();        // 5 (Friday, 0 = Sunday)
 *
 * Requires PHP 8.1+. Locale formatting uses ext-intl (IntlDateFormatter)
 * when available, with a plain fallback otherwise.
 */
class Date
{
    /**
     * Date/time formats attempted, in order, when the constructor receives
     * a single string argument with no explicit format.
     *
     * @var string[]
     */
    private const PARSE_FORMATS = [
        'Y-m-d H:i:s.v',
        'Y-m-d H:i:s',
        'Y-m-d\TH:i:sP',
        'Y-m-d\TH:i:s',
        'Y-m-d',
        'd/m/Y H:i:s',
        'd/m/Y',
        'd-m-Y',
        'Y/m/d',
    ];

    /**
     * Underlying date/time instance. Null represents an invalid date.
     */
    private ?DateTimeImmutable $dt;

    /**
     * Shared UTC timezone instance.
     */
    private static DateTimeZone $utc;

    /**
     * Shared local timezone instance, taken from date_default_timezone_get().
     */
    private static DateTimeZone $local;

    /**
     * Creates a new Date instance.
     *
     * Supported argument shapes:
     *
     *      new Date()                              Current date and time.
     *      new Date(int|float $ms)                 Milliseconds since the Unix epoch.
     *      new Date(string $value)                 Date/time string (see PARSE_FORMATS).
     *      new Date(Date $other)                   Copy of another Date instance.
     *      new Date($year, $month, $day, ...)      Date components, month zero-based,
     *                                              with overflow normalisation.
     *
     * Years 0-99 are mapped to 1900+year. Out-of-range component values
     * overflow into the next unit instead of throwing. Unparseable input
     * results in an invalid date, never an exception.
     *
     * @param mixed ...$args
     */
    public function __construct(mixed ...$args)
    {
        self::$utc   ??= new DateTimeZone('UTC');
        self::$local ??= new DateTimeZone(date_default_timezone_get());

        $count = count($args);

        try {
            $this->dt = match (true) {
                $count === 0 => new DateTimeImmutable('now', self::$local),
                $count === 1 => $this->constructSingle($args[0]),
                default      => $this->constructParts($args),
            };
        } catch (Exception) {
            $this->dt = null;
        }
    }

    /**
     * Resolves a single constructor argument into a DateTimeImmutable.
     *
     * @param mixed $arg
     * @return DateTimeImmutable
     *
     * @throws InvalidArgumentException When the argument type is unsupported.
     */
    private function constructSingle(mixed $arg): DateTimeImmutable
    {
        if ($arg instanceof self) {
            return $this->requireValid($arg)->dt;
        }

        if (is_int($arg) || is_float($arg)) {
            return $this->fromMilliseconds($arg);
        }

        if (is_string($arg)) {
            if (is_numeric($arg)) {
                return $this->fromMilliseconds((float) $arg);
            }

            foreach (self::PARSE_FORMATS as $format) {
                $dt = DateTimeImmutable::createFromFormat($format, $arg, self::$local);
                if ($dt instanceof DateTimeImmutable) {
                    return $dt;
                }
            }

            return new DateTimeImmutable($arg, self::$local);
        }

        throw new InvalidArgumentException('Unsupported argument for Date constructor.');
    }

    /**
     * Builds a DateTimeImmutable from date/time components, with overflow
     * normalisation: month 12 rolls into January of the following year,
     * hour 24 rolls into the next day, and so on.
     *
     * @param array<int,mixed> $args year, month (zero-based), day, hours, minutes, seconds, milliseconds.
     * @return DateTimeImmutable
     */
    private function constructParts(array $args): DateTimeImmutable
    {
        $year  = (int) ($args[0] ?? 0);
        $month = (int) ($args[1] ?? 0);
        $day   = (int) ($args[2] ?? 1);
        $hour  = (int) ($args[3] ?? 0);
        $min   = (int) ($args[4] ?? 0);
        $sec   = (int) ($args[5] ?? 0);
        $ms    = (int) ($args[6] ?? 0);

        if ($year >= 0 && $year <= 99) {
            $year += 1900;
        }

        return (new DateTimeImmutable('now', self::$local))
            ->setTime(0, 0, 0, 0)
            ->setDate($year, $month + 1, $day)
            ->setTime($hour, $min, $sec, $ms * 1000);
    }

    /**
     * Builds a local-time DateTimeImmutable from milliseconds since the
     * Unix epoch.
     *
     * @param int|float $ms
     * @return DateTimeImmutable
     */
    private function fromMilliseconds(int|float $ms): DateTimeImmutable
    {
        $seconds = intdiv((int) $ms, 1000);
        $micro   = ((int) round($ms - $seconds * 1000)) * 1000;

        return DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%06d', $seconds, $micro), self::$utc)
            ->setTimezone(self::$local);
    }

    /**
     * Whether this instance holds a valid date.
     *
     * @return bool
     */
    public function isValid(): bool
    {
        return $this->dt !== null;
    }

    /**
     * Returns the given instance when valid, otherwise throws.
     *
     * @param Date|null $date
     * @return Date
     *
     * @throws InvalidArgumentException When the instance (or $date) is invalid.
     */
    private function requireValid(?self $date = null): self
    {
        $target = $date ?? $this;
        if ($target->dt === null) {
            throw new InvalidArgumentException('Invalid Date');
        }
        return $target;
    }

    /**
     * Returns the day of the month (1-31) in local time.
     *
     * @return int|float
     */
    public function getDate(): float|int
    {
        return $this->dt ? (int) $this->dt->format('j') : NAN;
    }

    /**
     * Returns the day of the week (0 = Sunday through 6 = Saturday)
     * in local time.
     *
     * @return int|float
     */
    public function getDay(): float|int
    {
        return $this->dt ? (int) $this->dt->format('w') : NAN;
    }

    /**
     * Returns the year (four digits) in local time.
     *
     * @return int|float
     */
    public function getFullYear(): float|int
    {
        return $this->dt ? (int) $this->dt->format('Y') : NAN;
    }

    /**
     * Returns the hour (0-23) in local time.
     *
     * @return int|float
     */
    public function getHours(): float|int
    {
        return $this->dt ? (int) $this->dt->format('G') : NAN;
    }

    /**
     * Returns the milliseconds component (0-999) in local time.
     *
     * @return int|float
     */
    public function getMilliseconds(): float|int
    {
        return $this->dt ? (int) round(((int) $this->dt->format('u')) / 1000) : NAN;
    }

    /**
     * Returns the minutes component (0-59) in local time.
     *
     * @return int|float
     */
    public function getMinutes(): float|int
    {
        return $this->dt ? (int) $this->dt->format('i') : NAN;
    }

    /**
     * Returns the month (0 = January through 11 = December) in local time.
     *
     * @return int|float
     */
    public function getMonth(): float|int
    {
        return $this->dt ? ((int) $this->dt->format('n')) - 1 : NAN;
    }

    /**
     * Returns the seconds component (0-59) in local time.
     *
     * @return int|float
     */
    public function getSeconds(): float|int
    {
        return $this->dt ? (int) $this->dt->format('s') : NAN;
    }

    /**
     * Returns the number of milliseconds since the Unix epoch.
     *
     * @return float
     */
    public function getTime(): float
    {
        if ($this->dt === null) {
            return NAN;
        }

        return (float) sprintf('%d%03d', $this->dt->getTimestamp(), $this->getMilliseconds());
    }

    /**
     * Returns the timezone offset, in minutes, using the UTC-minus-local
     * sign convention (e.g. GMT-3 yields 180).
     *
     * @return int|float
     */
    public function getTimezoneOffset(): float|int
    {
        return $this->dt ? (int) (-$this->dt->getOffset() / 60) : NAN;
    }

    /**
     * Returns the day of the month (1-31) in UTC.
     *
     * @return int|float
     */
    public function getUTCDate(): float|int
    {
        return $this->inUtc() ? (int) $this->inUtc()->format('j') : NAN;
    }

    /**
     * Returns the day of the week (0 = Sunday through 6 = Saturday) in UTC.
     *
     * @return int|float
     */
    public function getUTCDay(): float|int
    {
        return $this->inUtc() ? (int) $this->inUtc()->format('w') : NAN;
    }

    /**
     * Returns the year (four digits) in UTC.
     *
     * @return int|float
     */
    public function getUTCFullYear(): float|int
    {
        return $this->inUtc() ? (int) $this->inUtc()->format('Y') : NAN;
    }

    /**
     * Returns the hour (0-23) in UTC.
     *
     * @return int|float
     */
    public function getUTCHours(): float|int
    {
        return $this->inUtc() ? (int) $this->inUtc()->format('G') : NAN;
    }

    /**
     * Returns the milliseconds component (0-999) in UTC.
     *
     * @return int|float
     */
    public function getUTCMilliseconds(): float|int
    {
        return $this->dt === null ? NAN : (int) round(((int) $this->dt->format('u')) / 1000);
    }

    /**
     * Returns the minutes component (0-59) in UTC.
     *
     * @return int|float
     */
    public function getUTCMinutes(): float|int
    {
        return $this->inUtc() ? (int) $this->inUtc()->format('i') : NAN;
    }

    /**
     * Returns the month (0 = January through 11 = December) in UTC.
     *
     * @return int|float
     */
    public function getUTCMonth(): float|int
    {
        return $this->inUtc() ? ((int) $this->inUtc()->format('n')) - 1 : NAN;
    }

    /**
     * Returns the seconds component (0-59) in UTC.
     *
     * @return int|float
     */
    public function getUTCSeconds(): float|int
    {
        return $this->inUtc() ? (int) $this->inUtc()->format('s') : NAN;
    }

    /**
     * Returns the underlying instance shifted to UTC, or null when invalid.
     *
     * @return DateTimeImmutable|null
     */
    private function inUtc(): ?DateTimeImmutable
    {
        return $this->dt?->setTimezone(self::$utc);
    }

    /**
     * Sets the day of the month in local time. Accepts values outside the
     * 1-31 range, which overflow into adjacent months.
     *
     * @param int $day
     * @return float The new timestamp in milliseconds.
     */
    public function setDate(int $day): float
    {
        $this->requireValid();
        $this->dt = $this->dt->setDate((int) $this->dt->format('Y'), (int) $this->dt->format('n'), $day);
        return $this->getTime();
    }

    /**
     * Sets the year (optionally month and day) in local time. Years 0-99
     * are mapped to 1900+year.
     *
     * @param int $year
     * @param int|null $month Zero-based month.
     * @param int|null $day
     * @return float The new timestamp in milliseconds.
     */
    public function setFullYear(int $year, ?int $month = null, ?int $day = null): float
    {
        $this->requireValid();

        if ($year >= 0 && $year <= 99) {
            $year += 1900;
        }

        $this->dt = $this->dt->setDate(
            $year,
            $month !== null ? $month + 1 : (int) $this->dt->format('n'),
            $day ?? (int) $this->dt->format('j')
        );

        return $this->getTime();
    }

    /**
     * Sets the hour (and optionally minutes, seconds, milliseconds) in
     * local time, with overflow into adjacent units.
     *
     * @param int $hours
     * @param int|null $minutes
     * @param int|null $seconds
     * @param int|null $ms
     * @return float The new timestamp in milliseconds.
     */
    public function setHours(int $hours, ?int $minutes = null, ?int $seconds = null, ?int $ms = null): float
    {
        $this->requireValid();
        $this->dt = $this->dt->setTime(
            $hours,
            $minutes ?? (int) $this->dt->format('i'),
            $seconds ?? (int) $this->dt->format('s'),
            ($ms ?? $this->getMilliseconds()) * 1000
        );
        return $this->getTime();
    }

    /**
     * Sets the milliseconds component in local time.
     *
     * @param int $ms
     * @return float The new timestamp in milliseconds.
     */
    public function setMilliseconds(int $ms): float
    {
        $this->requireValid();
        $this->dt = $this->dt->setTime(
            (int) $this->dt->format('G'),
            (int) $this->dt->format('i'),
            (int) $this->dt->format('s'),
            $ms * 1000
        );
        return $this->getTime();
    }

    /**
     * Sets the minutes component (and optionally seconds and milliseconds)
     * in local time, with overflow into adjacent units.
     *
     * @param int $minutes
     * @param int|null $seconds
     * @param int|null $ms
     * @return float The new timestamp in milliseconds.
     */
    public function setMinutes(int $minutes, ?int $seconds = null, ?int $ms = null): float
    {
        $this->requireValid();
        $this->dt = $this->dt->setTime(
            (int) $this->dt->format('G'),
            $minutes,
            $seconds ?? (int) $this->dt->format('s'),
            ($ms ?? $this->getMilliseconds()) * 1000
        );
        return $this->getTime();
    }

    /**
     * Sets the month (zero-based, optionally the day too) in local time,
     * with overflow into adjacent years.
     *
     * @param int $month
     * @param int|null $day
     * @return float The new timestamp in milliseconds.
     */
    public function setMonth(int $month, ?int $day = null): float
    {
        $this->requireValid();
        $this->dt = $this->dt->setDate(
            (int) $this->dt->format('Y'),
            $month + 1,
            $day ?? (int) $this->dt->format('j')
        );
        return $this->getTime();
    }

    /**
     * Sets the seconds component (and optionally milliseconds) in local
     * time, with overflow into adjacent units.
     *
     * @param int $seconds
     * @param int|null $ms
     * @return float The new timestamp in milliseconds.
     */
    public function setSeconds(int $seconds, ?int $ms = null): float
    {
        $this->requireValid();
        $this->dt = $this->dt->setTime(
            (int) $this->dt->format('G'),
            (int) $this->dt->format('i'),
            $seconds,
            ($ms ?? $this->getMilliseconds()) * 1000
        );
        return $this->getTime();
    }

    /**
     * Sets the date from milliseconds since the Unix epoch.
     *
     * @param int|float $ms
     * @return float The new timestamp in milliseconds.
     */
    public function setTime(int|float $ms): float
    {
        $this->dt = $this->fromMilliseconds($ms);
        return $this->getTime();
    }

    /**
     * Sets the day of the month in UTC.
     *
     * @param int $day
     * @return float The new timestamp in milliseconds.
     */
    public function setUTCDate(int $day): float
    {
        $this->requireValid();
        $utc = $this->inUtc()->setDate((int) $this->inUtc()->format('Y'), (int) $this->inUtc()->format('n'), $day);
        $this->dt = $utc->setTimezone(self::$local);
        return $this->getTime();
    }

    /**
     * Sets the year (optionally month and day) in UTC. Years 0-99 are
     * mapped to 1900+year.
     *
     * @param int $year
     * @param int|null $month Zero-based month.
     * @param int|null $day
     * @return float The new timestamp in milliseconds.
     */
    public function setUTCFullYear(int $year, ?int $month = null, ?int $day = null): float
    {
        $this->requireValid();

        if ($year >= 0 && $year <= 99) {
            $year += 1900;
        }

        $utc = $this->inUtc()->setDate(
            $year,
            $month !== null ? $month + 1 : (int) $this->inUtc()->format('n'),
            $day ?? (int) $this->inUtc()->format('j')
        );
        $this->dt = $utc->setTimezone(self::$local);
        return $this->getTime();
    }

    /**
     * Sets the hour (and optionally minutes, seconds, milliseconds) in UTC.
     *
     * @param int $hours
     * @param int|null $minutes
     * @param int|null $seconds
     * @param int|null $ms
     * @return float The new timestamp in milliseconds.
     */
    public function setUTCHours(int $hours, ?int $minutes = null, ?int $seconds = null, ?int $ms = null): float
    {
        $this->requireValid();
        $utc = $this->inUtc()->setTime(
            $hours,
            $minutes ?? (int) $this->inUtc()->format('i'),
            $seconds ?? (int) $this->inUtc()->format('s'),
            ($ms ?? $this->getMilliseconds()) * 1000
        );
        $this->dt = $utc->setTimezone(self::$local);
        return $this->getTime();
    }

    /**
     * Sets the minutes component (and optionally seconds and milliseconds)
     * in UTC.
     *
     * @param int $minutes
     * @param int|null $seconds
     * @param int|null $ms
     * @return float The new timestamp in milliseconds.
     */
    public function setUTCMinutes(int $minutes, ?int $seconds = null, ?int $ms = null): float
    {
        $this->requireValid();
        $utc = $this->inUtc()->setTime(
            (int) $this->inUtc()->format('G'),
            $minutes,
            $seconds ?? (int) $this->inUtc()->format('s'),
            ($ms ?? $this->getMilliseconds()) * 1000
        );
        $this->dt = $utc->setTimezone(self::$local);
        return $this->getTime();
    }

    /**
     * Sets the month (zero-based, optionally the day too) in UTC.
     *
     * @param int $month
     * @param int|null $day
     * @return float The new timestamp in milliseconds.
     */
    public function setUTCMonth(int $month, ?int $day = null): float
    {
        $this->requireValid();
        $utc = $this->inUtc()->setDate(
            (int) $this->inUtc()->format('Y'),
            $month + 1,
            $day ?? (int) $this->inUtc()->format('j')
        );
        $this->dt = $utc->setTimezone(self::$local);
        return $this->getTime();
    }

    /**
     * Sets the seconds component (and optionally milliseconds) in UTC.
     *
     * @param int $seconds
     * @param int|null $ms
     * @return float The new timestamp in milliseconds.
     */
    public function setUTCSeconds(int $seconds, ?int $ms = null): float
    {
        $this->requireValid();
        $utc = $this->inUtc()->setTime(
            (int) $this->inUtc()->format('G'),
            (int) $this->inUtc()->format('i'),
            $seconds,
            ($ms ?? $this->getMilliseconds()) * 1000
        );
        $this->dt = $utc->setTimezone(self::$local);
        return $this->getTime();
    }

    /**
     * Formats the date portion in English, e.g. "Fri Oct 02 2026".
     *
     * @return string
     */
    public function toDateString(): string
    {
        return $this->dt?->format('D M d Y') ?? 'Invalid Date';
    }

    /**
     * Formats the time portion, e.g. "20:02:00 GMT-0300".
     *
     * @return string
     */
    public function toTimeString(): string
    {
        return $this->dt?->format('H:i:s \G\M\TO') ?? 'Invalid Date';
    }

    /**
     * Full string representation, e.g.
     * "Fri Oct 02 2026 20:02:00 GMT-0300 (America/Sao_Paulo)".
     *
     * @return string
     */
    public function toString(): string
    {
        if ($this->dt === null) {
            return 'Invalid Date';
        }

        return $this->dt->format('D M d Y H:i:s \G\M\TO') . ' (' . $this->dt->format('e') . ')';
    }

    /**
     * Formats the date in UTC, e.g. "Fri, 02 Oct 2026 20:02:00 GMT".
     *
     * @return string
     */
    public function toUTCString(): string
    {
        return $this->dt?->setTimezone(self::$utc)->format('D, d M Y H:i:s \G\M\T') ?? 'Invalid Date';
    }

    /**
     * ISO 8601 representation, e.g. "2026-10-02T20:02:00.000Z".
     *
     * @return string
     *
     * @throws InvalidArgumentException When the instance is an invalid date.
     */
    public function toISOString(): string
    {
        $this->requireValid();
        return $this->dt->setTimezone(self::$utc)->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * JSON representation of the date. Identical to toISOString().
     *
     * @return string
     */
    public function toJSON(): string
    {
        return $this->toISOString();
    }

    /**
     * Locale-aware date and time formatting.
     *
     * When ext-intl is available, supports the full set of Intl options:
     *
     *      ['dateStyle' => 'short|medium|long|full']
     *      ['timeStyle' => 'short|medium|long|full']
     *      ['weekday' => 'narrow|short|long']
     *      ['year' => 'numeric|2-digit']
     *      ['month' => 'numeric|2-digit|short|long|narrow']
     *      ['day' => 'numeric|2-digit']
     *      ['hour' => 'numeric|2-digit']
     *      ['minute' => 'numeric|2-digit']
     *      ['second' => 'numeric|2-digit']
     *      ['hour12' => bool]
     *
     * @param string|null $locale Locale code, e.g. 'pt-BR'. Defaults to app_locale() when available.
     * @param array<string,mixed> $options
     * @return string
     */
    public function toLocaleString(?string $locale = null, array $options = []): string
    {
        if ($this->dt === null || !extension_loaded('intl')) {
            return $this->dt === null ? 'Invalid Date' : $this->dt->format('Y-m-d H:i:s');
        }

        $locale = $this->normalizeLocale($locale);

        if ($this->hasFieldOptions($options)) {
            return $this->formatWithFields($locale, $options, true, true);
        }

        [$dateStyle, $timeStyle] = $this->resolveStyles($options, 'medium', 'short');

        $formatter = new IntlDateFormatter($locale, $dateStyle, $timeStyle, self::$local->getName());

        return $formatter->format($this->dt) ?: '';
    }

    /**
     * Locale-aware date-only formatting. Accepts the same options as
     * toLocaleString().
     *
     * @param string|null $locale
     * @param array<string,mixed> $options
     * @return string
     */
    public function toLocaleDateString(?string $locale = null, array $options = []): string
    {
        if ($this->dt === null || !extension_loaded('intl')) {
            return $this->dt === null ? 'Invalid Date' : $this->dt->format('Y-m-d');
        }

        $locale = $this->normalizeLocale($locale);

        if ($this->hasFieldOptions($options)) {
            return $this->formatWithFields($locale, $options, true, false);
        }

        [$dateStyle] = $this->resolveStyles($options, 'medium', 'medium');

        $formatter = new IntlDateFormatter($locale, $dateStyle, IntlDateFormatter::NONE, self::$local->getName());

        return $formatter->format($this->dt) ?: '';
    }

    /**
     * Locale-aware time-only formatting. Accepts the same options as
     * toLocaleString().
     *
     * @param string|null $locale
     * @param array<string,mixed> $options
     * @return string
     */
    public function toLocaleTimeString(?string $locale = null, array $options = []): string
    {
        if ($this->dt === null || !extension_loaded('intl')) {
            return $this->dt === null ? 'Invalid Date' : $this->dt->format('H:i:s');
        }

        $locale = $this->normalizeLocale($locale);

        if ($this->hasFieldOptions($options)) {
            return $this->formatWithFields($locale, $options, false, true);
        }

        [, $timeStyle] = $this->resolveStyles($options, 'medium', 'short');

        $formatter = new IntlDateFormatter(locale: $locale, timezone: self::$local->getName());
        $formatter->setPattern($timeStyle === IntlDateFormatter::SHORT ? 'HH:mm' : 'HH:mm:ss');

        return $formatter->format($this->dt) ?: '';
    }

    /**
     * Returns the current timestamp in milliseconds.
     *
     * @return int
     */
    public static function now(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    /**
     * Parses a date string into a timestamp in milliseconds.
     *
     * @param string $value
     * @return float NAN when the string cannot be parsed.
     */
    public static function parse(string $value): float
    {
        return (new self($value))->getTime();
    }

    /**
     * Builds a UTC timestamp in milliseconds from date/time components.
     *
     * @param mixed ...$args year, month (zero-based), day, hours, minutes, seconds, milliseconds.
     * @return float
     */
    public static function UTC(mixed ...$args): float
    {
        self::$utc   ??= new DateTimeZone('UTC');
        self::$local ??= new DateTimeZone(date_default_timezone_get());

        $year  = (int) ($args[0] ?? 0);
        $month = (int) ($args[1] ?? 0);
        $day   = (int) ($args[2] ?? 1);
        $hour  = (int) ($args[3] ?? 0);
        $min   = (int) ($args[4] ?? 0);
        $sec   = (int) ($args[5] ?? 0);
        $ms    = (int) ($args[6] ?? 0);

        if ($year >= 0 && $year <= 99) {
            $year += 1900;
        }

        $dt = (new DateTimeImmutable('now', self::$utc))
            ->setTime(0, 0, 0, 0)
            ->setDate($year, $month + 1, $day)
            ->setTime($hour, $min, $sec, $ms * 1000);

        return (float) sprintf('%d%03d', $dt->getTimestamp(), (int) round(((int) $dt->format('u')) / 1000));
    }

    /**
     * Returns the full string representation.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->toString();
    }

    /**
     * Normalizes a locale code (hyphens to underscores) and applies the
     * default locale when none is given.
     *
     * @param string|null $locale
     * @return string
     */
    private function normalizeLocale(?string $locale): string
    {
        $locale ??= function_exists('app_locale') ? app_locale() : 'pt_BR';
        return str_replace('-', '_', $locale);
    }

    /**
     * Whether any individual field option is present.
     *
     * @param array<string,mixed> $options
     * @return bool
     */
    private function hasFieldOptions(array $options): bool
    {
        foreach (['weekday', 'year', 'month', 'day', 'hour', 'minute', 'second'] as $field) {
            if (isset($options[$field])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Resolves dateStyle/timeStyle options to IntlDateFormatter constants.
     *
     * @param array<string,mixed> $options
     * @return array{0:int,1:int}
     */
    private function resolveStyles(array $options, string $defaultDate, string $defaultTime): array
    {
        $map = [
            'short'  => IntlDateFormatter::SHORT,
            'medium' => IntlDateFormatter::MEDIUM,
            'long'   => IntlDateFormatter::LONG,
            'full'   => IntlDateFormatter::FULL,
        ];

        return [
            $map[$options['dateStyle'] ?? $defaultDate] ?? IntlDateFormatter::MEDIUM,
            $map[$options['timeStyle'] ?? $defaultTime] ?? IntlDateFormatter::MEDIUM,
        ];
    }

    /**
     * Builds an ICU pattern from individual field options and formats the
     * date with it.
     *
     * @param string $locale
     * @param array<string,mixed> $options
     * @param bool $withDate
     * @param bool $withTime
     * @return string
     */
    private function formatWithFields(string $locale, array $options, bool $withDate, bool $withTime): string
    {
        $hour12  = (bool) ($options['hour12'] ?? false);
        $pattern = '';

        if ($withDate) {
            if (isset($options['weekday'])) {
                $pattern .= match ($options['weekday']) {
                    'narrow' => 'EEEEE ',
                    'short'  => 'E ',
                    default  => 'EEEE ',
                };
            }
            if (isset($options['year'])) {
                $pattern .= ($options['year'] === '2-digit' ? 'yy ' : 'y ');
            }
            if (isset($options['month'])) {
                $pattern .= match ($options['month']) {
                    'narrow'  => 'MMMMM ',
                    'short'   => 'MMM ',
                    '2-digit' => 'MM ',
                    'numeric' => 'M ',
                    default   => 'MMMM ',
                };
            }
            if (isset($options['day'])) {
                $pattern .= ($options['day'] === '2-digit' ? 'dd ' : 'd ');
            }
        }

        if ($withTime) {
            if (isset($options['hour'])) {
                $pattern .= $hour12
                    ? (($options['hour'] === '2-digit' ? 'hh' : 'h') . ':')
                    : (($options['hour'] === '2-digit' ? 'HH' : 'H') . ':');
            }
            if (isset($options['minute'])) {
                $pattern .= 'mm ';
            }
            if (isset($options['second'])) {
                $pattern .= 'ss ';
            }
            if ($hour12) {
                $pattern .= 'a';
            }
        }

        $formatter = new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::NONE, self::$local->getName());
        $formatter->setPattern(trim($pattern));

        return $formatter->format($this->dt) ?: '';
    }
}