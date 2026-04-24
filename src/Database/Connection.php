<?php

/*
|--------------------------------------------------------------------------
| Connection — PDO Singleton
|--------------------------------------------------------------------------
|
| Manages the PDO connection using the Singleton pattern to ensure a single
| active connection per process. Supports MySQL, PostgreSQL and SQLite.
|
| SQLite requires only a file path (or ':memory:') as the hostname.
|
*/

declare(strict_types=1);

namespace Slenix\Database;

use PDO;
use PDOException;
use Slenix\Core\Exceptions\ErrorHandler;

class Connection extends ErrorHandler
{

    /** @var Connection|null Singleton instance. */
    private static ?Connection $connection = null;

    /** @var PDO Active PDO object. */
    private PDO $pdo;

    /**
     * Builds the PDO connection from a configuration array.
     *
     * Required config keys:
     *   drive    — 'mysql' | 'pgsql' | 'sqlite'
     *   hostname — host (MySQL/PgSQL) or file path (SQLite, use ':memory:' for in-memory)
     *   port     — port number (MySQL/PgSQL; ignored for SQLite)
     *   dbname   — database name (MySQL/PgSQL; ignored for SQLite)
     *   username — database user (MySQL/PgSQL; ignored for SQLite)
     *   password — database password (MySQL/PgSQL; ignored for SQLite)
     *   charset  — connection charset (MySQL only, default 'utf8mb4')
     *
     * @param array|null $config Configuration array; null loads from app.php.
     *
     * @throws \Exception              When the configuration file is missing.
     * @throws \InvalidArgumentException When an unsupported driver is requested.
     */
    public function __construct(?array $config = null)
    {
        if ($config === null) {
            $configFile = __DIR__ . '/../Config/app.php';

            if (!file_exists($configFile)) {
                throw new \Exception('Configuration file not found: ' . $configFile);
            }

            $config = require_once $configFile;
            $config = $config['db_connect'];
        }

        $driver = strtolower(trim($config['drive'] ?? ''));

        if (!in_array($driver, ['mysql', 'pgsql', 'sqlite'], true)) {
            throw new \InvalidArgumentException(
                "Unsupported database driver: '{$config['drive']}'. Supported: mysql, pgsql, sqlite."
            );
        }

        try {
            $dsn     = $this->buildDsn($config, $driver);
            $options = $this->getPdoOptions($driver);

            if ($driver === 'sqlite') {
                // SQLite does not use username / password
                $this->pdo = new PDO($dsn, null, null, $options);
            } else {
                $this->pdo = new PDO(
                    $dsn,
                    $config['username'] ?? null,
                    $config['password'] ?? null,
                    $options
                );
            }
        } catch (PDOException $e) {
            $this->handleException($e);
        }
    }

    /**
     * Assembles the DSN string for the given driver and configuration.
     *
     * MySQL  → mysql:host=…;port=…;dbname=…;charset=…
     * PgSQL  → pgsql:host=… port=… dbname=… options='--client_encoding=UTF8'
     * SQLite → sqlite:/path/to/file  or  sqlite::memory:
     *
     * @param array  $config Validated configuration array.
     * @param string $driver Normalised driver name.
     * @return string PDO DSN string.
     */
    private function buildDsn(array $config, string $driver): string
    {
        return match ($driver) {
            'mysql' => sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $config['hostname'] ?? '127.0.0.1',
                $config['port']     ?? 3306,
                $config['dbname']   ?? '',
                $config['charset']  ?? 'utf8mb4'
            ),

            'pgsql' => sprintf(
                "pgsql:host=%s;port=%s;dbname=%s;options='--client_encoding=UTF8'",
                $config['hostname'] ?? '127.0.0.1',
                $config['port']     ?? 5432,
                $config['dbname']   ?? ''
            ),

            // SQLite: hostname is the file path (or ':memory:')
            'sqlite' => 'sqlite:' . ($config['hostname'] ?? ':memory:'),
        };
    }

    /**
     * Returns PDO connection options tailored to the active driver.
     *
     * Common options (all drivers):
     *   ERRMODE_EXCEPTION       — always throw PDOException on error
     *   FETCH_OBJ               — default fetch mode returns stdClass objects
     *   EMULATE_PREPARES false  — use native prepared statements
     *
     * PgSQL extras:
     *   STRINGIFY_FETCHES false — preserve native PHP types (int, float, bool)
     *
     * SQLite extras:
     *   Enable WAL journal mode and foreign-key enforcement via PRAGMA.
     *
     * @param string $driver Normalised driver name.
     * @return array<int, mixed> PDO options array.
     */
    private function getPdoOptions(string $driver): array
    {
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        if ($driver === 'pgsql') {
            $options[PDO::ATTR_STRINGIFY_FETCHES] = false;
        }

        return $options;
    }

    // =========================================================
    // POST-CONNECTION SETUP
    // =========================================================

    /**
     * Applies driver-specific PRAGMA / session settings after connection.
     * Called internally once the PDO object is constructed.
     *
     * SQLite:
     *   PRAGMA journal_mode = WAL  — improves concurrent read performance
     *   PRAGMA foreign_keys = ON   — enforce referential integrity
     *
     * @param string $driver Normalised driver name.
     * @return void
     */
    private function applyPostConnectSettings(string $driver): void
    {
        if ($driver === 'sqlite') {
            $this->pdo->exec('PRAGMA journal_mode = WAL');
            $this->pdo->exec('PRAGMA foreign_keys = ON');
        }
    }

    /**
     * Returns the shared PDO instance, creating the connection on first call.
     *
     * @return PDO Active PDO connection.
     */
    public static function getInstance(): PDO
    {
        if (self::$connection === null) {
            self::$connection = new self();
        }

        return self::$connection->pdo;
    }

    /**
     * Resets the singleton, forcing a new connection on the next call to
     * getInstance(). Useful in tests that need a fresh connection.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$connection = null;
    }

    /**
     * Executes a raw SQL query and returns all results as objects.
     *
     * @param string  $sql    Raw SQL string with optional named placeholders.
     * @param mixed[] $params Bind parameters (named).
     * @return mixed[] Fetched rows as stdClass objects.
     */
    public static function raw(string $sql, array $params = []): array
    {
        $pdo  = self::getInstance();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Returns the underlying PDO object for this instance.
     *
     * @return PDO The active PDO connection.
     */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Returns the PDO driver name for the active connection.
     * e.g. 'mysql', 'pgsql', 'sqlite'
     *
     * @return string Driver name.
     */
    public static function getDriver(): string
    {
        return self::getInstance()->getAttribute(PDO::ATTR_DRIVER_NAME);
    }
}