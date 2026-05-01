<?php

/*
|--------------------------------------------------------------------------
| Grammar — SQL Dialect Translator
|--------------------------------------------------------------------------
|
| Centralises every driver-specific SQL difference so that Blueprint,
| Schema, Migrator and Seeder never need inline driver-sniffing.
|
| Supported drivers: mysql | pgsql | sqlite
|
*/

declare(strict_types=1);

namespace Slenix\Database\Migrations;

use InvalidArgumentException;

class Grammar
{

    /** Supported database drivers */
    public const MYSQL  = 'mysql';
    public const PGSQL  = 'pgsql';
    public const SQLITE = 'sqlite';

    /** @var string Active driver identifier */
    protected string $driver;

    /**
     * Creates a Grammar instance for the given PDO driver string.
     *
     * @param string $driver PDO driver name ('mysql', 'pgsql', 'sqlite').
     *
     * @throws InvalidArgumentException When an unsupported driver is supplied.
     */
    public function __construct(string $driver)
    {
        $driver = strtolower(trim($driver));

        if (!in_array($driver, [self::MYSQL, self::PGSQL, self::SQLITE], true)) {
            throw new InvalidArgumentException(
                "Unsupported database driver: '{$driver}'. Supported: mysql, pgsql, sqlite."
            );
        }

        $this->driver = $driver;
    }

    /**
     * Returns the active driver string.
     *
     * @return string e.g. 'mysql', 'pgsql', 'sqlite'
     */
    public function driver(): string
    {
        return $this->driver;
    }

    /** @return bool True when the active driver is MySQL. */
    public function isMySQL(): bool
    {
        return $this->driver === self::MYSQL;
    }

    /** @return bool True when the active driver is PostgreSQL. */
    public function isPgSQL(): bool
    {
        return $this->driver === self::PGSQL;
    }

    /** @return bool True when the active driver is SQLite. */
    public function isSQLite(): bool
    {
        return $this->driver === self::SQLITE;
    }

    /**
     * Wraps an identifier (table or column name) with the correct
     * driver-specific delimiter.
     *
     * MySQL  → `identifier`
     * PgSQL  → "identifier"
     * SQLite → "identifier"
     *
     * @param string $identifier Raw identifier name.
     * @return string Quoted identifier.
     */
    public function quoteIdentifier(string $identifier): string
    {
        return $this->isMySQL()
            ? '`' . str_replace('`', '``', $identifier) . '`'
            : '"' . str_replace('"', '""', $identifier) . '"';
    }

    /**
     * Quotes a table name, handling optional schema prefix (e.g. "public"."users").
     *
     * @param string $table Table name, optionally with schema (schema.table).
     * @return string Fully quoted table expression.
     */
    public function quoteTable(string $table): string
    {
        if (str_contains($table, '.')) {
            [$schema, $name] = explode('.', $table, 2);
            return $this->quoteIdentifier($schema) . '.' . $this->quoteIdentifier($name);
        }

        return $this->quoteIdentifier($table);
    }

    /**
     * Quotes an array of column names and joins them with commas.
     *
     * @param string[] $columns Column names to quote.
     * @return string Comma-separated quoted columns, e.g. `col1`, `col2`.
     */
    public function quoteColumns(array $columns): string
    {
        return implode(', ', array_map([$this, 'quoteIdentifier'], $columns));
    }

