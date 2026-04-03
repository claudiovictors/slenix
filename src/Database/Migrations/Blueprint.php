<?php

/*
|--------------------------------------------------------------------------
| Classe Blueprint (v2) — Melhorias
|--------------------------------------------------------------------------
|
| O contrato público (toCreateSql / toAlterClauses) permanece inalterado.
|
*/

declare(strict_types=1);

namespace Slenix\Database\Migrations;

class Blueprint
{
    protected string $table;
    protected array $columns = [];
    protected array $indexes = [];
    protected array $foreignKeys = [];
    protected array $dropClauses = [];
    protected ?string $lastColumn = null;

    public function __construct(string $table)
    {
        $this->table = $table;
    }

    // =========================================================
    // COLUNAS NUMÉRICAS
    // =========================================================

    public function id(string $column = 'id'): static
    {
        $this->columns[$column] = 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
        $this->lastColumn = $column;
        return $this;
    }

    public function tinyInteger(string $column, bool $unsigned = false): static
    {
        $this->columns[$column] = 'TINYINT' . ($unsigned ? ' UNSIGNED' : '') . ' NOT NULL';
        $this->lastColumn = $column;
        return $this;
    }

    public function smallInteger(string $column, bool $unsigned = false): static
    {
        $this->columns[$column] = 'SMALLINT' . ($unsigned ? ' UNSIGNED' : '') . ' NOT NULL';
        $this->lastColumn = $column;
        return $this;
    }

    public function integer(string $column, bool $unsigned = false): static
    {
        $this->columns[$column] = 'INT' . ($unsigned ? ' UNSIGNED' : '') . ' NOT NULL';
        $this->lastColumn = $column;
        return $this;
    }

    public function bigInteger(string $column, bool $unsigned = false): static
    {
        $this->columns[$column] = 'BIGINT' . ($unsigned ? ' UNSIGNED' : '') . ' NOT NULL';
        $this->lastColumn = $column;
        return $this;
    }

    public function float(string $column, int $total = 8, int $places = 2): static
    {
        $this->columns[$column] = "FLOAT({$total}, {$places}) NOT NULL";
        $this->lastColumn = $column;
        return $this;
    }

    public function double(string $column, int $total = 15, int $places = 8): static
    {
        $this->columns[$column] = "DOUBLE({$total}, {$places}) NOT NULL";
        $this->lastColumn = $column;
        return $this;
    }

    public function decimal(string $column, int $total = 10, int $places = 2): static
    {
        $this->columns[$column] = "DECIMAL({$total}, {$places}) NOT NULL";
        $this->lastColumn = $column;
        return $this;
    }

    public function boolean(string $column): static
    {
        $this->columns[$column] = 'TINYINT(1) NOT NULL';
        $this->lastColumn = $column;
        return $this;
    }

    // =========================================================
    // COLUNAS DE TEXTO
    // =========================================================

    public function char(string $column, int $length = 1): static
    {
        $this->columns[$column] = "CHAR({$length}) NOT NULL";
        $this->lastColumn = $column;
        return $this;
    }

    public function string(string $column, int $length = 255): static
    {
        $this->columns[$column] = "VARCHAR({$length}) NOT NULL";
        $this->lastColumn = $column;
        return $this;
    }

    public function tinyText(string $column): static
    {
        $this->columns[$column] = 'TINYTEXT NOT NULL';
        $this->lastColumn = $column;
        return $this;
    }

    public function text(string $column): static
    {
        $this->columns[$column] = 'TEXT NOT NULL';
        $this->lastColumn = $column;
        return $this;
    }

    public function mediumText(string $column): static
    {
        $this->columns[$column] = 'MEDIUMTEXT NOT NULL';
        $this->lastColumn = $column;
        return $this;
    }

    public function longText(string $column): static
    {
        $this->columns[$column] = 'LONGTEXT NOT NULL';
        $this->lastColumn = $column;
        return $this;
    }

    public function json(string $column): static
    {
        $this->columns[$column] = 'JSON NOT NULL';
        $this->lastColumn = $column;
        return $this;
    }

