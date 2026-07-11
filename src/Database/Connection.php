<?php

/*
|--------------------------------------------------------------------------
| Connection — PDO Singleton
|--------------------------------------------------------------------------
|
| Manages the PDO connection using the Singleton pattern to ensure a single
| active connection per process. Supports MySQL, PostgreSQL and SQLite.
|
| Config keys (app.php → db_connect):
|   driver    — 'mysql' | 'pgsql' | 'sqlite'
|   host      — server host (MySQL/PgSQL) — ignored for SQLite
|   port      — server port (MySQL/PgSQL) — ignored for SQLite
|   database  — database name (MySQL/PgSQL) or file path (SQLite)
|               use ':memory:' for an in-memory SQLite database
|               relative paths are resolved from the project root
|   username  — database user  (MySQL/PgSQL) — ignored for SQLite
|   password  — database password (MySQL/PgSQL) — ignored for SQLite
|   charset   — connection charset, MySQL only (default 'utf8mb4')
|   collation — table collation,   MySQL only (default 'utf8mb4_unicode_ci')
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
     * When $config is null the connection parameters are loaded from
     * src/Config/app.php under the 'db_connect' key.
     *
     * @param  array<string, mixed>|null $config Configuration array.
     * @throws \Exception                When the configuration file is missing.
     * @throws \InvalidArgumentException When an unsupported driver is requested.
     */
    public function __construct(?array $config = null)
    {
        if ($config === null) {
            // src/Database/Connection.php → dirname 2x → src/ → dirname 1x → project root
            $configFile = dirname(__DIR__, 2) . '/src/Config/app.php';

            if (!file_exists($configFile)) {
                throw new \Exception('Configuration file not found: ' . $configFile);
            }

            $appConfig = require $configFile;
            $config    = $appConfig['db_connect'] ?? [];
        }

        // ── Normalise legacy key names so old configs keep working ──────────
        // drive    → driver
        // hostname → host
        // dbname   → database
        if (!isset($config['driver'])   && isset($config['drive']))    $config['driver']   = $config['drive'];
        if (!isset($config['host'])     && isset($config['hostname'])) $config['host']     = $config['hostname'];
        if (!isset($config['database']) && isset($config['dbname']))   $config['database'] = $config['dbname'];
        // ────────────────────────────────────────────────────────────────────

        $driver = strtolower(trim($config['driver'] ?? 'sqlite'));

        if (!in_array($driver, ['mysql', 'pgsql', 'sqlite'], true)) {
            throw new \InvalidArgumentException(
                "Unsupported database driver: '{$driver}'. Supported: mysql, pgsql, sqlite."
            );
        }

        // ── Resolve SQLite path ──────────────────────────────────────────────
        // Relative paths (e.g. 'database/database.sqlite') are resolved from
        // the project root, which is three levels above src/Database/.
        if ($driver === 'sqlite' && ($config['database'] ?? '') !== ':memory:') {
            $path = $config['database'] ?? 'database/database.sqlite';

            if (!str_starts_with($path, '/')) {
                // src/Database/Connection.php → 3 levels up = project root
                $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
            }

            // Create the directory automatically if it does not exist yet
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $config['database'] = $path;
        }
        // ────────────────────────────────────────────────────────────────────

        try {
            $dsn     = $this->buildDsn($config, $driver);
            $options = $this->getPdoOptions($driver);

            if ($driver === 'sqlite') {
                $this->pdo = new PDO($dsn, null, null, $options);
            } else {
                $this->pdo = new PDO(
                    $dsn,
                    $config['username'] ?? null,
                    $config['password'] ?? null,
                    $options
                );
            }

            $this->applyPostConnectSettings($driver);

        } catch (PDOException $e) {
            $this->handleException($e);
        }
    }

    // -------------------------------------------------------------------------
    // DSN Builder
    // -------------------------------------------------------------------------

    /**
     * Assembles the DSN string for the given driver and configuration.
     *
     * MySQL  → mysql:host=…;port=…;dbname=…;charset=…
     * PgSQL  → pgsql:host=…;port=…;dbname=…;options='--client_encoding=UTF8'
     * SQLite → sqlite:/absolute/path/to/file  or  sqlite::memory:
     *
     * @param  array<string, mixed> $config Validated configuration array.
     * @param  string               $driver Normalised driver name.
     * @return string PDO DSN string.
     */
    private function buildDsn(array $config, string $driver): string
    {
        return match ($driver) {
            'mysql' => sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $config['host']     ?? '127.0.0.1',
                $config['port']     ?? 3306,
                $config['database'] ?? '',
                $config['charset']  ?? 'utf8mb4'
            ),

            'pgsql' => sprintf(
                "pgsql:host=%s;port=%s;dbname=%s;options='--client_encoding=UTF8'",
                $config['host']     ?? '127.0.0.1',
                $config['port']     ?? 5432,
                $config['database'] ?? ''
            ),

            'sqlite' => 'sqlite:' . ($config['database'] ?? ':memory:'),
        };
    }

    // -------------------------------------------------------------------------
    // PDO Options
    // -------------------------------------------------------------------------

    /**
     * Returns PDO connection options tailored to the active driver.
     *
     * All drivers:
     *   ERRMODE_EXCEPTION      — always throw PDOException on error
     *   FETCH_OBJ              — default fetch mode returns stdClass objects
     *   EMULATE_PREPARES false — use native prepared statements
     *
     * PgSQL extras:
     *   STRINGIFY_FETCHES false — preserve native PHP types (int, float, bool)
     *
     * @param  string $driver Normalised driver name.
     * @return array<int, mixed>
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

    // -------------------------------------------------------------------------
    // Post-connection Setup
    // -------------------------------------------------------------------------

    /**
     * Applies driver-specific settings that require an open connection.
     *
     * SQLite:
     *   PRAGMA journal_mode = WAL — better concurrent read performance
     *   PRAGMA foreign_keys = ON  — enforce referential integrity
     *
     * @param  string $driver Normalised driver name.
     * @return void
     */
    private function applyPostConnectSettings(string $driver): void
    {
        if ($driver === 'sqlite') {
            $this->pdo->exec('PRAGMA journal_mode = WAL');
            $this->pdo->exec('PRAGMA foreign_keys = ON');
        }
    }

    // -------------------------------------------------------------------------
    // Static Interface
    // -------------------------------------------------------------------------

    /**
     * Returns the shared PDO instance, creating the connection on the first call.
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
     * Resets the singleton, forcing a fresh connection on the next getInstance() call.
     *
     * Useful in tests that need an isolated connection.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$connection = null;
    }

    /**
     * Executes a raw SQL query and returns all results as stdClass objects.
     *
     * @param  string         $sql    Raw SQL with optional named placeholders.
     * @param  mixed[]        $params Bind parameters (named).
     * @return mixed[]        Fetched rows as stdClass objects.
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
     * @return PDO
     */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Returns the PDO driver name for the active connection.
     *
     * @return string e.g. 'mysql', 'pgsql', 'sqlite'
     */
    public static function getDriver(): string
    {
        return self::getInstance()->getAttribute(PDO::ATTR_DRIVER_NAME);
    }
}