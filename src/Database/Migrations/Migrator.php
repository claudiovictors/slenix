<?php

/*
|--------------------------------------------------------------------------
| Migrator — Database Migration Runner
|--------------------------------------------------------------------------
|
| Discovers, executes and reverts migrations. Keeps a `migrations` table
| for version control. Every migration is run exactly once (idempotent).
|
| Supports MySQL, PostgreSQL and SQLite through Grammar-aware DDL.
|
| Notes on transactions and DDL:
|   • MySQL auto-commits DDL (CREATE/DROP/ALTER TABLE). Wrapping DDL in
|     a transaction provides no rollback guarantee; we attempt it anyway
|     to protect purely-DML migrations.
|   • PostgreSQL DOES support transactional DDL. Migrations are fully
|     rolled back on failure.
|   • SQLite supports transactional DDL and deferred FK checks.
|
*/

declare(strict_types=1);

namespace Slenix\Database\Migrations;

use PDO;
use RuntimeException;
use Slenix\Database\Connection;

class Migrator
{

    /** @var PDO|null Lazy connection — only created when needed. */
    protected ?PDO $pdo = null;

    /** @var Grammar|null Lazy grammar — resolved after PDO is obtained. */
    protected ?Grammar $grammar = null;

    /** @var string Absolute path to the migrations directory. */
    protected string $migrationsPath;

    /**
     * @param string|null $migrationsPath Absolute path to migrations directory.
     *                                    Defaults to <project_root>/database/migrations.
     */
    public function __construct(?string $migrationsPath = null)
    {
        // No database connection here — kept lazy for CLI environments where
        // the framework may be bootstrapped before a DB is available.
        $this->migrationsPath = $migrationsPath
            ?? dirname(__DIR__, 5) . '/database/migrations';
    }

    /**
     * Returns the PDO instance, creating the connection on first call.
     *
     * @return PDO Active PDO connection.
     */
    protected function pdo(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = Connection::getInstance();
        }