    public function enum(string $column, array $values): static
    {
        $list = implode(', ', array_map(fn($v) => "'{$v}'", $values));
        $this->columns[$column] = "ENUM({$list}) NOT NULL";
        $this->lastColumn = $column;
        return $this;
    }

    public function set(string $column, array $values): static
    {
        $list = implode(', ', array_map(fn($v) => "'{$v}'", $values));
        $this->columns[$column] = "SET({$list}) NOT NULL";
        $this->lastColumn = $column;
        return $this;
    }

    // =========================================================
    // COLUNAS DE DATA/HORA
    // =========================================================

    public function date(string $column): static
    {
        $this->columns[$column] = 'DATE NOT NULL';
        $this->lastColumn = $column;
        return $this;
    }

    public function time(string $column): static
    {
        $this->columns[$column] = 'TIME NOT NULL';
        $this->lastColumn = $column;
        return $this;
    }

    public function dateTime(string $column): static
    {
        $this->columns[$column] = 'DATETIME NOT NULL';
        $this->lastColumn = $column;
        return $this;
    }

    public function timestamp(string $column): static
    {
        $this->columns[$column] = 'TIMESTAMP NULL DEFAULT NULL';
        $this->lastColumn = $column;
        return $this;
    }

    public function year(string $column): static
    {
        $this->columns[$column] = 'YEAR NOT NULL';
        $this->lastColumn = $column;
        return $this;
    }

    public function timestamps(): static
    {
        $this->columns['created_at'] = 'TIMESTAMP NULL DEFAULT NULL';
        $this->columns['updated_at'] = 'TIMESTAMP NULL DEFAULT NULL';
        return $this;
    }

    public function softDeletes(string $column = 'deleted_at'): static
    {
        $this->columns[$column] = 'TIMESTAMP NULL DEFAULT NULL';
        $this->lastColumn = $column;
        return $this;
    }

    // =========================================================
    // COLUNAS ESPECIAIS / BINÁRIA
    // =========================================================

    public function binary(string $column, int $length = 255): static
    {
        $this->columns[$column] = "BINARY({$length}) NOT NULL";
        $this->lastColumn = $column;
        return $this;
    }

    public function uuid(string $column = 'uuid'): static
    {
        $this->columns[$column] = 'VARCHAR(36) NOT NULL';
        $this->lastColumn = $column;
        return $this;
    }