    /**
     * Translates a driver-agnostic column type definition (as produced by
     * Blueprint) into the correct SQL fragment for the active driver.
     *
     * This is the single source of truth for type mapping across drivers.
     * It operates via string replacement so it can be applied to the raw
     * definition string stored in Blueprint::$columns.
     *
     * MySQL-specific syntax that must be converted:
     *   - AUTO_INCREMENT PRIMARY KEY  → SERIAL / INTEGER PRIMARY KEY AUTOINCREMENT
     *   - UNSIGNED                    → removed (not supported by PgSQL/SQLite)
     *   - TINYINT(1)                  → BOOLEAN / INTEGER
     *   - TINYINT / SMALLINT          → SMALLINT / INTEGER
     *   - MEDIUMINT                   → INTEGER
     *   - BIGINT                      → BIGINT / INTEGER
     *   - DATETIME                    → TIMESTAMP
     *   - LONGTEXT / MEDIUMTEXT       → TEXT
     *   - ENUM(...)                   → TEXT CHECK(col IN (...))
     *   - SET(...)                    → TEXT
     *   - ENGINE=... / CHARSET=...    → (stripped at CREATE level)
     *   - FLOAT(p,s) / DOUBLE(p,s)   → REAL / FLOAT
     *   - GENERATED ALWAYS AS         → supported in PgSQL 12+, stripped in SQLite
     *   - COMMENT 'text'              → stripped (not supported by PgSQL/SQLite)
     *   - AFTER `col` / FIRST         → stripped (PgSQL/SQLite do not support positioning)
     *
     * @param string $column     Column name (used for ENUM CHECK constraint).
     * @param string $definition Raw MySQL-style column definition string.
     * @return string Translated column definition for the active driver.
     */
    public function translateColumnDefinition(string $column, string $definition): string
    {
        if ($this->isMySQL()) {
            return $definition;
        }

        // ── Strip MySQL-only modifiers ────────────────────────────────────────
        // AFTER `col` / FIRST — positional clauses not supported outside MySQL
        $definition = preg_replace('/\s+AFTER\s+`[^`]+`/i', '', $definition);
        $definition = preg_replace('/\s+FIRST\b/i', '', $definition);

        // COMMENT 'text' — not valid DDL syntax in PgSQL/SQLite (use pg_description)
        $definition = preg_replace("/\\s+COMMENT\\s+'(?:[^'\\\\]|\\\\.)*'/i", '', $definition);

        // UNSIGNED — not a concept in PgSQL/SQLite
        $definition = preg_replace('/\bUNSIGNED\b\s*/i', '', $definition);

        // ── AUTO_INCREMENT PRIMARY KEY → driver serial types ──────────────────
        if ($this->isPgSQL()) {
            // BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY → BIGSERIAL PRIMARY KEY
            $definition = preg_replace(
                '/BIGINT\s+NOT NULL\s+AUTO_INCREMENT\s+PRIMARY KEY/i',
                'BIGSERIAL PRIMARY KEY',
                $definition
            );
            $definition = preg_replace(
                '/INT\s+NOT NULL\s+AUTO_INCREMENT\s+PRIMARY KEY/i',
                'SERIAL PRIMARY KEY',
                $definition
            );
        } elseif ($this->isSQLite()) {
            // SQLite: INTEGER PRIMARY KEY is the special rowid alias
            $definition = preg_replace(
                '/BIGINT\s+NOT NULL\s+AUTO_INCREMENT\s+PRIMARY KEY/i',
                'INTEGER PRIMARY KEY AUTOINCREMENT',
                $definition
            );
            $definition = preg_replace(
                '/INT\s+NOT NULL\s+AUTO_INCREMENT\s+PRIMARY KEY/i',
                'INTEGER PRIMARY KEY AUTOINCREMENT',
                $definition
            );
        }

        // Remove leftover AUTO_INCREMENT (when not part of PK clause)
        $definition = preg_replace('/\bAUTO_INCREMENT\b/i', '', $definition);

        // ── Numeric types ─────────────────────────────────────────────────────
        if ($this->isSQLite()) {
            // SQLite type affinity: everything numeric maps to INTEGER or REAL
            $definition = preg_replace('/\bTINYINT\(1\)/i',  'INTEGER', $definition);
            $definition = preg_replace('/\bTINYINT\b/i',     'INTEGER', $definition);
            $definition = preg_replace('/\bSMALLINT\b/i',    'INTEGER', $definition);
            $definition = preg_replace('/\bMEDIUMINT\b/i',   'INTEGER', $definition);
            $definition = preg_replace('/\bBIGINT\b/i',      'INTEGER', $definition);
            $definition = preg_replace('/\bFLOAT\([^)]+\)/i','REAL',    $definition);
            $definition = preg_replace('/\bDOUBLE\([^)]+\)/i','REAL',   $definition);
            $definition = preg_replace('/\bDECIMAL\([^)]+\)/i','NUMERIC',$definition);
        } elseif ($this->isPgSQL()) {
            $definition = preg_replace('/\bTINYINT\(1\)/i',  'BOOLEAN', $definition);
            $definition = preg_replace('/\bTINYINT\b/i',     'SMALLINT',$definition);
            $definition = preg_replace('/\bMEDIUMINT\b/i',   'INTEGER', $definition);
            $definition = preg_replace('/\bFLOAT\([^)]+\)/i','REAL',    $definition);
            $definition = preg_replace('/\bDOUBLE\([^)]+\)/i','FLOAT8', $definition);
            // BIGINT is valid in PgSQL — keep as-is
        }

        // ── String / Text types ───────────────────────────────────────────────
        if ($this->isSQLite()) {
            $definition = preg_replace('/\bTINYTEXT\b/i',   'TEXT', $definition);
            $definition = preg_replace('/\bMEDIUMTEXT\b/i', 'TEXT', $definition);
            $definition = preg_replace('/\bLONGTEXT\b/i',   'TEXT', $definition);
        } elseif ($this->isPgSQL()) {
            $definition = preg_replace('/\bTINYTEXT\b/i',   'TEXT', $definition);
            $definition = preg_replace('/\bMEDIUMTEXT\b/i', 'TEXT', $definition);
            $definition = preg_replace('/\bLONGTEXT\b/i',   'TEXT', $definition);
        }

        // ── Date/Time types ───────────────────────────────────────────────────
        $definition = preg_replace('/\bDATETIME\b/i', 'TIMESTAMP', $definition);

        if ($this->isSQLite()) {
            // SQLite has no native TIMESTAMP type; TEXT stores ISO-8601 just fine
            $definition = preg_replace('/\bTIMESTAMP\b/i', 'TEXT', $definition);
            $definition = preg_replace('/\bDATE\b/i',      'TEXT', $definition);
            $definition = preg_replace('/\bTIME\b/i',      'TEXT', $definition);
            $definition = preg_replace('/\bYEAR\b/i',      'INTEGER', $definition);
        }

        // ── ENUM → CHECK constraint ───────────────────────────────────────────
        // MySQL: ENUM('a', 'b')  →  TEXT CHECK("col" IN ('a', 'b'))
        if (preg_match('/\bENUM\(([^)]+)\)/i', $definition, $m)) {
            $quotedCol  = $this->quoteIdentifier($column);
            $check      = "TEXT CHECK({$quotedCol} IN ({$m[1]}))";
            $definition = preg_replace('/\bENUM\([^)]+\)/i', $check, $definition);
        }

        // SET is not standard outside MySQL; map to TEXT
        $definition = preg_replace('/\bSET\([^)]+\)/i', 'TEXT', $definition);

        // ── JSON type ─────────────────────────────────────────────────────────
        if ($this->isSQLite()) {
            // SQLite stores JSON as TEXT
            $definition = preg_replace('/\bJSON\b/i', 'TEXT', $definition);
        }
        // PgSQL has native JSON/JSONB — keep as-is

        // ── BINARY type ───────────────────────────────────────────────────────
        if (!$this->isMySQL()) {
            $definition = preg_replace('/\bBINARY\([^)]+\)/i', 'BYTEA', $definition);
        }

        // ── GENERATED columns ─────────────────────────────────────────────────
        if ($this->isSQLite()) {
            // SQLite supports generated columns from 3.31.0 (2020-01-22)
            // but the syntax is slightly different — kept as VIRTUAL/STORED
            // Nothing to replace; SQLite uses the same GENERATED ALWAYS AS syntax
        }
        // PgSQL uses GENERATED ALWAYS AS (expr) STORED (VIRTUAL not supported)
        if ($this->isPgSQL()) {
            $definition = preg_replace(
                '/GENERATED ALWAYS AS \(([^)]+)\)\s+VIRTUAL/i',
                'GENERATED ALWAYS AS ($1) STORED',
                $definition
            );
        }

        // ── DEFAULT CURRENT_TIMESTAMP ─────────────────────────────────────────
        // Already valid in all three drivers; no change needed.

        // ── Clean up extra whitespace ─────────────────────────────────────────
        $definition = preg_replace('/\s{2,}/', ' ', trim($definition));

        return $definition;
    }

