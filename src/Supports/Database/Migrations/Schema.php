<?php

/*
|--------------------------------------------------------------------------
| Classe Schema
|--------------------------------------------------------------------------
|
| Facade estática para operações DDL: criação, alteração e remoção de
| tabelas. Internamente usa Blueprint para construir o SQL e Connection
| para executar. Suporta MySQL e PostgreSQL (via detecção de driver).
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Database\Migrations;

use PDO;
use Slenix\Supports\Database\Connection;

class Schema
{
    /**
     * Cria uma nova tabela.
     *
     * @example Schema::create('users', function(Blueprint $table) {
     *     $table->id();
     *     $table->string('name');
     *     $table->timestamps();
     * });
     */
    public static function create(string $table, callable $callback): void
    {
        $blueprint = new Blueprint($table);
        $callback($blueprint);

        $pdo    = Connection::getInstance();
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        $sql = $driver === 'pgsql'
            ? self::buildCreateSqlPgsql($blueprint)
            : self::buildCreateSqlMysql($blueprint);

        $pdo->exec($sql);
    }

    /**
     * Altera uma tabela existente.
     *
     * @example Schema::table('users', function(Blueprint $table) {
     *     $table->string('phone', 20)->nullable()->after('email');
     * });
     */
    public static function table(string $table, callable $callback): void
    {
        $blueprint = new Blueprint($table);
        $callback($blueprint);

        $pdo     = Connection::getInstance();
        $clauses = $blueprint->toAlterClauses();

        if (empty($clauses)) return;

        $sql = "ALTER TABLE `{$table}` " . implode(', ', $clauses);
        $pdo->exec($sql);
    }

    /**
     * Remove a tabela se existir.
     *
     * @example Schema::dropIfExists('users')
     */
    public static function dropIfExists(string $table): void
    {
        $pdo    = Connection::getInstance();
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        // Desabilita checagem de FK temporariamente
        if ($driver !== 'pgsql') {
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        }

        $pdo->exec("DROP TABLE IF EXISTS `{$table}`");

        if ($driver !== 'pgsql') {
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
        }
    }

    /**
     * Remove a tabela (lança erro se não existir).
     */
    public static function drop(string $table): void
    {
        Connection::getInstance()->exec("DROP TABLE `{$table}`");
    }

    /**
     * Verifica se a tabela existe.
     */
    public static function hasTable(string $table): bool
    {
        $pdo    = Connection::getInstance();
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'pgsql') {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = 'public' AND table_name = :table"
            );
        } else {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = :table"
            );
        }

        $stmt->execute(['table' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Verifica se a coluna existe na tabela.
     */
    public static function hasColumn(string $table, string $column): bool
    {
        $pdo    = Connection::getInstance();
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'pgsql') {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = 'public' AND table_name = :table AND column_name = :column"
            );
        } else {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column"
            );
        }

        $stmt->execute(['table' => $table, 'column' => $column]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Renomeia uma tabela.
     */
    public static function rename(string $from, string $to): void
    {
        Connection::getInstance()->exec("RENAME TABLE `{$from}` TO `{$to}`");
    }

    /**
     * Trunca (limpa) uma tabela sem removê-la.
     */
    public static function truncate(string $table): void
    {
        Connection::getInstance()->exec("TRUNCATE TABLE `{$table}`");
    }

    // =========================================================
    // BUILDERS SQL INTERNOS
    // =========================================================

    protected static function buildCreateSqlMysql(Blueprint $blueprint): string
    {
        $table = $blueprint->getTable();
        $body  = $blueprint->toCreateSql();

        return "CREATE TABLE IF NOT EXISTS `{$table}` (\n{$body}\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }

    protected static function buildCreateSqlPgsql(Blueprint $blueprint): string
    {
        // PostgreSQL tem tipos diferentes; essa é uma tradução básica
        $table = $blueprint->getTable();
        $body  = $blueprint->toCreateSql();

        // Substitui tipos MySQL → PostgreSQL
        $body = str_replace(
            ['BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY', 'TINYINT(1)', 'DATETIME'],
            ['BIGSERIAL PRIMARY KEY', 'BOOLEAN', 'TIMESTAMP'],
            $body
        );

        return "CREATE TABLE IF NOT EXISTS \"{$table}\" (\n{$body}\n)";
    }
}