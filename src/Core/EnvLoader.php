<?php

/*
|--------------------------------------------------------------------------
| EnvLoader Class — Slenix Framework
|--------------------------------------------------------------------------
|
| Responsible for loading, parsing and resolving environment variables
| from a .env file. Supports inline comments, variable interpolation,
| quoted values, type casting, and validation.
|
*/

declare(strict_types=1);

namespace Slenix\Core;

class EnvLoader
{
    /** @var string Path to the .env file. */
    private static string $path_env = '';

    /** @var array<string, mixed> Parsed variables cache. */
    private static array $loaded = [];

    /**
     * Loads environment variables from the specified .env file.
     *
     * @param  string $path_env Full path to the .env file.
     * @param  bool   $override Whether to override existing env vars.
     * @throws \RuntimeException If the file is not found or unreadable.
     * @return void
     */
    public static function load(string $path_env, bool $override = false): void
    {
        self::$path_env = $path_env;

        if (!file_exists(self::$path_env) || !is_readable(self::$path_env)) {
            throw new \RuntimeException('.env file not found or unreadable at: ' . self::$path_env);
        }

        $lines = file(self::$path_env, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            throw new \RuntimeException('Failed to read .env file at: ' . self::$path_env);
        }

        foreach ($lines as $index => $line) {
            // Strip UTF-8 BOM if present — only ever possible on the first line,
            // but files saved/edited on Windows commonly carry it.
            if ($index === 0) {
                $line = self::stripBom($line);
            }

            self::parseLine(trim($line), $override, $index + 1);
        }
    }

    /**
     * Removes a UTF-8 byte order mark from the start of a string, if present.
     *
     * @param  string $line
     * @return string
     */
    private static function stripBom(string $line): string
    {
        $bom = "\xEF\xBB\xBF";
        if (str_starts_with($line, $bom)) {
            return substr($line, strlen($bom));
        }
        return $line;
    }

    /**
     * Parses a single line from the .env file.
     *
     * @param  string $line
     * @param  bool   $override
     * @param  int    $lineNumber For diagnostic purposes only.
     * @return void
     */
    private static function parseLine(string $line, bool $override, int $lineNumber = 0): void
    {
        // Skip empty lines and full-line comments
        if ($line === '' || str_starts_with($line, '#')) {
            return;
        }

        if (!str_contains($line, '=')) {
            return;
        }

        [$variable, $value] = explode('=', $line, 2);

        $variable = trim($variable);
        $value    = trim($value);

        if ($variable === '') {
            return;
        }

        // Strip inline comments (e.g. VALUE=something # comment)
        $value = self::stripInlineComment($value);

        // Remove surrounding quotes (" or ')
        $value = self::unquote($value);

        // Resolve variable interpolation: ${VAR} or $VAR
        $value = self::interpolate($value);

        // Skip if already set and not overriding
        if (!$override && (array_key_exists($variable, $_ENV) || array_key_exists($variable, $_SERVER))) {
            return;
        }

        $castValue = self::castValue($value);

        putenv("$variable=$value");
        $_ENV[$variable]    = $castValue;
        $_SERVER[$variable] = $castValue;

        self::$loaded[$variable] = $castValue;
    }

    /**
     * Strips inline comments from a value.
     * Handles quoted strings (won't strip # inside quotes).
     *
     * @param  string $value
     * @return string
     */
    private static function stripInlineComment(string $value): string
    {
        // If value is quoted, don't strip comments inside quotes
        if (preg_match('/^(["\']).*\1$/', $value)) {
            return $value;
        }

        // Remove everything from the first unquoted #
        $result = preg_replace('/\s+#.*$/', '', $value);

        return $result ?? $value;
    }

    /**
     * Removes surrounding single or double quotes from a value.
     *
     * @param  string $value
     * @return string
     */
    private static function unquote(string $value): string
    {
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last  = $value[-1];

            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                return substr($value, 1, -1);
            }
        }

        return $value;
    }

    /**
     * Resolves variable interpolation: ${VAR_NAME} and $VAR_NAME.
     *
     * @param  string $value
     * @return string
     */
    private static function interpolate(string $value): string
    {
        $value = preg_replace_callback('/\$\{([A-Z_][A-Z0-9_]*)\}/', function (array $matches): string {
            return (string) self::get($matches[1], $matches[0]);
        }, $value) ?? $value;

        $value = preg_replace_callback('/\$([A-Z_][A-Z0-9_]*)/', function (array $matches): string {
            return (string) self::get($matches[1], $matches[0]);
        }, $value) ?? $value;

        return $value;
    }

    /**
     * Casts string values to their appropriate PHP types.
     *
     * Uses regex instead of ctype_digit() so it never depends on ext-ctype
     * being compiled/enabled — some minimal PHP builds (e.g. Termux) don't
     * ship it activated by default.
     *
     * @param  string $value
     * @return mixed
     */
    private static function castValue(string $value): mixed
    {
        $lower = strtolower($value);

        return match (true) {
            $lower === 'true'                    => true,
            $lower === 'false'                   => false,
            $lower === 'null'                    => null,
            $lower === 'empty'                   => '',
            preg_match('/^\d+$/', $value) === 1   => (int) $value,
            is_numeric($value)                   => (float) $value,
            default                               => $value,
        };
    }

    /**
     * Retrieves the value of an environment variable with optional type casting.
     *
     * @param  string $name    Variable name.
     * @param  mixed  $default Default value if not found.
     * @param  string|null $cast  Optional: 'int', 'float', 'bool', 'string', 'array'
     * @return mixed
     */
    public static function get(string $name, mixed $default = null, ?string $cast = null): mixed
    {
        $value = self::$loaded[$name] ?? getenv($name);

        if ($value === false || $value === null) {
            return $default;
        }

        if ($cast !== null) {
            return match ($cast) {
                'int'    => (int) $value,
                'float'  => (float) $value,
                'bool'   => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                'string' => (string) $value,
                'array'  => array_map('trim', explode(',', (string) $value)),
                default  => $value,
            };
        }

        return $value;
    }

    /**
     * Checks whether an environment variable exists.
     *
     * @param  string $name
     * @return bool
     */
    public static function has(string $name): bool
    {
        return isset(self::$loaded[$name]) || getenv($name) !== false;
    }

    /**
     * Returns all loaded environment variables.
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        return self::$loaded;
    }

    /**
     * Validates that required environment variables are present and non-empty.
     *
     * @param  string[] $required List of required variable names.
     * @throws \RuntimeException If any required variables are missing.
     * @return void
     */
    public static function validate(array $required): void
    {
        $missing = [];

        foreach ($required as $key) {
            if (!self::has($key) || self::get($key) === '' || self::get($key) === null) {
                $missing[] = $key;
            }
        }

        if (!empty($missing)) {
            throw new \RuntimeException(
                'Missing required environment variables: ' . implode(', ', $missing)
            );
        }
    }

    /**
     * Reloads the .env file from scratch, clearing all previously loaded vars.
     *
     * @param  bool $override Whether to override existing env vars on reload.
     * @return void
     */
    public static function reload(bool $override = true): void
    {
        foreach (self::$loaded as $key => $_) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        self::$loaded = [];

        self::load(self::$path_env, $override);
    }
}