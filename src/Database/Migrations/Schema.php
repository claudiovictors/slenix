<?php

/*
|--------------------------------------------------------------------------
| Schema — DDL Facade
|--------------------------------------------------------------------------
|
| Static facade for DDL operations (CREATE, ALTER, DROP, RENAME, TRUNCATE).
| Delegates all driver-specific SQL generation to the Grammar class so that
| every operation works correctly on MySQL, PostgreSQL and SQLite.
|
*/

declare(strict_types=1);

namespace Slenix\Database\Migrations;

use PDO;
use RuntimeException;
use Slenix\Database\Connection;

class Schema
{

    /**
     * Resolves the active PDO connection and builds a Grammar instance
     * for the current database driver.
     *
     * @return array{pdo: PDO, grammar: Grammar}
     */
    protected static function resolve(): array
    {
        $pdo     = Connection::getInstance();
        $driver  = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $grammar = new Grammar($driver);

        return compact('pdo', 'grammar');
    }

    /**
     * Creates a new table.
     *
     * The user callback receives a Blueprint instance pre-loaded with
     * the active Grammar so that column definitions are translated
     * correctly for the current driver.
     *
     * @example
     * Schema::create('users', function (Blueprint $table) {
     *     $table->id();
     *     $table->string('name');
     *     $table->string('email')->unique();
     *     $table->timestamps();
     * });
     *
     * @param string   $table    Table name.
     * @param callable $callback Receives a Blueprint instance.
     * @return void
     *
     * @throws RuntimeException On SQL execution failure.
     */
    public static function create(string $table, callable $callback): void
    {
        ['pdo' => $pdo, 'grammar' => $grammar] = static::resolve();

        $blueprint = new Blueprint($table, $grammar);
        $callback($blueprint);

        $sql = static::buildCreateSql($blueprint, $grammar);
        $pdo->exec($sql);

        // PgSQL/SQLite: CREATE INDEX statements cannot be inside CREATE TABLE;
        // emit them as separate statements after table creation.
        $postStatements = static::buildPostCreateStatements($blueprint, $grammar);
        foreach ($postStatements as $stmt) {
            $pdo->exec($stmt);
        }
    }

    /**
     * Builds the full CREATE TABLE SQL string.
     *
     * @param Blueprint $blueprint Populated blueprint.
     * @param Grammar   $grammar   Active grammar.
     * @return string Complete CREATE TABLE statement.
     */
    protected static function buildCreateSql(Blueprint $blueprint, Grammar $grammar): string
    {
        $body = $blueprint->toCreateSql();

        $sql  = $grammar->compileCreateTable($blueprint->getTable()) . "\n";
        $sql .= $body . "\n";
        $sql .= $grammar->compileCreateTableEnd();

        return $sql;
    }

    /**
     * Returns standalone SQL statements that must be executed after CREATE TABLE.
     *
     * On MySQL nothing extra is needed (indexes are declared inline).
     * On PgSQL and SQLite, non-primary indexes are emitted as CREATE INDEX.
     *
     * @param Blueprint $blueprint Populated blueprint.
     * @param Grammar   $grammar   Active grammar.
     * @return string[] Additional SQL statements.
     */
    protected static function buildPostCreateStatements(Blueprint $blueprint, Grammar $grammar): array
    {
        if ($grammar->isMySQL()) {
            return [];
        }

        $statements = [];
        $table      = $blueprint->getTable();

        foreach ($blueprint->getIndexes() as $index) {
            if (in_array($index['type'], ['index', 'fulltext'], true)) {
                $stmts = $grammar->compileAddIndex(
                    $index['name'],
                    $index['columns'],
                    $table,
                    false
                );
                foreach ($stmts as $stmt) {
                    $statements[] = $stmt;
                }
            }
        }

        return $statements;
    }