    /**
     * Produces the CREATE TABLE SQL preamble for a given table name.
     * The caller is responsible for appending the column/constraint body.
     *
     * MySQL  → CREATE TABLE IF NOT EXISTS `table` (
     * PgSQL  → CREATE TABLE IF NOT EXISTS "table" (
     * SQLite → CREATE TABLE IF NOT EXISTS "table" (
     *
     * @param string $table Table name.
     * @return string Opening DDL fragment including the opening parenthesis.
     */
    public function compileCreateTable(string $table): string
    {
        return 'CREATE TABLE IF NOT EXISTS ' . $this->quoteTable($table) . ' (';
    }

    /**
     * Returns the closing DDL fragment for CREATE TABLE, including
     * engine/charset options where applicable.
     *
     * MySQL  → ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
     * Others → )
     *
     * @param string $charset   MySQL charset (default 'utf8mb4').
     * @param string $collation MySQL collation (default 'utf8mb4_unicode_ci').
     * @return string Closing DDL fragment.
     */
    public function compileCreateTableEnd(
        string $charset   = 'utf8mb4',
        string $collation = 'utf8mb4_unicode_ci'
    ): string {
        if ($this->isMySQL()) {
            return ") ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";
        }

        return ')';
    }

    /**
     * Builds the ALTER TABLE prefix for the active driver.
     *
     * @param string $table Table name.
     * @return string e.g. 'ALTER TABLE `users`'
     */
    public function compileAlterTable(string $table): string
    {
        return 'ALTER TABLE ' . $this->quoteTable($table);
    }

