<?php

/*
|--------------------------------------------------------------------------
| Blueprint — Driver-Agnostic Table Builder
|--------------------------------------------------------------------------
|
| Defines the structure of a database table through a fluent API.
| Column definitions are stored in a normalised, MySQL-style intermediate
| representation. The Grammar class is responsible for translating those
| definitions into the SQL dialect required by the active driver at
| execution time (MySQL, PostgreSQL, SQLite).
|
| The public contract (toCreateSql / toAlterClauses) is driver-aware
| when a Grammar instance is injected; otherwise it falls back to raw
| MySQL syntax for backwards compatibility.
|
*/

declare(strict_types=1);

namespace Slenix\Database\Migrations;

class Blueprint
{

    /** @var string Table name this blueprint targets. */
    protected string $table;

    /**
     * @var array<string, string> Column definitions keyed by column name.
     *
     * Special key prefixes (generated internally, never rendered as ADD COLUMN):
     *   __drop__{col}    → DROP COLUMN clause
     *   __rename__{col}  → RENAME COLUMN clause
     *   __modify__{col}  → MODIFY / ALTER COLUMN clause
     *   __change__{col}  → CHANGE COLUMN clause (rename + retype)
     */
    protected array $columns = [];

    /** @var string[] Inline index definitions (KEY, UNIQUE KEY, FULLTEXT, PRIMARY). */
    protected array $indexes = [];

    /**
     * @var array<string, array{
     *   column: string,
     *   references: string,
     *   on: string,
     *   onDelete: string,
     *   onUpdate: string
     * }> Foreign key constraints keyed by constraint name.
     */
    protected array $foreignKeys = [];

    /** @var string[] Drop clauses (DROP INDEX, DROP FOREIGN KEY, DROP PRIMARY KEY). */
    protected array $dropClauses = [];

    /** @var string|null Name of the most recently defined column (modifier target). */
    protected ?string $lastColumn = null;

    /** @var Grammar|null Optional grammar instance injected by Schema::create/table. */
    protected ?Grammar $grammar = null;

    // =========================================================
    // CONSTRUCTOR
    // =========================================================

    /**
     * @param string       $table   Target table name.
     * @param Grammar|null $grammar Optional grammar; when null, raw MySQL is produced.
     */
    public function __construct(string $table, ?Grammar $grammar = null)
    {
        $this->table   = $table;
        $this->grammar = $grammar;
    }

    /**
     * Injects a Grammar instance after construction.
     * Called by Schema before invoking the user callback.
     *
     * @param Grammar $grammar Grammar for the active PDO driver.
     * @return static Fluent.
     */
    public function setGrammar(Grammar $grammar): static
    {
        $this->grammar = $grammar;
        return $this;
    }

    /**
     * Returns the active Grammar, or a MySQL Grammar as default.
     *
     * @return Grammar
     */
    protected function grammar(): Grammar
    {
        return $this->grammar ?? new Grammar('mysql');
    }

