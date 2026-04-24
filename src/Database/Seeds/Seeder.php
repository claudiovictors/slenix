<?php

/*
|--------------------------------------------------------------------------
| Seeder — Abstract Base Class
|--------------------------------------------------------------------------
|
| All application seeders extend this class. Provides helpers for
| inserting, truncating and verifying data in a driver-aware manner.
|
| Supported drivers: mysql | pgsql | sqlite
|
| Driver differences handled transparently:
|   INSERT IGNORE (MySQL) vs INSERT OR IGNORE (SQLite) vs ON CONFLICT DO NOTHING (PgSQL)
|   TRUNCATE … (MySQL/PgSQL) vs DELETE FROM … (SQLite)
|   FK-check disabling for bulk operations (MySQL: SET FOREIGN_KEY_CHECKS)
|   Identifier quoting (backtick vs double-quote)
|
*/

declare(strict_types=1);

namespace Slenix\Database\Seeds;

use PDO;
use RuntimeException;
use Slenix\Database\Connection;
use Slenix\Database\Migrations\Grammar;

abstract class Seeder
{
    // =========================================================
    // PROPERTIES
    // =========================================================

    /** @var PDO Active PDO connection. */
    protected PDO $pdo;

    /** @var Grammar Grammar instance for the active driver. */
    protected Grammar $grammar;

    // =========================================================
    // CONSTRUCTOR
    // =========================================================