    /**
     * Builds DROP TABLE IF EXISTS for the active driver,
     * optionally wrapping it in FK-disable statements for MySQL.
     *
     * @param string $table      Table name.
     * @param bool   $disableFks Whether to wrap with FK-check disabling (MySQL only).
     * @return string[] SQL statements to execute in order.
     */
    public function compileDropTableIfExists(string $table, bool $disableFks = true): array
    {
        $quoted = $this->quoteTable($table);

        if ($this->isMySQL() && $disableFks) {
            return [
                'SET FOREIGN_KEY_CHECKS = 0',
                "DROP TABLE IF EXISTS {$quoted}",
                'SET FOREIGN_KEY_CHECKS = 1',
            ];
        }

        if ($this->isPgSQL()) {
            return ["DROP TABLE IF EXISTS {$quoted} CASCADE"];
        }

        // SQLite — no FK enforcement by default unless PRAGMA enabled
        return ["DROP TABLE IF EXISTS {$quoted}"];
    }

    /**
     * Produces the RENAME TABLE statement for the active driver.
     *
     * MySQL  → RENAME TABLE `from` TO `to`
     * PgSQL  → ALTER TABLE "from" RENAME TO "to"
     * SQLite → ALTER TABLE "from" RENAME TO "to"
     *
     * @param string $from Current table name.
     * @param string $to   New table name.
     * @return string SQL statement.
     */
    public function compileRenameTable(string $from, string $to): string
    {
        if ($this->isMySQL()) {
            return 'RENAME TABLE ' . $this->quoteTable($from) . ' TO ' . $this->quoteTable($to);
        }

        return $this->compileAlterTable($from) . ' RENAME TO ' . $this->quoteIdentifier($to);
    }

    /**
     * Produces the TRUNCATE statement for the active driver.
     *
     * MySQL  → TRUNCATE TABLE `table`
     * PgSQL  → TRUNCATE TABLE "table" RESTART IDENTITY CASCADE
     * SQLite → DELETE FROM "table" (SQLite has no TRUNCATE)
     *
     * @param string $table Table name.
     * @return string[] SQL statements to execute in order.
     */
    public function compileTruncate(string $table): array
    {
        $quoted = $this->quoteTable($table);

        if ($this->isMySQL()) {
            return [
                'SET FOREIGN_KEY_CHECKS = 0',
                "TRUNCATE TABLE {$quoted}",
                'SET FOREIGN_KEY_CHECKS = 1',
            ];
        }

        if ($this->isPgSQL()) {
            return ["TRUNCATE TABLE {$quoted} RESTART IDENTITY CASCADE"];
        }

        // SQLite: TRUNCATE does not exist; DELETE is functionally equivalent
        return ["DELETE FROM {$quoted}"];
    }