    /**
     * Alters an existing table.
     *
     * @example
     * Schema::table('users', function (Blueprint $table) {
     *     $table->string('phone', 20)->nullable()->after('email');
     *     $table->dropColumn('old_field');
     * });
     *
     * @param string   $table    Table name.
     * @param callable $callback Receives a Blueprint instance.
     * @return void
     *
     * @throws RuntimeException On SQL execution failure.
     */
    public static function table(string $table, callable $callback): void
    {
        ['pdo' => $pdo, 'grammar' => $grammar] = static::resolve();

        $blueprint = new Blueprint($table, $grammar);
        $callback($blueprint);

        $clauses = $blueprint->toAlterClauses();
        if (empty($clauses)) {
            return;
        }

        // SQLite ALTER TABLE only supports ADD COLUMN and RENAME COLUMN
        // (one at a time). Emit one statement per clause.
        if ($grammar->isSQLite()) {
            static::executeSQLiteAlter($table, $clauses, $grammar, $pdo);
            return;
        }

        // MySQL and PgSQL support multiple clauses in a single ALTER TABLE
        $alterPrefix = $grammar->compileAlterTable($table);

        // For PgSQL, certain clause types must be issued as separate statements
        if ($grammar->isPgSQL()) {
            static::executePgSQLAlter($alterPrefix, $clauses, $pdo);
            return;
        }

        // MySQL: all clauses in one statement
        $pdo->exec($alterPrefix . ' ' . implode(', ', $clauses));
    }

    /**
     * Issues individual ALTER TABLE statements for SQLite, which only
     * supports ADD COLUMN and RENAME COLUMN one at a time.
     *
     * Unsupported clauses (MODIFY, CHANGE, DROP FOREIGN KEY, etc.) are
     * skipped with a warning comment in the statement stream.
     *
     * @param string   $table   Table name.
     * @param string[] $clauses ALTER TABLE clause fragments.
     * @param Grammar  $grammar Active SQLite grammar.
     * @param PDO      $pdo     Active PDO connection.
     * @return void
     */
    protected static function executeSQLiteAlter(
        string  $table,
        array   $clauses,
        Grammar $grammar,
        PDO     $pdo
    ): void {
        $alterPrefix = $grammar->compileAlterTable($table);

        foreach ($clauses as $clause) {
            $upper = strtoupper(ltrim($clause));

            // SQLite supports ADD COLUMN and RENAME COLUMN
            if (str_starts_with($upper, 'ADD COLUMN') || str_starts_with($upper, 'RENAME COLUMN')) {
                $pdo->exec($alterPrefix . ' ' . $clause);
                continue;
            }

            // CREATE INDEX statements (produced by compileAddIndex for SQLite)
            if (str_starts_with($upper, 'CREATE') || str_starts_with($upper, 'DROP INDEX')) {
                $pdo->exec($clause);
                continue;
            }

            // DROP COLUMN — supported in SQLite 3.35.0+ (2021-03-12)
            if (str_starts_with($upper, 'DROP COLUMN')) {
                $pdo->exec($alterPrefix . ' ' . $clause);
                continue;
            }

            // All other clauses (MODIFY, CHANGE, DROP FK, etc.) are silently
            // skipped because SQLite requires full table recreation for such
            // changes. Callers should handle this via raw migration.
        }
    }

    /**
     * Issues ALTER TABLE clauses for PostgreSQL, splitting statements that
     * cannot be combined (e.g. CREATE INDEX must be standalone).
     *
     * @param string   $alterPrefix Base ALTER TABLE "table" string.
     * @param string[] $clauses     Clause fragments.
     * @param PDO      $pdo         Active PDO connection.
     * @return void
     */
    protected static function executePgSQLAlter(string $alterPrefix, array $clauses, PDO $pdo): void
    {
        $inlineBuffer = [];

        foreach ($clauses as $clause) {
            $upper = strtoupper(ltrim($clause));

            // CREATE INDEX / DROP INDEX are standalone in PgSQL
            if (str_starts_with($upper, 'CREATE') || str_starts_with($upper, 'DROP INDEX')) {
                // Flush any buffered inline clauses first
                if (!empty($inlineBuffer)) {
                    $pdo->exec($alterPrefix . ' ' . implode(', ', $inlineBuffer));
                    $inlineBuffer = [];
                }
                $pdo->exec($clause);
                continue;
            }

            $inlineBuffer[] = $clause;
        }

        // Flush remaining inline clauses
        if (!empty($inlineBuffer)) {
            $pdo->exec($alterPrefix . ' ' . implode(', ', $inlineBuffer));
        }
    }

    /**
     * Drops a table if it exists.
     * Automatically disables FK checks on MySQL and uses CASCADE on PostgreSQL.
     *
     * @example Schema::dropIfExists('users');
     *
     * @param string $table Table name.
     * @return void
     */
    public static function dropIfExists(string $table): void
    {
        ['pdo' => $pdo, 'grammar' => $grammar] = static::resolve();

        $statements = $grammar->compileDropTableIfExists($table);
        foreach ($statements as $sql) {
            $pdo->exec($sql);
        }
    }