    /**
     * Obtains the PDO connection and builds the matching Grammar instance.
     */
    public function __construct()
    {
        $this->pdo     = Connection::getInstance();
        $this->grammar = new Grammar(
            $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)
        );
    }

    // =========================================================
    // ABSTRACT CONTRACT
    // =========================================================

    /**
     * Executes the seeder logic.
     * Implement this method to insert data into the database.
     *
     * @return void
     */
    abstract public function run(): void;

    // =========================================================
    // SEEDER DELEGATION
    // =========================================================

    /**
     * Calls one or more other seeders from within this seeder.
     *
     * @example $this->call(UserSeeder::class);
     * @example $this->call([UserSeeder::class, PostSeeder::class]);
     *
     * @param string|string[] $seeders Fully-qualified class name(s) of seeders to run.
     * @return void
     *
     * @throws RuntimeException When a seeder class does not exist or is invalid.
     */
    public function call(string|array $seeders): void
    {
        foreach ((array) $seeders as $seederClass) {
            if (!class_exists($seederClass)) {
                throw new RuntimeException("Seeder [{$seederClass}] not found.");
            }

            $seeder = new $seederClass();

            if (!($seeder instanceof self)) {
                throw new RuntimeException(
                    "Class [{$seederClass}] must extend Seeder."
                );
            }

            echo "    → Running {$seederClass}..." . PHP_EOL;
            $seeder->run();
        }
    }

    // =========================================================
    // INSERT HELPERS
    // =========================================================

    /**
     * Inserts a single row, silently ignoring duplicate-key conflicts.
     *
     * MySQL  → INSERT IGNORE INTO `table`
     * SQLite → INSERT OR IGNORE INTO "table"
     * PgSQL  → INSERT INTO "table" … ON CONFLICT DO NOTHING
     *
     * @param string               $table Table name.
     * @param array<string, mixed> $data  Associative array of column → value.
     * @return bool True on success (false means the row already existed — not an error).
     */
    protected function insertOrIgnore(string $table, array $data): bool
    {
        if (empty($data)) {
            return false;
        }

        $columns = array_keys($data);
        $holders = ':' . implode(', :', $columns);
        $sql     = $this->grammar->compileInsertOrIgnore($table, $columns, $holders);

        return $this->pdo->prepare($sql)->execute($data);
    }

    /**
     * Inserts multiple rows in batches using a single prepared statement
     * per chunk to minimise round-trips.
     *
     * All rows MUST share the same set of column keys.
     *
     * @example
     * $this->insertBatch('users', [
     *     ['name' => 'Alice', 'email' => 'alice@example.com'],
     *     ['name' => 'Bob',   'email' => 'bob@example.com'],
     * ]);
     *
     * @param string                        $table Table name.
     * @param array<int, array<string, mixed>> $rows  Array of row data (associative).
     * @param int                           $chunk Rows per INSERT statement (default 500).
     * @return int Total number of rows inserted.
     */
    protected function insertBatch(string $table, array $rows, int $chunk = 500): int
    {
        if (empty($rows)) {
            return 0;
        }

        // Filter invalid entries
        $rows = array_values(
            array_filter($rows, fn($r) => is_array($r) && !empty($r))
        );

        if (empty($rows)) {
            return 0;
        }

        $columns     = array_keys($rows[0]);
        $quotedTable = $this->grammar->quoteTable($table);
        $quotedCols  = $this->grammar->quoteColumns($columns);
        $total       = 0;

        foreach (array_chunk($rows, $chunk) as $batch) {
            $placeholders = [];
            $bindings     = [];

            foreach ($batch as $i => $row) {
                $rowParams = [];
                foreach ($columns as $col) {
                    $key              = "{$col}_{$i}";
                    $rowParams[]      = ":{$key}";
                    $bindings[$key]   = $row[$col] ?? null;
                }
                $placeholders[] = '(' . implode(', ', $rowParams) . ')';
            }

            $sql  = "INSERT INTO {$quotedTable} ({$quotedCols}) VALUES "
                  . implode(', ', $placeholders);

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($bindings);
            $total += $stmt->rowCount();
        }

        return $total;
    }

    /**
     * Inserts a single row using a standard INSERT.
     *
     * @param string               $table Table name.
     * @param array<string, mixed> $data  Associative array of column → value.
     * @return bool True on success.
     */
    protected function insert(string $table, array $data): bool
    {
        if (empty($data)) {
            return false;
        }

        $columns     = array_keys($data);
        $quotedTable = $this->grammar->quoteTable($table);
        $quotedCols  = $this->grammar->quoteColumns($columns);
        $holders     = ':' . implode(', :', $columns);

        $sql = "INSERT INTO {$quotedTable} ({$quotedCols}) VALUES ({$holders})";

        return $this->pdo->prepare($sql)->execute($data);
    }

    // =========================================================
    // TRUNCATE
    // =========================================================

    /**
     * Removes all rows from a table and resets auto-increment counters.
     *
     * MySQL  → SET FOREIGN_KEY_CHECKS = 0; TRUNCATE TABLE `t`; SET FOREIGN_KEY_CHECKS = 1
     * PgSQL  → TRUNCATE TABLE "t" RESTART IDENTITY CASCADE
     * SQLite → DELETE FROM "t"  (no native TRUNCATE)
     *
     * @param string $table Table name.
     * @return void
     */
    protected function truncate(string $table): void
    {
        foreach ($this->grammar->compileTruncate($table) as $sql) {
            $this->pdo->exec($sql);
        }
    }

    // =========================================================
    // UTILITY METHODS
    // =========================================================

    /**
     * Executes a raw SQL statement with optional bind parameters.
     *
     * @example $this->statement("UPDATE users SET verified = 1 WHERE role = 'admin'");
     * @example $this->statement("UPDATE users SET role = :role WHERE id = :id", ['role' => 'admin', 'id' => 1]);
     *
     * @param string               $sql      Raw SQL statement.
     * @param array<string, mixed> $bindings Named bind parameters.
     * @return bool True on success.
     */
    protected function statement(string $sql, array $bindings = []): bool
    {
        return $this->pdo->prepare($sql)->execute($bindings);
    }

    /**
     * Checks whether a row matching all given conditions already exists.
     *
     * @example $this->exists('users', ['email' => 'admin@example.com'])
     *
     * @param string               $table      Table name.
     * @param array<string, mixed> $conditions Column → value conditions (AND logic).
     * @return bool True when at least one matching row exists.
     */
    protected function exists(string $table, array $conditions): bool
    {
        $quotedTable = $this->grammar->quoteTable($table);

        $clauses = array_map(
            fn(string $col) => $this->grammar->quoteIdentifier($col) . " = :{$col}",
            array_keys($conditions)
        );

        $sql  = "SELECT COUNT(*) FROM {$quotedTable} WHERE " . implode(' AND ', $clauses);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($conditions);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Returns the row count for a table, optionally filtered by conditions.
     *
     * @param string               $table      Table name.
     * @param array<string, mixed> $conditions Optional WHERE conditions (AND logic).
     * @return int Number of matching rows.
     */
    protected function count(string $table, array $conditions = []): int
    {
        $quotedTable = $this->grammar->quoteTable($table);
        $sql         = "SELECT COUNT(*) FROM {$quotedTable}";

        $bindings = [];
        if (!empty($conditions)) {
            $clauses = array_map(
                fn(string $col) => $this->grammar->quoteIdentifier($col) . " = :{$col}",
                array_keys($conditions)
            );
            $sql     .= ' WHERE ' . implode(' AND ', $clauses);
            $bindings = $conditions;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Fetches a single row from a table matching the given conditions.
     * Returns null when no row is found.
     *
     * @param string               $table      Table name.
     * @param array<string, mixed> $conditions WHERE conditions (AND logic).
     * @return object|null stdClass row or null.
     */
    protected function findOne(string $table, array $conditions): ?object
    {
        $quotedTable = $this->grammar->quoteTable($table);

        $clauses = array_map(
            fn(string $col) => $this->grammar->quoteIdentifier($col) . " = :{$col}",
            array_keys($conditions)
        );

        $sql  = "SELECT * FROM {$quotedTable} WHERE " . implode(' AND ', $clauses) . ' LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($conditions);

        $row = $stmt->fetch(PDO::FETCH_OBJ);

        return $row !== false ? $row : null;
    }

    // =========================================================
    // IDENTIFIER QUOTING SHORTCUTS
    // =========================================================

    /**
     * Quotes a table identifier for the active driver.
     *
     * MySQL → `table`   PgSQL/SQLite → "table"
     *
     * @param string $table Raw table name.
     * @return string Quoted identifier.
     */
    protected function quoteTable(string $table): string
    {
        return $this->grammar->quoteTable($table);
    }

    /**
     * Quotes a column identifier for the active driver.
     *
     * MySQL → `col`   PgSQL/SQLite → "col"
     *
     * @param string $column Raw column name.
     * @return string Quoted identifier.
     */
    protected function quoteCol(string $column): string
    {
        return $this->grammar->quoteIdentifier($column);
    }

    /**
     * Quotes and joins an array of column names with commas.
     *
     * @param string[] $columns Raw column names.
     * @return string Comma-separated quoted identifiers.
     */
    protected function quoteColumns(array $columns): string
    {
        return $this->grammar->quoteColumns($columns);
    }

    // =========================================================
    // DRIVER DETECTION
    // =========================================================

    /**
     * Returns true when the active driver is MySQL.
     *
     * @return bool
     */
    protected function isMySQL(): bool
    {
        return $this->grammar->isMySQL();
    }

    /**
     * Returns true when the active driver is PostgreSQL.
     *
     * @return bool
     */
    protected function isPgSQL(): bool
    {
        return $this->grammar->isPgSQL();
    }

    /**
     * Returns true when the active driver is SQLite.
     *
     * @return bool
     */
    protected function isSQLite(): bool
    {
        return $this->grammar->isSQLite();
    }
}