    /**
     * Produces the ADD INDEX clause for an ALTER TABLE statement.
     *
     * SQLite does not support ADD INDEX inside ALTER TABLE;
     * a standalone CREATE INDEX must be used instead.
     *
     * @param string   $name    Index name.
     * @param string[] $columns Columns to index.
     * @param string   $table   Table name (needed for SQLite CREATE INDEX).
     * @param bool     $unique  Whether this is a UNIQUE index.
     * @return string[] One or more SQL statements.
     */
    public function compileAddIndex(
        string $name,
        array|string  $columns,
        string $table,
        bool   $unique = false
    ): array {
        $uniqueWord = $unique ? 'UNIQUE ' : '';
        $cols       = $this->quoteColumns($columns);

        if ($this->isSQLite()) {
            // SQLite: standalone statement
            return [
                "CREATE {$uniqueWord}INDEX IF NOT EXISTS "
                . $this->quoteIdentifier($name)
                . ' ON ' . $this->quoteTable($table) . " ({$cols})"
            ];
        }

        // MySQL / PgSQL: inline ALTER TABLE clause (caller joins with ', ')
        $keyword = $unique ? 'UNIQUE' : 'INDEX';
        return [
            "ADD {$keyword} " . $this->quoteIdentifier($name) . " ({$cols})"
        ];
    }

    /**
     * Produces the DROP INDEX clause.
     *
     * MySQL  → DROP INDEX `name` (inside ALTER TABLE)
     * PgSQL  → DROP INDEX "name" (standalone statement)
     * SQLite → DROP INDEX "name" (standalone statement)
     *
     * @param string $name  Index name.
     * @param string $table Table name (MySQL only, for ALTER TABLE context).
     * @return string[] SQL statements.
     */
    public function compileDropIndex(string $name, string $table): array
    {
        $quotedName = $this->quoteIdentifier($name);

        if ($this->isMySQL()) {
            // Inside ALTER TABLE context
            return ["DROP INDEX {$quotedName}"];
        }

        return ["DROP INDEX IF EXISTS {$quotedName}"];
    }

    /**
     * Produces the DROP FOREIGN KEY clause.
     *
     * MySQL  → DROP FOREIGN KEY `name` (inside ALTER TABLE)
     * PgSQL  → DROP CONSTRAINT "name" (inside ALTER TABLE)
     * SQLite → not supported (returns empty array)
     *
     * @param string $name Constraint name.
     * @return string[] SQL fragments for ALTER TABLE.
     */
    public function compileDropForeignKey(string $name): array
    {
        if ($this->isSQLite()) {
            // SQLite does not support dropping FK constraints
            return [];
        }

        $quotedName = $this->quoteIdentifier($name);

        if ($this->isMySQL()) {
            return ["DROP FOREIGN KEY {$quotedName}"];
        }

        // PgSQL
        return ["DROP CONSTRAINT {$quotedName}"];
    }

    /**
     * Produces the DROP PRIMARY KEY clause.
     *
     * MySQL  → DROP PRIMARY KEY
     * PgSQL  → DROP CONSTRAINT "table_pkey"
     * SQLite → not supported (returns empty array; requires table recreation)
     *
     * @param string $table Table name (PgSQL needs it to infer constraint name).
     * @return string[] SQL fragments for ALTER TABLE.
     */
    public function compileDropPrimary(string $table): array
    {
        if ($this->isSQLite()) {
            return [];
        }

        if ($this->isMySQL()) {
            return ['DROP PRIMARY KEY'];
        }

        // PgSQL default PK constraint name: {table}_pkey
        return ['DROP CONSTRAINT ' . $this->quoteIdentifier($table . '_pkey')];
    }

