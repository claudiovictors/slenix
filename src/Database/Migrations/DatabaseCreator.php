<?php

/*
|--------------------------------------------------------------------------
| DatabaseCreator — Auto-create Missing Databases
|--------------------------------------------------------------------------
|
| Detects when the configured database does not exist and offers to create
| it automatically, mirroring the Laravel "database not found" UX.
|
| Supports MySQL, PostgreSQL and SQLite.
|
*/

declare(strict_types=1);

namespace Slenix\Database\Migrations;

use PDO;
use PDOException;

class DatabaseCreator
{
    /**
     * Attempts to create the database described by the given DSN config.
     * Returns true when the database was created (or already existed).
     *
     * @param array{
     *   driver: string,
     *   host: string,
     *   port: int|string,
     *   database: string,
     *   username: string,
     *   password: string,
     *   charset?: string,
     * } $config Database configuration.
     *
     * @return bool True if the database now exists.
     */
    public static function create(array $config): bool
    {
        $driver = strtolower($config['driver'] ?? 'mysql');

        return match ($driver) {
            'mysql'  => static::createMySQL($config),
            'pgsql'  => static::createPostgres($config),
            'sqlite' => static::createSQLite($config),
            default  => false,
        };
    }

    /**
     * Checks whether the configured database is reachable.
     * Returns the PDOException on failure, or null on success.
     *
     * @param array $config Database configuration.
     * @return PDOException|null
     */
    public static function probe(array $config): ?PDOException
    {
        try {
            $driver = strtolower($config['driver'] ?? 'mysql');

            if ($driver === 'sqlite') {
                $path = $config['database'];
                // SQLite "database not found" = file does not exist
                if ($path !== ':memory:' && !file_exists($path)) {
                    return new PDOException(
                        "SQLite database file not found: {$path}",
                        '1049'
                    );
                }
                return null;
            }

            $dsn = static::buildDsn($config, withDatabase: true);
            new PDO($dsn, $config['username'] ?? '', $config['password'] ?? '');
            return null;
        } catch (PDOException $e) {
            return $e;
        }
    }

    /**
     * Returns true when the PDO exception indicates a missing database.
     *
     * @param PDOException $e
     * @return bool
     */
    public static function isMissingDatabase(PDOException $e): bool
    {
        $code    = (string) $e->getCode();
        $message = strtolower($e->getMessage());

        // MySQL: 1049 Unknown database; SQLSTATE[HY000][1049]
        if ($code === '1049' || str_contains($message, 'unknown database')) {
            return true;
        }

        // PostgreSQL: 7 — FATAL: database "x" does not exist
        if ($code === '7' || str_contains($message, 'does not exist')) {
            return true;
        }

        // SQLite: file not found (our synthetic exception above)
        if (str_contains($message, 'sqlite database file not found')) {
            return true;
        }

        return false;
    }

    /**
     * Creates a MySQL/MariaDB database.
     *
     * @param array $config
     * @return bool
     */
    protected static function createMySQL(array $config): bool
    {
        $dsn      = static::buildDsn($config, withDatabase: false);
        $pdo      = new PDO($dsn, $config['username'] ?? '', $config['password'] ?? '');
        $charset  = $config['charset']   ?? 'utf8mb4';
        $collate  = $config['collation'] ?? 'utf8mb4_unicode_ci';
        $db       = $config['database'];

        $pdo->exec(
            "CREATE DATABASE IF NOT EXISTS `{$db}`"
            . " CHARACTER SET {$charset} COLLATE {$collate}"
        );

        return true;
    }

    /**
     * Creates a PostgreSQL database.
     *
     * @param array $config
     * @return bool
     */
    protected static function createPostgres(array $config): bool
    {
        // Connect to the maintenance database (postgres) first
        $maintConfig              = $config;
        $maintConfig['database'] = 'postgres';

        $dsn = static::buildDsn($maintConfig, withDatabase: true);
        $pdo = new PDO($dsn, $config['username'] ?? '', $config['password'] ?? '');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $db      = $config['database'];
        $charset = $config['charset'] ?? 'utf8';

        // Check if it already exists to avoid errors
        $stmt = $pdo->prepare(
            "SELECT 1 FROM pg_database WHERE datname = :name"
        );
        $stmt->execute(['name' => $db]);

        if (!$stmt->fetchColumn()) {
            $pdo->exec(
                "CREATE DATABASE \"{$db}\" ENCODING '{$charset}'"
            );
        }

        return true;
    }

    /**
     * Creates the SQLite database file (and any missing parent directories).
     *
     * @param array $config
     * @return bool
     */
    protected static function createSQLite(array $config): bool
    {
        $path = $config['database'];

        if ($path === ':memory:') {
            return true;
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Opening a PDO connection to a non-existent SQLite file creates it
        new PDO("sqlite:{$path}");

        return true;
    }

    /**
     * Builds a PDO DSN string from configuration.
     *
     * @param array $config       Database configuration.
     * @param bool  $withDatabase Whether to include the database name in the DSN.
     * @return string PDO DSN.
     */
    protected static function buildDsn(array $config, bool $withDatabase = true): string
    {
        $driver = strtolower($config['driver'] ?? 'mysql');
        $host   = $config['host']    ?? '127.0.0.1';
        $port   = $config['port']    ?? ($driver === 'pgsql' ? 5432 : 3306);
        $db     = $config['database'] ?? '';

        return match ($driver) {
            'pgsql'  => "pgsql:host={$host};port={$port}"
                . ($withDatabase ? ";dbname={$db}" : ''),

            'sqlite' => "sqlite:{$db}",

            default  => "mysql:host={$host};port={$port}"
                . ($withDatabase ? ";dbname={$db}" : '')
                . ';charset=' . ($config['charset'] ?? 'utf8mb4'),
        };
    }
}