    public function foreignId(string $column): static
    {
        $this->columns[$column] = 'BIGINT UNSIGNED NOT NULL';
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * Chave estrangeira nullable (SET NULL ao deletar)
     *
     * @example $table->foreignIdNullable('parent_id')->constrained('categories')->nullOnDelete()
     */
    public function foreignIdNullable(string $column): static
    {
        $this->columns[$column] = 'BIGINT UNSIGNED NULL DEFAULT NULL';
        $this->lastColumn = $column;
        return $this;
    }

    public function ipAddress(string $column = 'ip_address'): static
    {
        $this->columns[$column] = 'VARCHAR(45) NOT NULL';
        $this->lastColumn = $column;
        return $this;
    }

    public function macAddress(string $column = 'mac_address'): static
    {
        $this->columns[$column] = 'VARCHAR(17) NOT NULL';
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * Coluna calculada/gerada (MySQL 5.7+)
     *
     * @example $table->generated('full_name', "CONCAT(first_name, ' ', last_name)")
     * @example $table->generated('area', 'width * height', 'STORED')
     */
    public function generated(string $column, string $expression, string $type = 'VIRTUAL'): static
    {
        $type = strtoupper($type) === 'STORED' ? 'STORED' : 'VIRTUAL';
        $this->columns[$column] = "VARCHAR(255) GENERATED ALWAYS AS ({$expression}) {$type}";
        $this->lastColumn = $column;
        return $this;
    }

    // =========================================================
    // MODIFICADORES (encadeáveis)
    // =========================================================

    public function nullable(): static
    {
        if ($this->lastColumn && isset($this->columns[$this->lastColumn])) {
            $this->columns[$this->lastColumn] = str_replace(' NOT NULL', ' NULL', $this->columns[$this->lastColumn]);
            if (!str_contains($this->columns[$this->lastColumn], 'NULL')) {
                $this->columns[$this->lastColumn] .= ' NULL';
            }
        }
        return $this;
    }

    public function default(mixed $value): static
    {
        if ($this->lastColumn && isset($this->columns[$this->lastColumn])) {
            $default = match (true) {
                is_bool($value) => $value ? '1' : '0',
                is_null($value) => 'NULL',
                is_numeric($value) => (string) $value,
                default => "'{$value}'",
            };
            $this->columns[$this->lastColumn] .= " DEFAULT {$default}";
        }
        return $this;
    }

    public function comment(string $text): static
    {
        if ($this->lastColumn && isset($this->columns[$this->lastColumn])) {
            $this->columns[$this->lastColumn] .= " COMMENT '" . addslashes($text) . "'";
        }
        return $this;
    }

    public function after(string $column): static
    {
        if ($this->lastColumn && isset($this->columns[$this->lastColumn])) {
            $this->columns[$this->lastColumn] .= " AFTER `{$column}`";
        }
        return $this;
    }

    public function first(): static
    {
        if ($this->lastColumn && isset($this->columns[$this->lastColumn])) {
            $this->columns[$this->lastColumn] .= ' FIRST';
        }
        return $this;
    }

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

    public function primary(): static
    {
        if ($this->lastColumn) {
            $this->addPrimary([$this->lastColumn]);
        }
        return $this;
    }

    // =========================================================
    // NOVO: modifyColumn — ALTER MODIFY
    // =========================================================

    /**
     * Modifica a definição completa de uma coluna existente.
     * Gera: MODIFY COLUMN `col` NOVA_DEFINIÇÃO
     *
     * @example Schema::table('users', fn($t) => $t->modifyColumn('email', 'VARCHAR(320) NOT NULL'))
     */
    public function modifyColumn(string $column, string $definition): static
    {
        $this->columns["__modify__{$column}"] = "MODIFY COLUMN `{$column}` {$definition}";
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * Altera o nome E a definição de uma coluna.
     * Gera: CHANGE COLUMN `old` `new` DEFINIÇÃO
     *
     * @example $table->changeColumn('phone', 'mobile', 'VARCHAR(20) NULL')
     */
    public function changeColumn(string $from, string $to, string $definition): static
    {
        $this->columns["__change__{$from}"] = "CHANGE COLUMN `{$from}` `{$to}` {$definition}";
        return $this;
    }

    // =========================================================
    // ÍNDICES
    // =========================================================

    public function unique(array|string|null $columns = null, ?string $name = null): static
    {
        if ($columns === null)
            $columns = [$this->lastColumn];
        $cols = (array) $columns;
        $indexName = $name ?? $this->table . '_' . implode('_', $cols) . '_unique';
        $this->indexes[] = "UNIQUE KEY `{$indexName}` (`" . implode('`, `', $cols) . '`)';
        return $this;
    }

    public function index(array|string $columns, ?string $name = null): static
    {
        $cols = (array) $columns;
        $indexName = $name ?? $this->table . '_' . implode('_', $cols) . '_index';
        $this->indexes[] = "KEY `{$indexName}` (`" . implode('`, `', $cols) . '`)';
        return $this;
    }

    public function fullText(array|string $columns, ?string $name = null): static
    {
        $cols = (array) $columns;
        $indexName = $name ?? $this->table . '_' . implode('_', $cols) . '_fulltext';
        $this->indexes[] = "FULLTEXT KEY `{$indexName}` (`" . implode('`, `', $cols) . '`)';
        return $this;
    }

    public function addPrimary(array $columns): static
    {
        $this->indexes[] = 'PRIMARY KEY (`' . implode('`, `', $columns) . '`)';
        return $this;
    }

    // =========================================================
    // NOVO: Drop de índices
    // =========================================================

    /**
     * Remove um índice pelo nome.
     *
     * @example $table->dropIndex('users_email_index')
     * @example $table->dropIndex(['email']) // gera o nome automaticamente
     */
    public function dropIndex(string|array $nameOrColumns): static
    {
        $name = is_array($nameOrColumns)
            ? $this->table . '_' . implode('_', $nameOrColumns) . '_index'
            : $nameOrColumns;

        $this->dropClauses[] = "DROP INDEX `{$name}`";
        return $this;
    }

    /**
     * Remove um índice UNIQUE.
     *
     * @example $table->dropUnique('users_email_unique')
     * @example $table->dropUnique(['email'])
     */
    public function dropUnique(string|array $nameOrColumns): static
    {
        $name = is_array($nameOrColumns)
            ? $this->table . '_' . implode('_', $nameOrColumns) . '_unique'
            : $nameOrColumns;

        $this->dropClauses[] = "DROP INDEX `{$name}`";
        return $this;
    }

    /**
     * Remove a PRIMARY KEY.
     */
    public function dropPrimary(): static
    {
        $this->dropClauses[] = 'DROP PRIMARY KEY';
        return $this;
    }

    // =========================================================
    // CHAVES ESTRANGEIRAS
    // =========================================================

    /**
     * Cria FK a partir de foreignId (encadeável).
     *
     * @example $table->foreignId('user_id')->constrained()
     * @example $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete()
     */
    public function constrained(?string $referencedTable = null, string $referencedColumn = 'id'): static
    {
        if (!$this->lastColumn)
            return $this;

        $column = $this->lastColumn;

        if ($referencedTable === null) {
            $referencedTable = rtrim(str_replace('_id', '', $column), '_') . 's';
        }

        $constraintName = "fk_{$this->table}_{$column}";

        $this->foreignKeys[$constraintName] = [
            'column' => $column,
            'references' => $referencedColumn,
            'on' => $referencedTable,
            'onDelete' => 'RESTRICT',
            'onUpdate' => 'RESTRICT',
        ];

        return $this;
    }

    /**
     * CASCADE ao deletar o pai.
     *
     * @example ->constrained()->cascadeOnDelete()
     */
    public function cascadeOnDelete(): static
    {
        return $this->onDelete('CASCADE');
    }

    /**
     * SET NULL ao deletar o pai (requer coluna nullable).
     *
     * @example ->constrained()->nullOnDelete()
     */
    public function nullOnDelete(): static
    {
        return $this->onDelete('SET NULL');
    }

    /**
     * RESTRICT ao deletar o pai (padrão).
     */
    public function restrictOnDelete(): static
    {
        return $this->onDelete('RESTRICT');
    }

    /**
     * NO ACTION ao deletar o pai.
     */
    public function noActionOnDelete(): static
    {
        return $this->onDelete('NO ACTION');
    }

    /**
     * CASCADE ao atualizar o pai.
     *
     * @example ->constrained()->cascadeOnUpdate()
     */
    public function cascadeOnUpdate(): static
    {
        return $this->onUpdate('CASCADE');
    }

    public function onDelete(string $action): static
    {
        $last = array_key_last($this->foreignKeys);
        if ($last !== null) {
            $this->foreignKeys[$last]['onDelete'] = mb_strtoupper($action);
        }
        return $this;
    }

    public function onUpdate(string $action): static
    {
        $last = array_key_last($this->foreignKeys);
        if ($last !== null) {
            $this->foreignKeys[$last]['onUpdate'] = mb_strtoupper($action);
        }
        return $this;
    }

    /**
     * FK explícita (sem foreignId).
     *
     * @example $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete()
     */
    public function foreign(string $column): static
    {
        $constraintName = "fk_{$this->table}_{$column}";
        $this->foreignKeys[$constraintName] = [
            'column' => $column,
            'references' => 'id',
            'on' => '',
            'onDelete' => 'RESTRICT',
            'onUpdate' => 'RESTRICT',
        ];
        $this->lastColumn = '__fk__' . $constraintName;
        return $this;
    }

    public function references(string $column): static
    {
        if (str_starts_with((string) $this->lastColumn, '__fk__')) {
            $key = substr($this->lastColumn, 6);
            if (isset($this->foreignKeys[$key])) {
                $this->foreignKeys[$key]['references'] = $column;
            }
        }
        return $this;
    }

    public function on(string $table): static
    {
        if (str_starts_with((string) $this->lastColumn, '__fk__')) {
            $key = substr($this->lastColumn, 6);
            if (isset($this->foreignKeys[$key])) {
                $this->foreignKeys[$key]['on'] = $table;
            }
        }
        return $this;
    }

    // =========================================================
    // NOVO: Drop de FKs
    // =========================================================

    /**
     * Remove uma foreign key pelo nome da constraint.
     *
     * @example $table->dropForeign('fk_posts_user_id')
     * @example $table->dropForeign(['user_id'])  → gera nome automaticamente
     */
    public function dropForeign(string|array $nameOrColumns): static
    {
        $name = is_array($nameOrColumns)
            ? 'fk_' . $this->table . '_' . implode('_', $nameOrColumns)
            : $nameOrColumns;

        $this->dropClauses[] = "DROP FOREIGN KEY `{$name}`";
        return $this;
    }

    // =========================================================
    // DROP / RENAME DE COLUNAS
    // =========================================================

    public function dropColumn(string|array $columns): static
    {
        foreach ((array) $columns as $col) {
            $this->columns["__drop__{$col}"] = "DROP COLUMN `{$col}`";
        }
        return $this;
    }

    public function renameColumn(string $from, string $to): static
    {
        $this->columns["__rename__{$from}"] = "RENAME COLUMN `{$from}` TO `{$to}`";
        return $this;
    }

    // =========================================================
    // GERAÇÃO SQL
    // =========================================================

    public function toCreateSql(): string
    {
        $parts = [];

        foreach ($this->columns as $name => $definition) {
            if (str_starts_with($name, '__'))
                continue;
            $parts[] = "    `{$name}` {$definition}";
        }

        foreach ($this->indexes as $index) {
            $parts[] = "    {$index}";
        }

        foreach ($this->foreignKeys as $name => $fk) {
            if (empty($fk['on']))
                continue;
            $parts[] = "    CONSTRAINT `{$name}` FOREIGN KEY (`{$fk['column']}`)"
                . " REFERENCES `{$fk['on']}` (`{$fk['references']}`)"
                . " ON DELETE {$fk['onDelete']} ON UPDATE {$fk['onUpdate']}";
        }

        return implode(",\n", $parts);
    }

    public function toAlterClauses(): array
    {
        $clauses = [];

        // DROP de FKs e índices (antes de outras alterações)
        foreach ($this->dropClauses as $drop) {
            $clauses[] = $drop;
        }

        foreach ($this->columns as $name => $definition) {
            if (str_starts_with($name, '__drop__')) {
                $clauses[] = $definition;
            } elseif (str_starts_with($name, '__rename__')) {
                $clauses[] = $definition;
            } elseif (str_starts_with($name, '__modify__')) {
                $clauses[] = $definition;
            } elseif (str_starts_with($name, '__change__')) {
                $clauses[] = $definition;
            } else {
                $clauses[] = "ADD COLUMN `{$name}` {$definition}";
            }
        }

        foreach ($this->indexes as $index) {
            $clauses[] = "ADD {$index}";
        }

        foreach ($this->foreignKeys as $name => $fk) {
            if (empty($fk['on']))
                continue;
            $clauses[] = "ADD CONSTRAINT `{$name}` FOREIGN KEY (`{$fk['column']}`)"
                . " REFERENCES `{$fk['on']}` (`{$fk['references']}`)"
                . " ON DELETE {$fk['onDelete']} ON UPDATE {$fk['onUpdate']}";
        }

        return $clauses;
    }

    public function getTable(): string
    {
        return $this->table;
    }
    public function getColumns(): array
    {
        return $this->columns;
    }
    public function getIndexes(): array
    {
        return $this->indexes;
    }
    public function getForeignKeys(): array
    {
        return $this->foreignKeys;
    }
}