    /**
     * Adds an auto-incrementing unsigned BIGINT primary key column.
     *
     * @param string $column Column name (default 'id').
     * @return static Fluent.
     */
    public function id(string $column = 'id'): static
    {
        $this->columns[$column] = 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * Adds a TINYINT column.
     *
     * @param string $column   Column name.
     * @param bool   $unsigned Whether to mark the column as unsigned (MySQL only).
     * @return static Fluent.
     */
    public function tinyInteger(string $column, bool $unsigned = false): static
    {
        $this->columns[$column] = 'TINYINT' . ($unsigned ? ' UNSIGNED' : '') . ' NOT NULL';
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * Adds a SMALLINT column.
     *
     * @param string $column   Column name.
     * @param bool   $unsigned Whether to mark the column as unsigned (MySQL only).
     * @return static Fluent.
     */
    public function smallInteger(string $column, bool $unsigned = false): static
    {
        $this->columns[$column] = 'SMALLINT' . ($unsigned ? ' UNSIGNED' : '') . ' NOT NULL';
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * Adds an INT column.
     *
     * @param string $column   Column name.
     * @param bool   $unsigned Whether to mark the column as unsigned (MySQL only).
     * @return static Fluent.
     */
    public function integer(string $column, bool $unsigned = false): static
    {
        $this->columns[$column] = 'INT' . ($unsigned ? ' UNSIGNED' : '') . ' NOT NULL';
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * Adds a BIGINT column.
     *
     * @param string $column   Column name.
     * @param bool   $unsigned Whether to mark the column as unsigned (MySQL only).
     * @return static Fluent.
     */
    public function bigInteger(string $column, bool $unsigned = false): static
    {
        $this->columns[$column] = 'BIGINT' . ($unsigned ? ' UNSIGNED' : '') . ' NOT NULL';
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * Adds a FLOAT column.
     *
     * @param string $column Column name.
     * @param int    $total  Total digits.
     * @param int    $places Decimal places.
     * @return static Fluent.
     */
    public function float(string $column, int $total = 8, int $places = 2): static
    {
        $this->columns[$column] = "FLOAT({$total}, {$places}) NOT NULL";
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * Adds a DOUBLE column.
     *
     * @param string $column Column name.
     * @param int    $total  Total digits.
     * @param int    $places Decimal places.
     * @return static Fluent.
     */
    public function double(string $column, int $total = 15, int $places = 8): static
    {
        $this->columns[$column] = "DOUBLE({$total}, {$places}) NOT NULL";
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * Adds a DECIMAL column.
     *
     * @param string $column Column name.
     * @param int    $total  Total digits.
     * @param int    $places Decimal places.
     * @return static Fluent.
     */
    public function decimal(string $column, int $total = 10, int $places = 2): static
    {
        $this->columns[$column] = "DECIMAL({$total}, {$places}) NOT NULL";
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * Adds a BOOLEAN column (TINYINT(1) in MySQL).
     *
     * @param string $column Column name.
     * @return static Fluent.
     */
    public function boolean(string $column): static
    {
        $this->columns[$column] = 'TINYINT(1) NOT NULL';
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * Adds a CHAR column with a fixed length.
     *
     * @param string $column Column name.
     * @param int    $length Fixed character length.
     * @return static Fluent.
     */
    public function char(string $column, int $length = 1): static
    {
        $this->columns[$column] = "CHAR({$length}) NOT NULL";
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * Adds a VARCHAR column.
     *
     * @param string $column Column name.
     * @param int    $length Maximum character length.
     * @return static Fluent.
     */
    public function string(string $column, int $length = 255): static
    {
        $this->columns[$column] = "VARCHAR({$length}) NOT NULL";
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * Adds a TINYTEXT column (mapped to TEXT on PgSQL / SQLite).
     *
     * @param string $column Column name.
     * @return static Fluent.
     */
    public function tinyText(string $column): static
    {
        $this->columns[$column] = 'TINYTEXT NOT NULL';
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * Adds a TEXT column.
     *
     * @param string $column Column name.
     * @return static Fluent.
     */
    public function text(string $column): static
    {
        $this->columns[$column] = 'TEXT NOT NULL';
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * Adds a MEDIUMTEXT column (mapped to TEXT on PgSQL / SQLite).
     *
     * @param string $column Column name.
     * @return static Fluent.
     */
    public function mediumText(string $column): static
    {
        $this->columns[$column] = 'MEDIUMTEXT NOT NULL';
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * Adds a LONGTEXT column (mapped to TEXT on PgSQL / SQLite).
     *
     * @param string $column Column name.
     * @return static Fluent.
     */
    public function longText(string $column): static
    {
        $this->columns[$column] = 'LONGTEXT NOT NULL';
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * Adds a JSON column (TEXT on SQLite, JSONB on PgSQL if preferred).
     *
     * @param string $column Column name.
     * @return static Fluent.
     */
    public function json(string $column): static
    {
        $this->columns[$column] = 'JSON NOT NULL';
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * Adds an ENUM column.
     * On PgSQL / SQLite it is translated to TEXT + CHECK constraint.
     *
     * @param string   $column Column name.
     * @param string[] $values Allowed values.
     * @return static Fluent.
     */
    public function enum(string $column, array $values): static
    {
        $list = implode(', ', array_map(fn($v) => "'{$v}'", $values));
        $this->columns[$column] = "ENUM({$list}) NOT NULL";
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * Adds a SET column (MySQL-only; mapped to TEXT on other drivers).
     *
     * @param string   $column Column name.
     * @param string[] $values Allowed set members.
     * @return static Fluent.
     */
    public function set(string $column, array $values): static
    {
        $list = implode(', ', array_map(fn($v) => "'{$v}'", $values));
        $this->columns[$column] = "SET({$list}) NOT NULL";
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * Adds a DATE column.
     *
     * @param string $column Column name.
     * @return static Fluent.
     */
    public function date(string $column): static
    {
        $this->columns[$column] = 'DATE NOT NULL';
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * Adds a TIME column.
     *
     * @param string $column Column name.
     * @return static Fluent.
     */
    public function time(string $column): static
    {
        $this->columns[$column] = 'TIME NOT NULL';
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * Adds a DATETIME column (mapped to TIMESTAMP on PgSQL / SQLite).
     *
     * @param string $column Column name.
     * @return static Fluent.
     */
    public function dateTime(string $column): static
    {
        $this->columns[$column] = 'DATETIME NOT NULL';
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * Adds a TIMESTAMP column that defaults to NULL.
     *
     * @param string $column Column name.
     * @return static Fluent.
     */
    public function timestamp(string $column): static
    {
        $this->columns[$column] = 'TIMESTAMP NULL DEFAULT NULL';
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * Adds a YEAR column (INT on PgSQL / SQLite).
     *
     * @param string $column Column name.
     * @return static Fluent.
     */
    public function year(string $column): static
    {
        $this->columns[$column] = 'YEAR NOT NULL';
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * Adds `created_at` and `updated_at` TIMESTAMP columns (both nullable).
     *
     * @return static Fluent.
     */
    public function timestamps(): static
    {
        $this->columns['created_at'] = 'TIMESTAMP NULL DEFAULT NULL';
        $this->columns['updated_at'] = 'TIMESTAMP NULL DEFAULT NULL';
        return $this;
    }

    /**
     * Adds a soft-delete TIMESTAMP column, defaulting to NULL.
     *
     * @param string $column Column name (default 'deleted_at').
     * @return static Fluent.
     */
    public function softDeletes(string $column = 'deleted_at'): static
    {
        $this->columns[$column] = 'TIMESTAMP NULL DEFAULT NULL';
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * Adds a BINARY column (BYTEA on PgSQL / SQLite).
     *
     * @param string $column Column name.
     * @param int    $length Binary length (MySQL only).
     * @return static Fluent.
     */
    public function binary(string $column, int $length = 255): static
    {
        $this->columns[$column] = "BINARY({$length}) NOT NULL";
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * Adds a UUID column as VARCHAR(36).
     *
     * @param string $column Column name (default 'uuid').
     * @return static Fluent.
     */
    public function uuid(string $column = 'uuid'): static
    {
        $this->columns[$column] = 'VARCHAR(36) NOT NULL';
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * Adds an unsigned BIGINT foreign key column (NOT NULL).
     *
     * @param string $column Column name (conventionally `{relation}_id`).
     * @return static Fluent — chain with ->constrained() to add the FK constraint.
     */
    public function foreignId(string $column): static
    {
        $this->columns[$column] = 'BIGINT UNSIGNED NOT NULL';
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * Adds a nullable unsigned BIGINT foreign key column.
     *
     * @param string $column Column name.
     * @return static Fluent — chain with ->constrained()->nullOnDelete().
     */
    public function foreignIdNullable(string $column): static
    {
        $this->columns[$column] = 'BIGINT UNSIGNED NULL DEFAULT NULL';
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * Adds an IPv4/IPv6 address column (VARCHAR(45)).
     *
     * @param string $column Column name (default 'ip_address').
     * @return static Fluent.
     */
    public function ipAddress(string $column = 'ip_address'): static
    {
        $this->columns[$column] = 'VARCHAR(45) NOT NULL';
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * Adds a MAC address column (VARCHAR(17)).
     *
     * @param string $column Column name (default 'mac_address').
     * @return static Fluent.
     */
    public function macAddress(string $column = 'mac_address'): static
    {
        $this->columns[$column] = 'VARCHAR(17) NOT NULL';
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * Adds a generated / computed column.
     *
     * MySQL / PgSQL 12+ support stored generated columns.
     * SQLite 3.31+ supports both VIRTUAL and STORED.
     *
     * @param string $column     Column name.
     * @param string $expression SQL expression.
     * @param string $type       'VIRTUAL' or 'STORED'.
     * @return static Fluent.
     */
    public function generated(string $column, string $expression, string $type = 'VIRTUAL'): static
    {
        $type = strtoupper($type) === 'STORED' ? 'STORED' : 'VIRTUAL';
        $this->columns[$column] = "VARCHAR(255) GENERATED ALWAYS AS ({$expression}) {$type}";
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * Marks the last column as nullable.
     *
     * @return static Fluent.
     */
    public function nullable(): static
    {
        if ($this->lastColumn && isset($this->columns[$this->lastColumn])) {
            $def = $this->columns[$this->lastColumn];
            // Replace NOT NULL → NULL; if NOT NULL is absent, append NULL
            if (str_contains($def, 'NOT NULL')) {
                $def = str_replace('NOT NULL', 'NULL', $def);
            } elseif (!str_contains($def, 'NULL')) {
                $def .= ' NULL';
            }
            $this->columns[$this->lastColumn] = $def;
        }
        return $this;
    }

    /**
     * Sets a DEFAULT value on the last column.
     *
     * @param mixed $value Scalar value, null, or a raw SQL expression string.
     * @return static Fluent.
     */
    public function default(mixed $value): static
    {
        if ($this->lastColumn && isset($this->columns[$this->lastColumn])) {
            $default = match (true) {
                is_bool($value)  => $value ? '1' : '0',
                is_null($value)  => 'NULL',
                is_numeric($value) => (string) $value,
                default          => "'{$value}'",
            };
            $this->columns[$this->lastColumn] .= " DEFAULT {$default}";
        }
        return $this;
    }

    /**
     * Adds a COMMENT to the last column.
     * Silently ignored on PgSQL / SQLite (comment not part of DDL there).
     *
     * @param string $text Comment text.
     * @return static Fluent.
     */
    public function comment(string $text): static
    {
        if ($this->lastColumn && isset($this->columns[$this->lastColumn])) {
            // Store the raw COMMENT clause; Grammar::translateColumnDefinition strips
            // it for non-MySQL drivers.
            $this->columns[$this->lastColumn] .= " COMMENT '" . addslashes($text) . "'";
        }
        return $this;
    }

    /**
     * Positions the last column AFTER another column (MySQL only).
     * Silently ignored on PgSQL / SQLite.
     *
     * @param string $column Column to place this one after.
     * @return static Fluent.
     */
    public function after(string $column): static
    {
        if ($this->lastColumn && isset($this->columns[$this->lastColumn])) {
            $this->columns[$this->lastColumn] .= " AFTER `{$column}`";
        }
        return $this;
    }

    /**
     * Places the last column as the first in the table (MySQL only).
     * Silently ignored on PgSQL / SQLite.
     *
     * @return static Fluent.
     */
    public function first(): static
    {
        if ($this->lastColumn && isset($this->columns[$this->lastColumn])) {
            $this->columns[$this->lastColumn] .= ' FIRST';
        }
        return $this;
    }

    /**
     * Adds the UNSIGNED modifier to the last column (MySQL only).
     * Silently ignored on PgSQL / SQLite.
     *
     * @return static Fluent.
     */
    public function unsigned(): static
    {
        if ($this->lastColumn && isset($this->columns[$this->lastColumn])) {
            $this->columns[$this->lastColumn] = preg_replace(
                '/^(\w+(?:\([^)]*\))?)/',
                '$1 UNSIGNED',
                $this->columns[$this->lastColumn]
            );
        }
        return $this;
    }

    /**
     * Marks the last column as the PRIMARY KEY (inline, without named constraint).
     *
     * @return static Fluent.
     */
    public function primary(): static
    {
        if ($this->lastColumn) {
            $this->addPrimary([$this->lastColumn]);
        }
        return $this;
    }

    /**
     * Modifies an existing column's full definition.
     *
     * MySQL  → MODIFY COLUMN
     * PgSQL  → ALTER COLUMN TYPE (+ SET NOT NULL / DROP NOT NULL)
     * SQLite → ignored (no native support for column type changes)
     *
     * @param string $column     Column name to modify.
     * @param string $definition New MySQL-style column definition.
     * @return static Fluent.
     */
    public function modifyColumn(string $column, string $definition): static
    {
        $this->columns["__modify__{$column}"] = $definition;
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * Renames a column and changes its definition in one step.
     *
     * MySQL  → CHANGE COLUMN `old` `new` definition
     * PgSQL  → RENAME COLUMN + ALTER COLUMN TYPE (two sub-clauses)
     * SQLite → ignored (no native support)
     *
     * @param string $from       Current column name.
     * @param string $to         New column name.
     * @param string $definition New MySQL-style column definition.
     * @return static Fluent.
     */
    public function changeColumn(string $from, string $to, string $definition): static
    {
        $this->columns["__change__{$from}"] = ['to' => $to, 'definition' => $definition];
        return $this;
    }

    /**
     * Adds a UNIQUE index on one or more columns.
     *
     * @param array|string|null $columns Columns to index (null = last column).
     * @param string|null       $name    Custom index name.
     * @return static Fluent.
     */
    public function unique(array|string|null $columns = null, ?string $name = null): static
    {
        if ($columns === null) {
            $columns = [$this->lastColumn];
        }
        $cols      = (array) $columns;
        $indexName = $name ?? $this->table . '_' . implode('_', $cols) . '_unique';
        $this->indexes[] = ['type' => 'unique', 'name' => $indexName, 'columns' => $cols];
        return $this;
    }

    /**
     * Adds a regular (non-unique) index on one or more columns.
     *
     * @param array|string $columns Columns to index.
     * @param string|null  $name    Custom index name.
     * @return static Fluent.
     */
    public function index(array|string $columns, ?string $name = null): static
    {
        $cols      = (array) $columns;
        $indexName = $name ?? $this->table . '_' . implode('_', $cols) . '_index';
        $this->indexes[] = ['type' => 'index', 'name' => $indexName, 'columns' => $cols];
        return $this;
    }

    /**
     * Adds a FULLTEXT index (MySQL only; ignored on PgSQL / SQLite).
     *
     * @param array|string $columns Columns to index.
     * @param string|null  $name    Custom index name.
     * @return static Fluent.
     */
    public function fullText(array|string $columns, ?string $name = null): static
    {
        $cols      = (array) $columns;
        $indexName = $name ?? $this->table . '_' . implode('_', $cols) . '_fulltext';
        $this->indexes[] = ['type' => 'fulltext', 'name' => $indexName, 'columns' => $cols];
        return $this;
    }

    /**
     * Adds a PRIMARY KEY constraint on the given columns.
     *
     * @param string[] $columns Column names forming the primary key.
     * @return static Fluent.
     */
    public function addPrimary(array $columns): static
    {
        $this->indexes[] = ['type' => 'primary', 'columns' => $columns];
        return $this;
    }

    /**
     * Drops an index by name or column list.
     *
     * @param string|string[] $nameOrColumns Index name or array of columns.
     * @return static Fluent.
     */
    public function dropIndex(string|array $nameOrColumns): static
    {
        $name = is_array($nameOrColumns)
            ? $this->table . '_' . implode('_', $nameOrColumns) . '_index'
            : $nameOrColumns;
        $this->dropClauses[] = ['type' => 'index', 'name' => $name];
        return $this;
    }

    /**
     * Drops a UNIQUE index by name or column list.
     *
     * @param string|string[] $nameOrColumns Index name or array of columns.
     * @return static Fluent.
     */
    public function dropUnique(string|array $nameOrColumns): static
    {
        $name = is_array($nameOrColumns)
            ? $this->table . '_' . implode('_', $nameOrColumns) . '_unique'
            : $nameOrColumns;
        $this->dropClauses[] = ['type' => 'index', 'name' => $name];
        return $this;
    }

    /**
     * Drops the PRIMARY KEY constraint from the table.
     *
     * @return static Fluent.
     */
    public function dropPrimary(): static
    {
        $this->dropClauses[] = ['type' => 'primary'];
        return $this;
    }

    /**
     * Adds a FOREIGN KEY constraint on the last `foreignId` column,
     * automatically inferring the referenced table name from the column.
     *
     * @param string|null $referencedTable    Explicit table name (optional).
     * @param string      $referencedColumn   Referenced column (default 'id').
     * @return static Fluent — chain with ->cascadeOnDelete() etc.
     */
    public function constrained(?string $referencedTable = null, string $referencedColumn = 'id'): static
    {
        if (!$this->lastColumn) {
            return $this;
        }

        $column = $this->lastColumn;

        if ($referencedTable === null) {
            // Infer: user_id → users, category_id → categories
            $referencedTable = rtrim(str_replace('_id', '', $column), '_') . 's';
        }

        $constraintName = "fk_{$this->table}_{$column}";
        $this->foreignKeys[$constraintName] = [
            'column'     => $column,
            'references' => $referencedColumn,
            'on'         => $referencedTable,
            'onDelete'   => 'RESTRICT',
            'onUpdate'   => 'RESTRICT',
        ];

        return $this;
    }

    /**
     * Sets CASCADE on delete for the last defined foreign key.
     *
     * @return static Fluent.
     */
    public function cascadeOnDelete(): static
    {
        return $this->onDelete('CASCADE');
    }

    /**
     * Sets SET NULL on delete for the last defined foreign key.
     * The referencing column must be nullable.
     *
     * @return static Fluent.
     */
    public function nullOnDelete(): static
    {
        return $this->onDelete('SET NULL');
    }

    /**
     * Sets RESTRICT on delete for the last defined foreign key (default).
     *
     * @return static Fluent.
     */
    public function restrictOnDelete(): static
    {
        return $this->onDelete('RESTRICT');
    }

    /**
     * Sets NO ACTION on delete for the last defined foreign key.
     *
     * @return static Fluent.
     */
    public function noActionOnDelete(): static
    {
        return $this->onDelete('NO ACTION');
    }

    /**
     * Sets CASCADE on update for the last defined foreign key.
     *
     * @return static Fluent.
     */
    public function cascadeOnUpdate(): static
    {
        return $this->onUpdate('CASCADE');
    }

    /**
     * Sets a custom ON DELETE action on the last defined foreign key.
     *
     * @param string $action CASCADE | SET NULL | RESTRICT | NO ACTION.
     * @return static Fluent.
     */
    public function onDelete(string $action): static
    {
        $last = array_key_last($this->foreignKeys);
        if ($last !== null) {
            $this->foreignKeys[$last]['onDelete'] = strtoupper($action);
        }
        return $this;
    }

    /**
     * Sets a custom ON UPDATE action on the last defined foreign key.
     *
     * @param string $action CASCADE | SET NULL | RESTRICT | NO ACTION.
     * @return static Fluent.
     */
    public function onUpdate(string $action): static
    {
        $last = array_key_last($this->foreignKeys);
        if ($last !== null) {
            $this->foreignKeys[$last]['onUpdate'] = strtoupper($action);
        }
        return $this;
    }

    /**
     * Begins an explicit foreign key definition (without using foreignId).
     *
     * @example $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete()
     *
     * @param string $column Column holding the foreign key.
     * @return static Fluent.
     */
    public function foreign(string $column): static
    {
        $constraintName = "fk_{$this->table}_{$column}";
        $this->foreignKeys[$constraintName] = [
            'column'     => $column,
            'references' => 'id',
            'on'         => '',
            'onDelete'   => 'RESTRICT',
            'onUpdate'   => 'RESTRICT',
        ];
        $this->lastColumn = '__fk__' . $constraintName;
        return $this;
    }

    /**
     * Sets the referenced column for the FK started with ->foreign().
     *
     * @param string $column Referenced column name.
     * @return static Fluent.
     */
    public function references(string $column): static
    {
        if (str_starts_with((string) $this->lastColumn, '__fk__')) {
            $key = substr((string) $this->lastColumn, 6);
            if (isset($this->foreignKeys[$key])) {
                $this->foreignKeys[$key]['references'] = $column;
            }
        }
        return $this;
    }

    /**
     * Sets the referenced table for the FK started with ->foreign().
     *
     * @param string $table Referenced table name.
     * @return static Fluent.
     */
    public function on(string $table): static
    {
        if (str_starts_with((string) $this->lastColumn, '__fk__')) {
            $key = substr((string) $this->lastColumn, 6);
            if (isset($this->foreignKeys[$key])) {
                $this->foreignKeys[$key]['on'] = $table;
            }
        }
        return $this;
    }

    /**
     * Drops a foreign key constraint by name or column list.
     *
     * @param string|string[] $nameOrColumns Constraint name or column list.
     * @return static Fluent.
     */
    public function dropForeign(string|array $nameOrColumns): static
    {
        $name = is_array($nameOrColumns)
            ? 'fk_' . $this->table . '_' . implode('_', $nameOrColumns)
            : $nameOrColumns;
        $this->dropClauses[] = ['type' => 'foreign', 'name' => $name];
        return $this;
    }

    // =========================================================
    // DROP / RENAME COLUMNS
    // =========================================================

    /**
     * Drops one or more columns from the table.
     *
     * @param string|string[] $columns Column name(s) to drop.
     * @return static Fluent.
     */
    public function dropColumn(string|array $columns): static
    {
        foreach ((array) $columns as $col) {
            $this->columns["__drop__{$col}"] = $col;
        }
        return $this;
    }

    /**
     * Renames a column (syntax support varies by driver version).
     *
     * @param string $from Current column name.
     * @param string $to   New column name.
     * @return static Fluent.
     */
    public function renameColumn(string $from, string $to): static
    {
        $this->columns["__rename__{$from}"] = $to;
        return $this;
    }

    /**
     * Produces the body of a CREATE TABLE statement (columns + indexes + FKs).
     * Each entry is indented with 4 spaces for readability.
     *
     * @return string Multi-line SQL body (without the CREATE TABLE header/footer).
     */
    public function toCreateSql(): string
    {
        $grammar = $this->grammar();
        $parts   = [];

        foreach ($this->columns as $name => $definition) {
            // Skip internal prefixed keys
            if (str_starts_with($name, '__')) {
                continue;
            }

            $translatedDef  = $grammar->translateColumnDefinition($name, (string) $definition);
            $quotedName     = $grammar->quoteIdentifier($name);
            $parts[]        = "    {$quotedName} {$translatedDef}";
        }

        // Indexes
        foreach ($this->indexes as $index) {
            $compiled = $this->compileInlineIndex($index, $grammar);
            if ($compiled !== '') {
                $parts[] = "    {$compiled}";
            }
        }

        // Foreign keys (not supported inline in SQLite — handled by Schema separately)
        if (!$grammar->isSQLite()) {
            foreach ($this->foreignKeys as $name => $fk) {
                if (empty($fk['on'])) {
                    continue;
                }
                $parts[] = $this->compileForeignKeyInline($name, $fk, $grammar);
            }
        }

        return implode(",\n", $parts);
    }

    /**
     * Produces an array of ALTER TABLE clause strings for Schema::table().
     * Each string is a single clause suitable for appending to ALTER TABLE `t`.
     *
     * @return string[] Array of SQL clause fragments.
     */
    public function toAlterClauses(): array
    {
        $grammar = $this->grammar();
        $clauses = [];

        // ── Drop clauses first (FK / index drops before column changes) ──────
        foreach ($this->dropClauses as $drop) {
            $compiled = match ($drop['type']) {
                'foreign' => $grammar->compileDropForeignKey($drop['name']),
                'primary' => $grammar->compileDropPrimary($this->table),
                'index'   => $grammar->compileDropIndex($drop['name'], $this->table),
                default   => [],
            };
            foreach ($compiled as $clause) {
                $clauses[] = $clause;
            }
        }

        // ── Column alterations ────────────────────────────────────────────────
        foreach ($this->columns as $name => $definition) {
            if (str_starts_with($name, '__drop__')) {
                // DROP COLUMN
                $col      = (string) $definition;
                $clauses[] = 'DROP COLUMN ' . $grammar->quoteIdentifier($col);

            } elseif (str_starts_with($name, '__rename__')) {
                // RENAME COLUMN
                $from     = substr($name, strlen('__rename__'));
                $to       = (string) $definition;
                $clauses[] = $grammar->compileRenameColumn($from, $to);

            } elseif (str_starts_with($name, '__modify__')) {
                // MODIFY / ALTER COLUMN TYPE
                $col      = substr($name, strlen('__modify__'));
                $compiled = $grammar->compileModifyColumn($col, (string) $definition);
                if ($compiled !== '') {
                    // PgSQL may return multiple clauses joined by comma
                    foreach (explode(', ALTER COLUMN', $compiled) as $i => $part) {
                        $clauses[] = $i === 0 ? $part : 'ALTER COLUMN' . $part;
                    }
                }

            } elseif (str_starts_with($name, '__change__')) {
                // CHANGE COLUMN (rename + retype)
                $from     = substr($name, strlen('__change__'));
                $meta     = (array) $definition;
                $compiled = $grammar->compileChangeColumn($from, $meta['to'], $meta['definition']);
                if ($compiled !== '') {
                    $clauses[] = $compiled;
                }

            } else {
                // ADD COLUMN
                $translatedDef = $grammar->translateColumnDefinition($name, (string) $definition);
                $quotedName    = $grammar->quoteIdentifier($name);
                $clauses[]     = "ADD COLUMN {$quotedName} {$translatedDef}";
            }
        }

        // ── Add indexes ───────────────────────────────────────────────────────
        foreach ($this->indexes as $index) {
            $stmts = $grammar->compileAddIndex(
                $index['name'] ?? '',
                $index['columns'],
                $this->table,
                $index['type'] === 'unique'
            );
            foreach ($stmts as $stmt) {
                $clauses[] = $stmt;
            }
        }

        // ── Add foreign keys (not supported in SQLite) ────────────────────────
        if (!$grammar->isSQLite()) {
            foreach ($this->foreignKeys as $name => $fk) {
                if (empty($fk['on'])) {
                    continue;
                }
                $clauses[] = 'ADD ' . $this->compileForeignKeyConstraint($name, $fk, $grammar);
            }
        }

        return $clauses;
    }

    /**
     * Compiles an inline index fragment for CREATE TABLE.
     * Returns empty string for index types not supported inline on the driver.
     *
     * @param array   $index   Index descriptor from $this->indexes.
     * @param Grammar $grammar Active grammar instance.
     * @return string SQL fragment or empty string.
     */
    protected function compileInlineIndex(array|string $index, Grammar $grammar): string
    {
        $cols = $grammar->quoteColumns($index['columns']);

        return match ($index['type']) {
            'primary'  => 'PRIMARY KEY (' . $cols . ')',

            'unique'   => $grammar->isMySQL()
                ? 'UNIQUE KEY ' . $grammar->quoteIdentifier($index['name']) . " ({$cols})"
                : 'UNIQUE (' . $cols . ')',

            'index'    => $grammar->isMySQL()
                ? 'KEY ' . $grammar->quoteIdentifier($index['name']) . " ({$cols})"
                : '',  // PgSQL/SQLite: CREATE INDEX statement (handled separately)

            'fulltext' => $grammar->isMySQL()
                ? 'FULLTEXT KEY ' . $grammar->quoteIdentifier($index['name']) . " ({$cols})"
                : '', // Not supported inline on other drivers

            default    => '',
        };
    }

    /**
     * Compiles a complete CONSTRAINT … FOREIGN KEY … REFERENCES … fragment
     * for use inside CREATE TABLE (after the column list).
     *
     * @param string  $name    Constraint name.
     * @param array   $fk      FK descriptor.
     * @param Grammar $grammar Active grammar instance.
     * @return string SQL fragment.
     */
    protected function compileForeignKeyInline(string $name, array $fk, Grammar $grammar): string
    {
        return '    ' . $this->compileForeignKeyConstraint($name, $fk, $grammar);
    }

    /**
     * Compiles a CONSTRAINT … FOREIGN KEY … REFERENCES … fragment
     * suitable for use both inline (CREATE TABLE) and via ADD (ALTER TABLE).
     *
     * @param string  $name    Constraint name.
     * @param array   $fk      FK descriptor (column, references, on, onDelete, onUpdate).
     * @param Grammar $grammar Active grammar instance.
     * @return string SQL constraint fragment.
     */
    protected function compileForeignKeyConstraint(string $name, array $fk, Grammar $grammar): string
    {
        $quotedName  = $grammar->quoteIdentifier($name);
        $quotedCol   = $grammar->quoteIdentifier($fk['column']);
        $quotedTable = $grammar->quoteTable($fk['on']);
        $quotedRef   = $grammar->quoteIdentifier($fk['references']);

        return "CONSTRAINT {$quotedName} FOREIGN KEY ({$quotedCol})"
            . " REFERENCES {$quotedTable} ({$quotedRef})"
            . " ON DELETE {$fk['onDelete']} ON UPDATE {$fk['onUpdate']}";
    }

    /**
     * Returns the table name this Blueprint targets.
     *
     * @return string Table name.
     */
    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * Returns the raw columns array (internal representation).
     *
     * @return array<string, string> Column definitions keyed by name.
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /**
     * Returns the raw index definitions array.
     *
     * @return array[] Index descriptors.
     */
    public function getIndexes(): array
    {
        return $this->indexes;
    }

    /**
     * Returns the raw foreign key definitions array.
     *
     * @return array<string, array> FK descriptors keyed by constraint name.
     */
    public function getForeignKeys(): array
    {
        return $this->foreignKeys;
    }
}