<?php

/*
|--------------------------------------------------------------------------
| Classe Blueprint
|--------------------------------------------------------------------------
|
| Define a estrutura de uma tabela: colunas, índices, chaves estrangeiras.
| É passada para o callback do Schema::create() / Schema::table().
| Gera as cláusulas SQL que serão executadas pela Connection.
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Database\Migrations;

class Blueprint
{
    /** @var string Nome da tabela */
    protected string $table;

    /** @var array Definições de colunas */
    protected array $columns = [];

    /** @var array Índices (UNIQUE, INDEX) */
    protected array $indexes = [];

    /** @var array Chaves estrangeiras */
    protected array $foreignKeys = [];

    /** @var string|null Última coluna adicionada (para encadeamento) */
    protected ?string $lastColumn = null;

    public function __construct(string $table)
    {
        $this->table = $table;
    }

    // =========================================================
    // COLUNAS NUMÉRICAS
    // =========================================================

    /**
     * BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
     * @example $table->id()
     */
    public function id(string $column = 'id'): static
    {
        $this->columns[$column] = "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY";
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * TINYINT (1 byte, -128 a 127)
     * @example $table->tinyInteger('rating')
     */
    public function tinyInteger(string $column, bool $unsigned = false): static
    {
        $type = 'TINYINT' . ($unsigned ? ' UNSIGNED' : '');
        $this->columns[$column] = $type . ' NOT NULL';
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * SMALLINT (2 bytes)
     * @example $table->smallInteger('views')
     */
    public function smallInteger(string $column, bool $unsigned = false): static
    {
        $type = 'SMALLINT' . ($unsigned ? ' UNSIGNED' : '');
        $this->columns[$column] = $type . ' NOT NULL';
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * INT padrão
     * @example $table->integer('stock')
     */
    public function integer(string $column, bool $unsigned = false): static
    {
        $type = 'INT' . ($unsigned ? ' UNSIGNED' : '');
        $this->columns[$column] = $type . ' NOT NULL';
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * BIGINT
     * @example $table->bigInteger('views')
     */
    public function bigInteger(string $column, bool $unsigned = false): static
    {
        $type = 'BIGINT' . ($unsigned ? ' UNSIGNED' : '');
        $this->columns[$column] = $type . ' NOT NULL';
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * FLOAT
     * @example $table->float('latitude')
     */
    public function float(string $column, int $total = 8, int $places = 2): static
    {
        $this->columns[$column] = "FLOAT($total, $places) NOT NULL";
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * DOUBLE
     * @example $table->double('amount', 15, 8)
     */
    public function double(string $column, int $total = 15, int $places = 8): static
    {
        $this->columns[$column] = "DOUBLE($total, $places) NOT NULL";
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * DECIMAL(total, places) — ideal para valores monetários
     * @example $table->decimal('price', 10, 2)
     */
    public function decimal(string $column, int $total = 10, int $places = 2): static
    {
        $this->columns[$column] = "DECIMAL($total, $places) NOT NULL";
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * TINYINT(1) usado como booleano
     * @example $table->boolean('is_active')
     */
    public function boolean(string $column): static
    {
        $this->columns[$column] = "TINYINT(1) NOT NULL";
        $this->lastColumn = $column;
        return $this;
    }

    // =========================================================
    // COLUNAS DE TEXTO
    // =========================================================

    /**
     * CHAR(length)
     * @example $table->char('code', 6)
     */
    public function char(string $column, int $length = 1): static
    {
        $this->columns[$column] = "CHAR($length) NOT NULL";
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * VARCHAR(length)
     * @example $table->string('name', 150)
     */
    public function string(string $column, int $length = 255): static
    {
        $this->columns[$column] = "VARCHAR($length) NOT NULL";
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * TINYTEXT (até 255 bytes)
     */
    public function tinyText(string $column): static
    {
        $this->columns[$column] = "TINYTEXT NOT NULL";
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * TEXT
     * @example $table->text('description')
     */
    public function text(string $column): static
    {
        $this->columns[$column] = "TEXT NOT NULL";
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * MEDIUMTEXT (até ~16MB)
     */
    public function mediumText(string $column): static
    {
        $this->columns[$column] = "MEDIUMTEXT NOT NULL";
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * LONGTEXT (até ~4GB)
     * @example $table->longText('content')
     */
    public function longText(string $column): static
    {
        $this->columns[$column] = "LONGTEXT NOT NULL";
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * JSON
     * @example $table->json('meta')
     */
    public function json(string $column): static
    {
        $this->columns[$column] = "JSON NOT NULL";
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * ENUM
     * @example $table->enum('status', ['active', 'inactive', 'pending'])
     */
    public function enum(string $column, array $values): static
    {
        $list = implode(', ', array_map(fn($v) => "'{$v}'", $values));
        $this->columns[$column] = "ENUM($list) NOT NULL";
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * SET (múltiplos valores)
     * @example $table->set('permissions', ['read', 'write', 'delete'])
     */
    public function set(string $column, array $values): static
    {
        $list = implode(', ', array_map(fn($v) => "'{$v}'", $values));
        $this->columns[$column] = "SET($list) NOT NULL";
        $this->lastColumn = $column;
        return $this;
    }

    // =========================================================
    // COLUNAS DE DATA/HORA
    // =========================================================

    /**
     * DATE (apenas data)
     * @example $table->date('birth_date')
     */
    public function date(string $column): static
    {
        $this->columns[$column] = "DATE NOT NULL";
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * TIME (apenas hora)
     */
    public function time(string $column): static
    {
        $this->columns[$column] = "TIME NOT NULL";
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * DATETIME
     * @example $table->dateTime('published_at')
     */
    public function dateTime(string $column): static
    {
        $this->columns[$column] = "DATETIME NOT NULL";
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * TIMESTAMP
     */
    public function timestamp(string $column): static
    {
        $this->columns[$column] = "TIMESTAMP NULL DEFAULT NULL";
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * YEAR
     */
    public function year(string $column): static
    {
        $this->columns[$column] = "YEAR NOT NULL";
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * Adiciona created_at e updated_at TIMESTAMP NULL
     * @example $table->timestamps()
     */
    public function timestamps(): static
    {
        $this->columns['created_at'] = "TIMESTAMP NULL DEFAULT NULL";
        $this->columns['updated_at'] = "TIMESTAMP NULL DEFAULT NULL";
        return $this;
    }

    /**
     * Coluna deleted_at para Soft Delete
     * @example $table->softDeletes()
     */
    public function softDeletes(string $column = 'deleted_at'): static
    {
        $this->columns[$column] = "TIMESTAMP NULL DEFAULT NULL";
        $this->lastColumn = $column;
        return $this;
    }

    // =========================================================
    // COLUNAS BINÁRIAS / ESPECIAIS
    // =========================================================

    /**
     * BINARY
     */
    public function binary(string $column, int $length = 255): static
    {
        $this->columns[$column] = "BINARY($length) NOT NULL";
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * UUID — VARCHAR(36)
     * @example $table->uuid('uuid')
     */
    public function uuid(string $column = 'uuid'): static
    {
        $this->columns[$column] = "VARCHAR(36) NOT NULL";
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * BIGINT UNSIGNED para chaves estrangeiras
     * @example $table->foreignId('user_id')
     */
    public function foreignId(string $column): static
    {
        $this->columns[$column] = "BIGINT UNSIGNED NOT NULL";
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * IP Address — VARCHAR(45) para suportar IPv6
     */
    public function ipAddress(string $column = 'ip_address'): static
    {
        $this->columns[$column] = "VARCHAR(45) NOT NULL";
        $this->lastColumn = $column;
        return $this;
    }

    /**
     * MAC Address — VARCHAR(17)
     */
    public function macAddress(string $column = 'mac_address'): static
    {
        $this->columns[$column] = "VARCHAR(17) NOT NULL";
        $this->lastColumn = $column;
        return $this;
    }

    // =========================================================
    // MODIFICADORES (encadeáveis)
    // =========================================================

    /**
     * Torna a coluna nullable
     * @example $table->text('description')->nullable()
     */
    public function nullable(): static
    {
        if ($this->lastColumn && isset($this->columns[$this->lastColumn])) {
            $this->columns[$this->lastColumn] = str_replace(
                ' NOT NULL',
                ' NULL',
                $this->columns[$this->lastColumn]
            );
            // Se não havia NOT NULL, adiciona NULL explícito (para TIMESTAMP etc.)
            if (!str_contains($this->columns[$this->lastColumn], 'NULL')) {
                $this->columns[$this->lastColumn] .= ' NULL';
            }
        }
        return $this;
    }

    /**
     * Define valor padrão
     * @example $table->integer('stock')->default(0)
     * @example $table->boolean('active')->default(true)
     * @example $table->string('status')->default('pending')
     */
    public function default(mixed $value): static
    {
        if ($this->lastColumn && isset($this->columns[$this->lastColumn])) {
            if (is_bool($value)) {
                $default = $value ? '1' : '0';
            } elseif (is_null($value)) {
                $default = 'NULL';
            } elseif (is_numeric($value)) {
                $default = (string) $value;
            } else {
                $default = "'{$value}'";
            }
            $this->columns[$this->lastColumn] .= " DEFAULT {$default}";
        }
        return $this;
    }

    /**
     * Adiciona um comentário à coluna
     * @example $table->string('status')->comment('Status do pedido')
     */
    public function comment(string $text): static
    {
        if ($this->lastColumn && isset($this->columns[$this->lastColumn])) {
            $escaped = addslashes($text);
            $this->columns[$this->lastColumn] .= " COMMENT '{$escaped}'";
        }
        return $this;
    }

    /**
     * Após qual coluna inserir (para ALTER TABLE)
     * @example $table->string('phone')->after('email')
     */
    public function after(string $column): static
    {
        if ($this->lastColumn && isset($this->columns[$this->lastColumn])) {
            $this->columns[$this->lastColumn] .= " AFTER `{$column}`";
        }
        return $this;
    }

    /**
     * Insere como primeira coluna (para ALTER TABLE)
     */
    public function first(): static
    {
        if ($this->lastColumn && isset($this->columns[$this->lastColumn])) {
            $this->columns[$this->lastColumn] .= " FIRST";
        }
        return $this;
    }

    /**
     * Coluna sem sinal (UNSIGNED)
     * @example $table->integer('views')->unsigned()
     */
    public function unsigned(): static
    {
        if ($this->lastColumn && isset($this->columns[$this->lastColumn])) {
            // Insere UNSIGNED após o tipo de dado
            $this->columns[$this->lastColumn] = preg_replace(
                '/^(\w+(?:\([^)]*\))?)/',
                '$1 UNSIGNED',
                $this->columns[$this->lastColumn]
            );
        }
        return $this;
    }

    /**
     * Define como chave primária
     */
    public function primary(): static
    {
        if ($this->lastColumn) {
            $this->addPrimary([$this->lastColumn]);
        }
        return $this;
    }

    // =========================================================
    // ÍNDICES
    // =========================================================

    /**
     * Adiciona índice UNIQUE
     * @example $table->string('email')->unique()
     * @example $table->unique(['email', 'tenant_id'])
     */
    public function unique(array|string|null $columns = null, ?string $name = null): static
    {
        if ($columns === null) {
            $columns = [$this->lastColumn];
        }
        $cols = (array) $columns;
        $indexName = $name ?? $this->table . '_' . implode('_', $cols) . '_unique';
        $this->indexes[] = "UNIQUE KEY `{$indexName}` (`" . implode('`, `', $cols) . "`)";
        return $this;
    }

    /**
     * Adiciona índice comum (para performance de buscas)
     * @example $table->index('category_id')
     * @example $table->index(['user_id', 'created_at'])
     */
    public function index(array|string $columns, ?string $name = null): static
    {
        $cols = (array) $columns;
        $indexName = $name ?? $this->table . '_' . implode('_', $cols) . '_index';
        $this->indexes[] = "KEY `{$indexName}` (`" . implode('`, `', $cols) . "`)";
        return $this;
    }

    /**
     * Adiciona índice FULLTEXT (para buscas textuais)
     * @example $table->fullText(['title', 'content'])
     */
    public function fullText(array|string $columns, ?string $name = null): static
    {
        $cols = (array) $columns;
        $indexName = $name ?? $this->table . '_' . implode('_', $cols) . '_fulltext';
        $this->indexes[] = "FULLTEXT KEY `{$indexName}` (`" . implode('`, `', $cols) . "`)";
        return $this;
    }

    /**
     * Adiciona PRIMARY KEY composta
     * @example $table->addPrimary(['user_id', 'role_id'])
     */
    public function addPrimary(array $columns): static
    {
        $this->indexes[] = "PRIMARY KEY (`" . implode('`, `', $columns) . "`)";
        return $this;
    }

    // =========================================================
    // CHAVES ESTRANGEIRAS
    // =========================================================

    /**
     * Define a tabela referenciada pela foreignId (encadeável)
     *
     * @example $table->foreignId('user_id')->constrained()
     * @example $table->foreignId('category_id')->constrained('categories')
     */
    public function constrained(?string $referencedTable = null, string $referencedColumn = 'id'): static
    {
        if (!$this->lastColumn) return $this;

        $column = $this->lastColumn;

        // Deduz o nome da tabela a partir da coluna (ex: user_id → users)
        if ($referencedTable === null) {
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
     * Define ação ao deletar o pai (CASCADE, SET NULL, RESTRICT, NO ACTION)
     * @example ->constrained()->onDelete('cascade')
     */
    public function onDelete(string $action): static
    {
        $last = array_key_last($this->foreignKeys);
        if ($last !== null) {
            $this->foreignKeys[$last]['onDelete'] = mb_strtolower($action);
        }
        return $this;
    }

    /**
     * Define ação ao atualizar o pai
     * @example ->constrained()->onUpdate('cascade')
     */
    public function onUpdate(string $action): static
    {
        $last = array_key_last($this->foreignKeys);
        if ($last !== null) {
            $this->foreignKeys[$last]['onUpdate'] = mb_strtolower($action);
        }
        return $this;
    }

    /**
     * Adiciona uma FK explícita (sem foreignId)
     *
     * @example $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade')
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
     * Define a coluna referenciada da FK
     */
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

    /**
     * Define a tabela referenciada da FK
     */
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
    // COLUNA ESPECIAL: REMOVER (para Schema::table)
    // =========================================================

    /**
     * Marca uma coluna para ser dropada (ALTER TABLE ... DROP COLUMN)
     * @example $table->dropColumn('old_field')
     */
    public function dropColumn(string|array $columns): static
    {
        foreach ((array) $columns as $col) {
            $this->columns["__drop__{$col}"] = "DROP COLUMN `{$col}`";
        }
        return $this;
    }

    /**
     * Renomeia uma coluna (MySQL 8+ / MariaDB)
     * @example $table->renameColumn('old', 'new')
     */
    public function renameColumn(string $from, string $to): static
    {
        $this->columns["__rename__{$from}"] = "RENAME COLUMN `{$from}` TO `{$to}`";
        return $this;
    }

    // =========================================================
    // GERAÇÃO SQL
    // =========================================================

    /**
     * Gera as cláusulas SQL para CREATE TABLE
     */
    public function toCreateSql(): string
    {
        $parts = [];

        foreach ($this->columns as $name => $definition) {
            // Ignora marcadores internos de drop/rename (não usados em CREATE)
            if (str_starts_with($name, '__')) continue;
            $parts[] = "    `{$name}` {$definition}";
        }

        foreach ($this->indexes as $index) {
            $parts[] = "    {$index}";
        }

        foreach ($this->foreignKeys as $name => $fk) {
            if (empty($fk['on'])) continue;
            $action  = "CONSTRAINT `{$name}` FOREIGN KEY (`{$fk['column']}`) REFERENCES `{$fk['on']}` (`{$fk['references']}`)";
            $action .= " ON DELETE {$fk['onDelete']} ON UPDATE {$fk['onUpdate']}";
            $parts[] = "    {$action}";
        }

        return implode(",\n", $parts);
    }

    /**
     * Gera as cláusulas SQL para ALTER TABLE (Schema::table)
     */
    public function toAlterClauses(): array
    {
        $clauses = [];

        foreach ($this->columns as $name => $definition) {
            if (str_starts_with($name, '__drop__')) {
                $clauses[] = $definition; // "DROP COLUMN `x`"
            } elseif (str_starts_with($name, '__rename__')) {
                $clauses[] = $definition; // "RENAME COLUMN `x` TO `y`"
            } else {
                $clauses[] = "ADD COLUMN `{$name}` {$definition}";
            }
        }

        foreach ($this->indexes as $index) {
            $clauses[] = "ADD {$index}";
        }

        foreach ($this->foreignKeys as $name => $fk) {
            if (empty($fk['on'])) continue;
            $clause  = "ADD CONSTRAINT `{$name}` FOREIGN KEY (`{$fk['column']}`) REFERENCES `{$fk['on']}` (`{$fk['references']}`)";
            $clause .= " ON DELETE {$fk['onDelete']} ON UPDATE {$fk['onUpdate']}";
            $clauses[] = $clause;
        }

        return $clauses;
    }

    public function getTable(): string { return $this->table; }
    public function getColumns(): array { return $this->columns; }
    public function getIndexes(): array { return $this->indexes; }
    public function getForeignKeys(): array { return $this->foreignKeys; }
}