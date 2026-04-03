<?php

/*
|--------------------------------------------------------------------------
| Classe Seeder (Base)
|--------------------------------------------------------------------------
|
| Classe abstrata base para todos os seeders do Slenix.
| Suporta MySQL e PostgreSQL nativamente.
|
*/

declare(strict_types=1);

namespace Slenix\Database\Seeds;

use PDO;
use Slenix\Database\Connection;

abstract class Seeder
{
    protected PDO $pdo;

    /** @var string Driver detectado: 'mysql' ou 'pgsql' */
    protected string $driver;

    public function __construct()
    {
        $this->pdo    = Connection::getInstance();
        $this->driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    abstract public function run(): void;

    // =========================================================
    // CHAMADA DE OUTROS SEEDERS
    // =========================================================

    /**
     * Chama outro seeder a partir deste.
     *
     * @example $this->call(UserSeeder::class);
     * @example $this->call([UserSeeder::class, PostSeeder::class]);
     */
    public function call(string|array $seeders): void
    {
        foreach ((array) $seeders as $seederClass) {
            if (!class_exists($seederClass)) {
                throw new \RuntimeException("Seeder [{$seederClass}] não encontrado.");
            }

            $seeder = new $seederClass();

            if (!($seeder instanceof self)) {
                throw new \RuntimeException(
                    "A classe [{$seederClass}] deve estender Seeder."
                );
            }

            echo "    → Executando {$seederClass}..." . PHP_EOL;
            $seeder->run();
        }
    }

    // =========================================================
    // INSERÇÃO
    // =========================================================

    /**
     * Insere um registro ignorando duplicatas.
     * MySQL  → INSERT IGNORE
     * PgSQL  → INSERT ... ON CONFLICT DO NOTHING
     *
     * @example $this->insertOrIgnore('users', ['name' => 'João', 'email' => 'joao@x.com'])
     */
    protected function insertOrIgnore(string $table, array $data): bool
    {
        if (empty($data)) return false;

        $columns = array_keys($data);
        $colList = $this->quoteColumns($columns);
        $holders = ':' . implode(', :', $columns);

        if ($this->driver === 'pgsql') {
            $sql = "INSERT INTO {$this->quoteTable($table)} ({$colList}) VALUES ({$holders}) ON CONFLICT DO NOTHING";
        } else {
            $sql = "INSERT IGNORE INTO {$this->quoteTable($table)} ({$colList}) VALUES ({$holders})";
        }

        return $this->pdo->prepare($sql)->execute($data);
    }

    /**
     * Insere múltiplos registros em lote (batch insert).
     * Retorna o número total de linhas inseridas.
     *
     * @param string $table
     * @param array  $rows  Array de arrays associativos — TODOS com as mesmas chaves
     * @param int    $chunk Registros por INSERT (default 500)
     *
     * @example $this->insertBatch('users', [['name' => 'A', 'email' => 'a@x.com'], ...])
     */
    protected function insertBatch(string $table, array $rows, int $chunk = 500): int
    {
        // Valida input antes de qualquer coisa
        if (empty($rows)) return 0;

        // Filtra linhas nulas/inválidas
        $rows = array_values(array_filter($rows, fn($r) => is_array($r) && !empty($r)));

        if (empty($rows)) return 0;

        $columns = array_keys($rows[0]);
        if (empty($columns)) return 0;

        $colList = $this->quoteColumns($columns);
        $total   = 0;

        foreach (array_chunk($rows, $chunk) as $batch) {
            $placeholders = [];
            $bindings     = [];

            foreach ($batch as $i => $row) {
                $rowParams = [];
                foreach ($columns as $col) {
                    $paramKey            = "{$col}_{$i}";
                    $rowParams[]         = ":{$paramKey}";
                    $bindings[$paramKey] = $row[$col] ?? null;
                }
                $placeholders[] = '(' . implode(', ', $rowParams) . ')';
            }

            $sql  = "INSERT INTO {$this->quoteTable($table)} ({$colList}) VALUES "
                  . implode(', ', $placeholders);

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($bindings);
            $total += $stmt->rowCount();
        }

        return $total;
    }

    // =========================================================
    // TRUNCATE
    // =========================================================

    /**
     * Limpa todos os registros da tabela antes de inserir.
     * MySQL  → desabilita FK checks, TRUNCATE, reabilita
     * PgSQL  → TRUNCATE ... CASCADE
     *
     * @example $this->truncate('users')
     */
    protected function truncate(string $table): void
    {
        $quoted = $this->quoteTable($table);

        if ($this->driver === 'pgsql') {
            $this->pdo->exec("TRUNCATE TABLE {$quoted} RESTART IDENTITY CASCADE");
        } else {
            $this->pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
            $this->pdo->exec("TRUNCATE TABLE {$quoted}");
            $this->pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
        }
    }

    // =========================================================
    // UTILITÁRIOS
    // =========================================================

    /**
     * Executa SQL bruto com bindings opcionais.
     *
     * @example $this->statement("UPDATE users SET verified = 1 WHERE role = 'admin'")
     */
    protected function statement(string $sql, array $bindings = []): bool
    {
        return $this->pdo->prepare($sql)->execute($bindings);
    }

    /**
     * Verifica se um registro já existe.
     *
     * @example $this->exists('users', ['email' => 'admin@x.com'])
     */
    protected function exists(string $table, array $conditions): bool
    {
        $clauses = [];
        foreach (array_keys($conditions) as $col) {
            $clauses[] = $this->quoteCol($col) . " = :{$col}";
        }

        $sql  = "SELECT COUNT(*) FROM {$this->quoteTable($table)} WHERE "
              . implode(' AND ', $clauses);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($conditions);

        return (int) $stmt->fetchColumn() > 0;
    }

    // =========================================================
    // QUOTE HELPERS (MySQL vs PostgreSQL)
    // =========================================================

    /**
     * Envolve um nome de tabela com o delimitador correto.
     * MySQL → `table`   |   PostgreSQL → "table"
     */
    protected function quoteTable(string $table): string
    {
        return $this->driver === 'pgsql'
            ? '"' . $table . '"'
            : '`' . $table . '`';
    }

    /**
     * Envolve um nome de coluna com o delimitador correto.
     */
    protected function quoteCol(string $col): string
    {
        return $this->driver === 'pgsql'
            ? '"' . $col . '"'
            : '`' . $col . '`';
    }

    /**
     * Gera a lista de colunas quoted a partir de um array.
     * Ex: ['name', 'email'] → "`name`, `email`"  (MySQL)
     *                       → '"name", "email"'   (PgSQL)
     */
    protected function quoteColumns(array $columns): string
    {
        return implode(', ', array_map([$this, 'quoteCol'], $columns));
    }
}