    /**
     * Returns the CREATE TABLE statement for the migrations tracking table.
     * Adapts syntax to the active driver.
     *
     * @return string Complete CREATE TABLE SQL.
     */
    public function compileMigrationsTable(): string
    {
        $table = $this->quoteTable('migrations');

        if ($this->isMySQL()) {
            return <<<SQL
            CREATE TABLE IF NOT EXISTS {$table} (
                `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `migration`   VARCHAR(255) NOT NULL,
                `batch`       INT NOT NULL DEFAULT 1,
                `executed_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL;
        }

        if ($this->isPgSQL()) {
            return <<<SQL
            CREATE TABLE IF NOT EXISTS {$table} (
                "id"          SERIAL PRIMARY KEY,
                "migration"   VARCHAR(255) NOT NULL,
                "batch"       INTEGER NOT NULL DEFAULT 1,
                "executed_at" TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
            SQL;
        }

        // SQLite
        return <<<SQL
        CREATE TABLE IF NOT EXISTS {$table} (
            "id"          INTEGER PRIMARY KEY AUTOINCREMENT,
            "migration"   TEXT NOT NULL,
            "batch"       INTEGER NOT NULL DEFAULT 1,
            "executed_at" TEXT DEFAULT (datetime('now'))
        )
        SQL;
    }

    /**
     * Returns a parameterised SQL query that returns 1 row when the given
     * table exists, 0 rows when it does not.
     *
     * Bind params: [:table] and optionally [:schema].
     *
     * @param string|null $schema Schema/database name for MySQL/PgSQL.
     *                            Pass null to use DATABASE() for MySQL or 'public' for PgSQL.
     * @return array{sql: string, params: array} Query and its bind parameters.
     */
    public function compileHasTable(?string $schema = null): array
    {
        if ($this->isMySQL()) {
            return [
                'sql'    => "SELECT COUNT(*) FROM information_schema.tables
                             WHERE table_schema = DATABASE() AND table_name = :table",
                'params' => [],
            ];
        }

        if ($this->isPgSQL()) {
            $schema ??= 'public';
            return [
                'sql'    => "SELECT COUNT(*) FROM information_schema.tables
                             WHERE table_schema = :schema AND table_name = :table",
                'params' => ['schema' => $schema],
            ];
        }

        // SQLite: uses sqlite_master
        return [
            'sql'    => "SELECT COUNT(*) FROM sqlite_master
                         WHERE type = 'table' AND name = :table",
            'params' => [],
        ];
    }

    /**
     * Returns a parameterised SQL query that returns 1 row when the given
     * column exists in the table, 0 rows otherwise.
     *
     * Bind params: [:table, :column] and optionally [:schema].
     *
     * @param string|null $schema Schema/database name.
     * @return array{sql: string, params: array} Query and its bind parameters.
     */
    public function compileHasColumn(?string $schema = null): array
    {
        if ($this->isMySQL()) {
            return [
                'sql'    => "SELECT COUNT(*) FROM information_schema.columns
                             WHERE table_schema = DATABASE()
                               AND table_name   = :table
                               AND column_name  = :column",
                'params' => [],
            ];
        }

        if ($this->isPgSQL()) {
            $schema ??= 'public';
            return [
                'sql'    => "SELECT COUNT(*) FROM information_schema.columns
                             WHERE table_schema = :schema
                               AND table_name   = :table
                               AND column_name  = :column",
                'params' => ['schema' => $schema],
            ];
        }

        // SQLite: parse PRAGMA table_info
        return [
            'sql'    => "SELECT COUNT(*) FROM pragma_table_info(:table)
                         WHERE name = :column",
            'params' => [],
        ];
    }

    /**
     * Returns the INSERT OR IGNORE syntax for the active driver.
     *
     * MySQL  → INSERT IGNORE INTO `table`
     * PgSQL  → INSERT INTO "table" ... ON CONFLICT DO NOTHING
     * SQLite → INSERT OR IGNORE INTO "table"
     *
     * @param string   $table   Table name.
     * @param string[] $columns Column names.
     * @param string   $holders Placeholder string (e.g. ':col1, :col2').
     * @return string Complete INSERT statement (without ON CONFLICT for PgSQL,
     *                which is appended by the method).
     */
    public function compileInsertOrIgnore(string $table, array $columns, string $holders): string
    {
        $quotedTable = $this->quoteTable($table);
        $quotedCols  = $this->quoteColumns($columns);

        if ($this->isMySQL()) {
            return "INSERT IGNORE INTO {$quotedTable} ({$quotedCols}) VALUES ({$holders})";
        }

        if ($this->isSQLite()) {
            return "INSERT OR IGNORE INTO {$quotedTable} ({$quotedCols}) VALUES ({$holders})";
        }

        // PgSQL
        return "INSERT INTO {$quotedTable} ({$quotedCols}) VALUES ({$holders}) ON CONFLICT DO NOTHING";
    }

    /**
     * Compiles the RENAME COLUMN clause for ALTER TABLE.
     *
     * MySQL 8+, PgSQL, SQLite 3.25+ all support:
     *   RENAME COLUMN `old` TO `new`
     *
     * @param string $from Old column name.
     * @param string $to   New column name.
     * @return string ALTER TABLE clause.
     */
    public function compileRenameColumn(string $from, string $to): string
    {
        $fromQ = $this->quoteIdentifier($from);
        $toQ   = $this->quoteIdentifier($to);
        return "RENAME COLUMN {$fromQ} TO {$toQ}";
    }

    /**
     * Compiles the MODIFY / ALTER COLUMN clause.
     *
     * MySQL  → MODIFY COLUMN `col` definition
     * PgSQL  → multiple ALTER COLUMN sub-clauses (type, NOT NULL, DEFAULT)
     * SQLite → not supported natively (returns empty string; caller must skip)
     *
     * @param string $column     Column name.
     * @param string $definition New column definition (MySQL style; will be translated).
     * @return string ALTER TABLE clause, or empty string when unsupported.
     */
    public function compileModifyColumn(string $column, string $definition): string
    {
        $translated = $this->translateColumnDefinition($column, $definition);
        $quotedCol  = $this->quoteIdentifier($column);

        if ($this->isMySQL()) {
            return "MODIFY COLUMN {$quotedCol} {$translated}";
        }

        if ($this->isSQLite()) {
            // SQLite does not support column type changes; caller should warn.
            return '';
        }

        // PgSQL: derive TYPE and NULL separately
        // This is a best-effort single TYPE change; complex transforms need
        // manual migrations.
        $typeMatch = preg_match('/^([A-Z][A-Z0-9\s\(\),]*?)(?:\s+NOT NULL|\s+NULL|\s+DEFAULT|\s+CHECK|\s*$)/i', $translated, $m);
        $typePart  = $typeMatch ? trim($m[1]) : $translated;

        $clauses = ["ALTER COLUMN {$quotedCol} TYPE {$typePart} USING {$quotedCol}::{$typePart}"];

        if (stripos($translated, 'NOT NULL') !== false) {
            $clauses[] = "ALTER COLUMN {$quotedCol} SET NOT NULL";
        } elseif (stripos($translated, ' NULL') !== false) {
            $clauses[] = "ALTER COLUMN {$quotedCol} DROP NOT NULL";
        }

        if (preg_match('/DEFAULT\s+(.+?)(?:\s+CHECK|\s*$)/i', $translated, $dm)) {
            $clauses[] = "ALTER COLUMN {$quotedCol} SET DEFAULT {$dm[1]}";
        }

        return implode(', ', $clauses);
    }

    /**
     * Compiles the CHANGE COLUMN clause (rename + retype in one step).
     *
     * MySQL  → CHANGE COLUMN `old` `new` definition
     * PgSQL  → RENAME COLUMN + TYPE in separate ALTER clauses
     * SQLite → not supported (returns empty string)
     *
     * @param string $from       Old column name.
     * @param string $to         New column name.
     * @param string $definition New MySQL-style column definition.
     * @return string ALTER TABLE clause(s), or empty string when unsupported.
     */
    public function compileChangeColumn(string $from, string $to, string $definition): string
    {
        if ($this->isSQLite()) {
            return '';
        }

        $translated = $this->translateColumnDefinition($to, $definition);
        $fromQ      = $this->quoteIdentifier($from);
        $toQ        = $this->quoteIdentifier($to);

        if ($this->isMySQL()) {
            return "CHANGE COLUMN {$fromQ} {$toQ} {$translated}";
        }

        // PgSQL: rename first, then change type (2 separate clauses joined by comma)
        return "RENAME COLUMN {$fromQ} TO {$toQ}, ALTER COLUMN {$toQ} TYPE {$translated}";
    }
}