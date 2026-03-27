<?php

/*
|--------------------------------------------------------------------------
| Classe Migration (Base)
|--------------------------------------------------------------------------
|
| Classe abstrata base para todas as migrations do Slenix.
| Cada migration deve implementar up() e down().
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Database\Migrations;

abstract class Migration
{
    /**
     * Executa a migration (cria/altera tabelas).
     */
    abstract public function up(): void;

    /**
     * Reverte a migration.
     */
    abstract public function down(): void;
}