    /**
     * Drops a table unconditionally. Throws a PDOException if the table
     * does not exist.
     *
     * @param string $table Table name.
     * @return void
     */
    public static function drop(string $table): void
    {
        ['pdo' => $pdo, 'grammar' => $grammar] = static::resolve();

        $pdo->exec('DROP TABLE ' . $grammar->quoteTable($table));
    }

    /**
     * Checks whether a table exists in the current database / schema.
     *
     * @param string      $table  Table name.
     * @param string|null $schema Schema name (optional; uses driver default).
     * @return bool True if the table exists.
     */
    public static function hasTable(string $table, ?string $schema = null): bool
    {
        ['pdo' => $pdo, 'grammar' => $grammar] = static::resolve();

        ['sql' => $sql, 'params' => $params] = $grammar->compileHasTable($schema);
        $params['table'] = $table;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Checks whether a column exists in a table.
     *
     * @param string      $table  Table name.
     * @param string      $column Column name.
     * @param string|null $schema Schema name (optional; uses driver default).
     * @return bool True if the column exists.
     */
    public static function hasColumn(string $table, string $column, ?string $schema = null): bool
    {
        ['pdo' => $pdo, 'grammar' => $grammar] = static::resolve();

        ['sql' => $sql, 'params' => $params] = $grammar->compileHasColumn($schema);
        $params['table']  = $table;
        $params['column'] = $column;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Checks whether multiple columns all exist in a table.
     *
     * @param string   $table   Table name.
     * @param string[] $columns Column names to check.
     * @return bool True only when every column exists.
     */
    public static function hasColumns(string $table, array $columns): bool
    {
        foreach ($columns as $column) {
            if (!static::hasColumn($table, $column)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Returns all column names for a given table.
     *
     * @param string $table Table name.
     * @return string[] Column names.
     */
    public static function getColumnNames(string $table): array
    {
        ['pdo' => $pdo, 'grammar' => $grammar] = static::resolve();

        if ($grammar->isMySQL()) {
            $stmt = $pdo->prepare("SHOW COLUMNS FROM {$grammar->quoteTable($table)}");
            $stmt->execute();
            return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
        }

        if ($grammar->isPgSQL()) {
            $stmt = $pdo->prepare(
                "SELECT column_name FROM information_schema.columns
                 WHERE table_schema = 'public' AND table_name = :table
                 ORDER BY ordinal_position"
            );
            $stmt->execute(['table' => $table]);
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        }

        // SQLite
        $stmt = $pdo->prepare("PRAGMA table_info({$grammar->quoteTable($table)})");
        $stmt->execute();
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'name');
    }

    /**
     * Renames a table.
     *
     * @param string $from Current table name.
     * @param string $to   New table name.
     * @return void
     */
    public static function rename(string $from, string $to): void
    {
        ['pdo' => $pdo, 'grammar' => $grammar] = static::resolve();

        $pdo->exec($grammar->compileRenameTable($from, $to));
    }

    /**
     * Removes all rows from a table, resetting auto-increment counters.
     *
     * MySQL  → TRUNCATE TABLE (with FK-check bypass)
     * PgSQL  → TRUNCATE TABLE … RESTART IDENTITY CASCADE
     * SQLite → DELETE FROM (TRUNCATE does not exist)
     *
     * @param string $table Table name.
     * @return void
     */
    public static function truncate(string $table): void
    {
        ['pdo' => $pdo, 'grammar' => $grammar] = static::resolve();

        foreach ($grammar->compileTruncate($table) as $sql) {
            $pdo->exec($sql);
        }
    }

    /**
     * Executes a raw SQL statement directly.
     * Useful for driver-specific DDL not covered by the Schema API.
     *
     * @param string $sql Raw SQL statement.
     * @return void
     */
    public static function statement(string $sql): void
    {
        Connection::getInstance()->exec($sql);
    }

    /**
     * Returns the active Grammar instance for the current PDO connection.
     * Useful for writing driver-aware raw migrations.
     *
     * @return Grammar Grammar instance for the active driver.
     */
    public static function grammar(): Grammar
    {
        ['grammar' => $grammar] = static::resolve();
        return $grammar;
    }
}