        return $this->pdo;
    }

    /**
     * Returns the Grammar for the active driver, resolved lazily.
     *
     * @return Grammar Active grammar instance.
     */
    protected function grammar(): Grammar
    {
        if ($this->grammar === null) {
            $driver        = $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
            $this->grammar = new Grammar($driver);
        }

        return $this->grammar;
    }

    /**
     * Creates the `migrations` tracking table if it does not yet exist.
     * The DDL is produced by Grammar so it works on all supported drivers.
     *
     * @return void
     */
    public function ensureMigrationsTableExists(): void
    {
        $sql = $this->grammar()->compileMigrationsTable();
        $this->pdo()->exec($sql);
    }

    /**
     * Returns the names of all migrations that have already been executed.
     *
     * @return string[] Migration names in ascending execution order.
     */
    public function getRan(): array
    {
        $grammar = $this->grammar();
        $table   = $grammar->quoteTable('migrations');
        $col     = $grammar->quoteIdentifier('migration');
        $id      = $grammar->quoteIdentifier('id');

        $stmt = $this->pdo()->query(
            "SELECT {$col} FROM {$table} ORDER BY {$id} ASC"
        );

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Returns the highest batch number recorded in the migrations table.
     *
     * @return int Last batch number (0 when no migrations have been run).
     */
    public function getLastBatchNumber(): int
    {
        $grammar = $this->grammar();
        $table   = $grammar->quoteTable('migrations');
        $batch   = $grammar->quoteIdentifier('batch');

        $stmt = $this->pdo()->query("SELECT MAX({$batch}) FROM {$table}");

        return (int) $stmt->fetchColumn();
    }

    /**
     * Discovers all migration files in the configured directory,
     * sorted in chronological order (by file name prefix).
     *
     * No database connection is required for this method.
     *
     * @return array<string, string> Map of migration name → absolute file path.
     */
    public function getMigrationFiles(): array
    {
        if (!is_dir($this->migrationsPath)) {
            return [];
        }

        $files = glob($this->migrationsPath . '/*.php') ?: [];
        sort($files); // Ensures timestamp-prefix ordering

        $result = [];
        foreach ($files as $file) {
            $result[basename($file, '.php')] = $file;
        }

        return $result;
    }

    /**
     * Returns the subset of discovered migrations that have not yet been run.
     *
     * @return array<string, string> Pending migration name → file path.
     */
    public function getPendingMigrations(): array
    {
        $ran   = $this->getRan();
        $files = $this->getMigrationFiles();

        return array_diff_key($files, array_flip($ran));
    }

    /**
     * Executes all pending migrations in chronological order.
     *
     * @return string[] Names of the migrations that were executed.
     *
     * @throws RuntimeException When a migration fails.
     */
    public function run(): array
    {
        $this->ensureMigrationsTableExists();

        $pending = $this->getPendingMigrations();
        if (empty($pending)) {
            return [];
        }

        $batch    = $this->getLastBatchNumber() + 1;
        $executed = [];

        foreach ($pending as $name => $file) {
            $this->runMigration($name, $file, $batch);
            $executed[] = $name;
        }

        return $executed;
    }

    /**
     * Runs a single migration file inside a transaction (best-effort).
     *
     * @param string $name  Migration name (file base name without .php).
     * @param string $file  Absolute path to the migration file.
     * @param int    $batch Current batch number.
     * @return void
     *
     * @throws RuntimeException On migration failure.
     */
    protected function runMigration(string $name, string $file, int $batch): void
    {
        $migration     = $this->resolveMigration($file);
        $inTransaction = $this->beginTransaction();

        try {
            // Enable deferred FK checks for SQLite when inside a transaction
            if ($this->grammar()->isSQLite() && $inTransaction) {
                $this->pdo()->exec('PRAGMA defer_foreign_keys = ON');
            }

            $migration->up();
            $this->logMigration($name, $batch);

            $this->commitTransaction($inTransaction);
        } catch (\Throwable $e) {
            $this->rollbackTransaction($inTransaction);
            throw new RuntimeException(
                "Failed to run migration [{$name}]: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Reverts the most recent batch(es) of migrations.
     *
     * @param int $steps Number of batches to roll back (default 1).
     * @return string[] Names of the migrations that were reverted.
     *
     * @throws RuntimeException When a migration file is missing or rollback fails.
     */
    public function rollback(int $steps = 1): array
    {
        $this->ensureMigrationsTableExists();

        $lastBatch = $this->getLastBatchNumber();
        if ($lastBatch === 0) {
            return [];
        }

        $batches  = range($lastBatch, max(1, $lastBatch - $steps + 1));
        $reverted = [];

        foreach ($batches as $batch) {
            $names = $this->getMigrationsForBatch($batch);

            foreach ($names as $name) {
                $file = $this->resolveFilePath($name);
                $this->rollbackMigration($name, $file);
                $reverted[] = $name;
            }
        }

        return $reverted;
    }

    /**
     * Reverts ALL executed migrations (oldest-first safety is preserved by
     * fetching in DESC order).
     *
     * @return string[] Names of the migrations that were reverted.
     */
    public function reset(): array
    {
        $this->ensureMigrationsTableExists();

        $grammar  = $this->grammar();
        $table    = $grammar->quoteTable('migrations');
        $col      = $grammar->quoteIdentifier('migration');
        $id       = $grammar->quoteIdentifier('id');

        $stmt = $this->pdo()->query(
            "SELECT {$col} FROM {$table} ORDER BY {$id} DESC"
        );
        $migrations = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $reverted = [];

        foreach ($migrations as $name) {
            $file = $this->migrationsPath . '/' . $name . '.php';
            if (file_exists($file)) {
                $this->rollbackMigration($name, $file);
                $reverted[] = $name;
            }
        }

        return $reverted;
    }

    /**
     * Resets all migrations and re-runs them from scratch.
     *
     * @return array{reverted: string[], executed: string[]}
     */
    public function fresh(): array
    {
        $reverted = $this->reset();
        $executed = $this->run();

        return compact('reverted', 'executed');
    }

    /**
     * Reverts a single migration inside a transaction (best-effort).
     *
     * @param string $name Migration name.
     * @param string $file Absolute path to the migration file.
     * @return void
     *
     * @throws RuntimeException On rollback failure.
     */
    protected function rollbackMigration(string $name, string $file): void
    {
        $migration     = $this->resolveMigration($file);
        $inTransaction = $this->beginTransaction();

        try {
            if ($this->grammar()->isSQLite() && $inTransaction) {
                $this->pdo()->exec('PRAGMA defer_foreign_keys = ON');
            }

            $migration->down();
            $this->deleteLog($name);

            $this->commitTransaction($inTransaction);
        } catch (\Throwable $e) {
            $this->rollbackTransaction($inTransaction);
            throw new RuntimeException(
                "Failed to rollback migration [{$name}]: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Requires the migration file and validates that it returns a Migration instance.
     *
     * @param string $file Absolute path to the migration file.
     * @return Migration Instantiated migration object.
     *
     * @throws RuntimeException When the file does not return a Migration.
     */
    protected function resolveMigration(string $file): Migration
    {
        $migration = require $file;

        if (!($migration instanceof Migration)) {
            throw new RuntimeException(
                "The migration in [{$file}] must return a Migration instance. "
                . "Use: return new class extends Migration { ... };"
            );
        }

        return $migration;
    }

    /**
     * Resolves the absolute file path for a migration name.
     *
     * @param string $name Migration name.
     * @return string Absolute path.
     *
     * @throws RuntimeException When the file does not exist.
     */
    protected function resolveFilePath(string $name): string
    {
        $file = $this->migrationsPath . '/' . $name . '.php';

        if (!file_exists($file)) {
            throw new RuntimeException(
                "Migration file [{$name}] not found in: {$this->migrationsPath}"
            );
        }

        return $file;
    }

    /**
     * Inserts a migration record into the tracking table.
     *
     * @param string $name  Migration name.
     * @param int    $batch Batch number.
     * @return void
     */
    protected function logMigration(string $name, int $batch): void
    {
        $grammar = $this->grammar();
        $table   = $grammar->quoteTable('migrations');
        $colMig  = $grammar->quoteIdentifier('migration');
        $colBat  = $grammar->quoteIdentifier('batch');

        $stmt = $this->pdo()->prepare(
            "INSERT INTO {$table} ({$colMig}, {$colBat}) VALUES (:migration, :batch)"
        );
        $stmt->execute(['migration' => $name, 'batch' => $batch]);
    }

    /**
     * Removes the tracking record for a migration (called on rollback).
     *
     * @param string $name Migration name.
     * @return void
     */
    protected function deleteLog(string $name): void
    {
        $grammar = $this->grammar();
        $table   = $grammar->quoteTable('migrations');
        $col     = $grammar->quoteIdentifier('migration');

        $stmt = $this->pdo()->prepare(
            "DELETE FROM {$table} WHERE {$col} = :migration"
        );
        $stmt->execute(['migration' => $name]);
    }

    /**
     * Fetches migration names for a specific batch, in reverse execution order.
     *
     * @param int $batch Batch number.
     * @return string[] Migration names.
     */
    protected function getMigrationsForBatch(int $batch): array
    {
        $grammar = $this->grammar();
        $table   = $grammar->quoteTable('migrations');
        $colMig  = $grammar->quoteIdentifier('migration');
        $colBat  = $grammar->quoteIdentifier('batch');
        $colId   = $grammar->quoteIdentifier('id');

        $stmt = $this->pdo()->prepare(
            "SELECT {$colMig} FROM {$table}
             WHERE {$colBat} = :batch ORDER BY {$colId} DESC"
        );
        $stmt->execute(['batch' => $batch]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Begins a database transaction if one is not already active.
     * Returns false when a transaction could not be started (e.g. SQLite
     * already has an implicit one, or the driver does not support them).
     *
     * @return bool True when a new transaction was started.
     */
    protected function beginTransaction(): bool
    {
        try {
            if (!$this->pdo()->inTransaction()) {
                $this->pdo()->beginTransaction();
                return true;
            }
        } catch (\Throwable) {
            // Driver does not support transactions (very unlikely with PDO)
        }

        return false;
    }

    /**
     * Commits the current transaction if $inTransaction is true.
     *
     * @param bool $inTransaction Whether we own the current transaction.
     * @return void
     */
    protected function commitTransaction(bool $inTransaction): void
    {
        if ($inTransaction && $this->pdo()->inTransaction()) {
            $this->pdo()->commit();
        }
    }

    /**
     * Rolls back the current transaction if $inTransaction is true.
     * Errors during rollback are silenced intentionally (MySQL DDL).
     *
     * @param bool $inTransaction Whether we own the current transaction.
     * @return void
     */
    protected function rollbackTransaction(bool $inTransaction): void
    {
        if ($inTransaction && $this->pdo()->inTransaction()) {
            try {
                $this->pdo()->rollBack();
            } catch (\Throwable) {
                // MySQL DDL causes implicit commit; rollback will fail — that is expected.
            }
        }
    }

    /**
     * Returns a status report for all discovered migrations.
     *
     * @return array<int, array{migration: string, status: string, batch: int|null}>
     */
    public function status(): array
    {
        $this->ensureMigrationsTableExists();

        $grammar = $this->grammar();
        $table   = $grammar->quoteTable('migrations');
        $colMig  = $grammar->quoteIdentifier('migration');
        $colBat  = $grammar->quoteIdentifier('batch');
        $colId   = $grammar->quoteIdentifier('id');

        $stmt = $this->pdo()->query(
            "SELECT {$colMig}, {$colBat} FROM {$table} ORDER BY {$colId} ASC"
        );
        $ran   = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $files = $this->getMigrationFiles();

        $result = [];
        foreach ($files as $name => $file) {
            $result[] = [
                'migration' => $name,
                'status'    => isset($ran[$name]) ? 'Ran' : 'Pending',
                'batch'     => $ran[$name] ?? null,
            ];
        }

        return $result;
    }

    /**
     * Generates a timestamped migration file name.
     *
     * @example Migrator::generateName('create_users_table')
     *          → '2024_01_15_120000_create_users_table'
     *
     * @param string $name Raw migration name (snake_case).
     * @return string Timestamped migration name.
     */
    public static function generateName(string $name): string
    {
        return date('Y_m_d_His') . '_' . $name;
    }

    /**
     * Returns the configured migrations directory path.
     *
     * @return string Absolute path to the migrations directory.
     */
    public function getMigrationsPath(): string
    {
        return $this->migrationsPath;
    }
}