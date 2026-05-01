<?php

/*
|--------------------------------------------------------------------------
| EnvLoader Class — Slenix Framework
|--------------------------------------------------------------------------
|
| This class is responsible for loading environment variables from a .env file.
| It parses the file line by line, ignores comments and empty lines, and 
| populates $_ENV, $_SERVER, and putenv().
|
*/

declare(strict_types=1);

namespace Slenix\Core;

class EnvLoader
{
    /** @var string Path to the .env file. */
    private static string $path_env = '';

    /**
     * Loads environment variables from the specified file.
     * * @param string $path_env Full path to the .env file.
     * @throws \Exception If the .env file is not found.
     * @return void
     */
    public static function load(string $path_env): void
    {
        self::$path_env = $path_env;

        if (!file_exists(self::$path_env)) {
            throw new \Exception('.env file not found at: ' . self::$path_env);
        }

        $lines = file(self::$path_env, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip comments
            if (str_starts_with($line, '#')) {
                continue;
            }

            if (str_contains($line, '=')) {
                list($variable, $value) = explode('=', $line, 2);
                
                $variable = trim($variable);
                $value    = trim($value);

                // Register variables if they don't already exist in globals
                if (!array_key_exists($variable, $_ENV) && !array_key_exists($variable, $_SERVER)) {
                    putenv("$variable=$value");
                    $_ENV[$variable] = $value;
                    $_SERVER[$variable] = $value;
                }
            }
        }
    }

    /**
     * Retrieves the value of a loaded environment variable.
     * * @param string $name
     * @param mixed  $default
     * @return mixed
     */
    public function get(string $name, mixed $default = null): mixed
    {
        return getenv($name) ?: $default;
    }
}