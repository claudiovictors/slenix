<?php

/*
|--------------------------------------------------------------------------
| Classe Migrator
|--------------------------------------------------------------------------
|
| Responsável por descobrir, executar e reverter migrations.
| Mantém um registro na tabela `migrations` para controle de versão.
| Cada migration é executada apenas uma vez (idempotência garantida).
|
| Nota: MySQL faz auto-commit em DDL (CREATE/DROP TABLE), então
| transações em torno de DDL são best-effort — erros de rollback
| são silenciados propositalmente.
|
*/

declare(strict_types=1);

namespace Slenix\Database\Migrations;

use PDO;
use Slenix\Database\Connection;

class Migrator
{
    /** @var PDO|null Conexão lazy — só criada ao executar/reverter */
    protected ?PDO $pdo = null;

    /** @var string Diretório onde ficam as migrations */
    protected string $migrationsPath;

    public function __construct(?string $migrationsPath = null)
    {
        // NÃO conecta ao banco aqui — conexão é lazy
        $this->migrationsPath = $migrationsPath
            ?? dirname(__DIR__, 5) . '/database/migrations';
    }

    /**
     * Retorna a conexão PDO (lazy — só conecta quando necessário).
     */
    protected function pdo(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = Connection::getInstance();
        }
        return $this->pdo;
    }

    // =========================================================
    // TABELA DE CONTROLE
    // =========================================================

    public function ensureMigrationsTableExists(): void
    {
        $this->pdo()->exec("
            CREATE TABLE IF NOT EXISTS `migrations` (
                `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `migration`   VARCHAR(255) NOT NULL,
                `batch`       INT NOT NULL DEFAULT 1,
                `executed_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function getRan(): array
    {
        $stmt = $this->pdo()->query(
            "SELECT `migration` FROM `migrations` ORDER BY `id` ASC"
        );
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function getLastBatchNumber(): int
    {
        $stmt = $this->pdo()->query("SELECT MAX(`batch`) FROM `migrations`");
        return (int) $stmt->fetchColumn();
    }

    // =========================================================
    // DISCOVERY — não precisa de banco
    // =========================================================

    /**
     * @return array<string, string> [nome => caminho_completo]
     */
    public function getMigrationFiles(): array
    {
        if (!is_dir($this->migrationsPath)) {
            return [];
        }

        $files = glob($this->migrationsPath . '/*.php');
        if (!$files) return [];

        sort($files);

        $result = [];
        foreach ($files as $file) {
            $result[basename($file, '.php')] = $file;
        }

        return $result;
    }

    public function getPendingMigrations(): array
    {
        $ran   = $this->getRan();
        $files = $this->getMigrationFiles();
        return array_diff_key($files, array_flip($ran));
    }

    // =========================================================
    // EXECUTAR
    // =========================================================

    /** @return array<string> Nomes das migrations executadas */
    public function run(): array
    {
        $this->ensureMigrationsTableExists();

        $pending = $this->getPendingMigrations();
        if (empty($pending)) return [];

        $batch    = $this->getLastBatchNumber() + 1;
        $executed = [];

        foreach ($pending as $name => $file) {
            $this->runMigration($name, $file, $batch);
            $executed[] = $name;
        }

        return $executed;
    }

    protected function runMigration(string $name, string $file, int $batch): void
    {
        $migration  = $this->resolveMigration($file);
        $inTransaction = false;

        try {
            // MySQL faz auto-commit em DDL, mas tentamos transação
            // para proteger migrações puramente DML
            $this->pdo()->beginTransaction();
            $inTransaction = true;
        } catch (\Throwable) {
            // Driver não suporta ou já há transação ativa — continua sem ela
            $inTransaction = false;
        }

        try {
            $migration->up();
            $this->log($name, $batch);

            if ($inTransaction && $this->pdo()->inTransaction()) {
                $this->pdo()->commit();
            }
        } catch (\Throwable $e) {
            if ($inTransaction && $this->pdo()->inTransaction()) {
                try { $this->pdo()->rollBack(); } catch (\Throwable) {}
            }
            throw new \RuntimeException(
                "Erro ao executar migration [{$name}]: " . $e->getMessage(), 0, $e
            );
        }
    }

    // =========================================================
    // REVERTER
    // =========================================================

    /** @return array<string> */
    public function rollback(int $steps = 1): array
    {
        $this->ensureMigrationsTableExists();

        $lastBatch = $this->getLastBatchNumber();
        if ($lastBatch === 0) return [];

        $batches  = range($lastBatch, max(1, $lastBatch - $steps + 1));
        $reverted = [];

        foreach ($batches as $batch) {
            $stmt = $this->pdo()->prepare(
                "SELECT `migration` FROM `migrations`
                 WHERE `batch` = :batch ORDER BY `id` DESC"
            );
            $stmt->execute(['batch' => $batch]);
            $migrations = $stmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($migrations as $name) {
                $file = $this->migrationsPath . '/' . $name . '.php';
                if (!file_exists($file)) {
                    throw new \RuntimeException(
                        "Arquivo da migration [{$name}] não encontrado em: {$file}"
                    );
                }
                $this->rollbackMigration($name, $file);
                $reverted[] = $name;
            }
        }

        return $reverted;
    }

    /** @return array<string> */
    public function reset(): array
    {
        $this->ensureMigrationsTableExists();

        $stmt       = $this->pdo()->query(
            "SELECT `migration` FROM `migrations` ORDER BY `id` DESC"
        );
        $migrations = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $reverted   = [];

        foreach ($migrations as $name) {
            $file = $this->migrationsPath . '/' . $name . '.php';
            if (file_exists($file)) {
                $this->rollbackMigration($name, $file);
                $reverted[] = $name;
            }
        }

        return $reverted;
    }

    /** @return array{reverted: array, executed: array} */
    public function fresh(): array
    {
        $reverted = $this->reset();
        $executed = $this->run();
        return compact('reverted', 'executed');
    }

    protected function rollbackMigration(string $name, string $file): void
    {
        $migration     = $this->resolveMigration($file);
        $inTransaction = false;

        try {
            $this->pdo()->beginTransaction();
            $inTransaction = true;
        } catch (\Throwable) {
            $inTransaction = false;
        }

        try {
            $migration->down();
            $this->deleteLog($name);

            if ($inTransaction && $this->pdo()->inTransaction()) {
                $this->pdo()->commit();
            }
        } catch (\Throwable $e) {
            if ($inTransaction && $this->pdo()->inTransaction()) {
                try { $this->pdo()->rollBack(); } catch (\Throwable) {}
            }
            throw new \RuntimeException(
                "Erro ao reverter migration [{$name}]: " . $e->getMessage(), 0, $e
            );
        }
    }

    // =========================================================
    // RESOLUÇÃO
    // =========================================================

    protected function resolveMigration(string $file): Migration
    {
        $migration = require $file;

        if (!($migration instanceof Migration)) {
            throw new \RuntimeException(
                "A migration em [{$file}] deve retornar uma instância de Migration. " .
                "Use: return new class extends Migration { ... };"
            );
        }

        return $migration;
    }

    // =========================================================
    // LOG
    // =========================================================

    protected function log(string $name, int $batch): void
    {
        $stmt = $this->pdo()->prepare(
            "INSERT INTO `migrations` (`migration`, `batch`) VALUES (:migration, :batch)"
        );
        $stmt->execute(['migration' => $name, 'batch' => $batch]);
    }

    protected function deleteLog(string $name): void
    {
        $stmt = $this->pdo()->prepare(
            "DELETE FROM `migrations` WHERE `migration` = :migration"
        );
        $stmt->execute(['migration' => $name]);
    }

    // =========================================================
    // UTILITÁRIOS
    // =========================================================

    public function status(): array
    {
        $this->ensureMigrationsTableExists();

        $stmt  = $this->pdo()->query(
            "SELECT `migration`, `batch` FROM `migrations` ORDER BY `id` ASC"
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

    public static function generateName(string $name): string
    {
        return date('Y_m_d_His') . '_' . $name;
    }

    public function getMigrationsPath(): string
    {
        return $this->migrationsPath;
    }
}