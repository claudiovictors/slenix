<?php

/*
|--------------------------------------------------------------------------
| Migration — Abstract Base Class
|--------------------------------------------------------------------------
|
| Every migration in the application must extend this class and implement
| the up() and down() methods.
|
| Use Schema::create(), Schema::table() and Schema::dropIfExists() inside
| these methods. The Schema facade automatically adapts the generated SQL
| to the active database driver (MySQL, PostgreSQL, SQLite).
|
*/

declare(strict_types=1);

namespace Slenix\Database\Migrations;

abstract class Migration
{
    /**
     * The database connection that should be used by the migration.
     * When null, the default connection is used.
     *
     * @var string|null
     */
    public ?string $connection = null;

    /**
     * Whether the migration should run within a transaction.
     * Set to false for DDL-heavy migrations on MySQL where auto-commit applies.
     *
     * @var bool
     */
    public bool $withinTransaction = true;

    /**
     * Applies the migration (creates or alters tables, inserts seed data, etc.).
     *
     * @return void
     */
    abstract public function up(): void;

    /**
     * Reverts the migration, restoring the database to its previous state.
     *
     * @return void
     */
    abstract public function down(): void;
}