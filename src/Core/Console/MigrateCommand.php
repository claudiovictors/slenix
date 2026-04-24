<?php

/*
|--------------------------------------------------------------------------
| MigrateCommand — Celestial CLI
|--------------------------------------------------------------------------
|
| Integrates the migration system into the Celestial CLI.
| Works with MySQL, PostgreSQL and SQLite through the Grammar-aware
| Migrator and Schema classes.
|
*/

declare(strict_types=1);

namespace Slenix\Core\Console;

use Slenix\Database\Migrations\Migrator;

class MigrateCommand extends Command
{

    /** @var array<int, string> CLI arguments received from argv. */
    private array $args;

    /** @var Migrator Migrator service instance. */
    private Migrator $migrator;

    /**
     * Initialises the command with the CLI argument list and configures
     * the Migrator with the correct migrations directory path.
     *
     * @param array<int, string> $args CLI argument list (argv).
     */
    public function __construct(array $args)
    {
        $this->args     = $args;
        $projectRoot    = dirname(__DIR__, 3);
        $this->migrator = new Migrator($projectRoot . '/database/migrations');
    }

    /**
     * Runs all pending migrations in chronological order.
     *
     * Outputs the name of each executed migration and a final count.
     *
     * @return void
     */
    public function run(): void
    {
        self::info('Running pending migrations...');
        echo PHP_EOL;

        try {
            $executed = $this->migrator->run();

            if (empty($executed)) {
                self::warning('No pending migrations.');
                return;
            }

            foreach ($executed as $name) {
                self::success("  ✔  {$name}");
            }

            echo PHP_EOL;
            self::success(count($executed) . ' migration(s) executed successfully.');

        } catch (\Throwable $e) {
            self::error($e->getMessage());
            exit(1);
        }
    }

    /**
     * Rolls back the last migration batch.
     *
     * Supports --step=N to roll back N batches at once.
     *
     * @return void
     */
    public function rollback(): void
    {
        $steps = 1;

        foreach ($this->args as $arg) {
            if (str_starts_with($arg, '--step=')) {
                $steps = max(1, (int) substr($arg, 7));
            }
        }

        self::info("Rolling back {$steps} batch(es)...");
        echo PHP_EOL;

        try {
            $reverted = $this->migrator->rollback($steps);

            if (empty($reverted)) {
                self::warning('Nothing to rollback.');
                return;
            }

            foreach ($reverted as $name) {
                self::warning("  ✖  {$name}");
            }

            echo PHP_EOL;
            self::success(count($reverted) . ' migration(s) rolled back.');

        } catch (\Throwable $e) {
            self::error($e->getMessage());
            exit(1);
        }
    }

    /**
     * Reverts every migration that has been executed.
     *
     * @return void
     */
    public function reset(): void
    {
        self::warning('Reverting ALL migrations...');
        echo PHP_EOL;

        try {
            $reverted = $this->migrator->reset();

            if (empty($reverted)) {
                self::warning('Nothing to revert.');
                return;
            }

            foreach ($reverted as $name) {
                self::warning("  ✖  {$name}");
            }

            echo PHP_EOL;
            self::success(count($reverted) . ' migration(s) reverted.');

        } catch (\Throwable $e) {
            self::error($e->getMessage());
            exit(1);
        }
    }

    /**
     * Performs a full database reset followed by a fresh migration run.
     * Equivalent to migrate:reset + migrate.
     *
     * @return void
     */
    public function fresh(): void
    {
        self::warning('Running migrate:fresh (reset + migrate)...');
        echo PHP_EOL;

        try {
            $result = $this->migrator->fresh();

            if (!empty($result['reverted'])) {
                self::info('Reverted:');
                foreach ($result['reverted'] as $name) {
                    self::warning("  ✖  {$name}");
                }
                echo PHP_EOL;
            }

            if (!empty($result['executed'])) {
                self::info('Executed:');
                foreach ($result['executed'] as $name) {
                    self::success("  ✔  {$name}");
                }
            } else {
                self::warning('No migrations to execute.');
            }

            echo PHP_EOL;
            self::success('migrate:fresh completed.');

        } catch (\Throwable $e) {
            self::error($e->getMessage());
            exit(1);
        }
    }

    /**
     * Renders the migration status as a formatted ASCII table in the terminal.
     *
     * Columns: STATUS | BATCH | MIGRATION
     *
     * @return void
     */
    public function status(): void
    {
        self::info('Migration Status:');
        echo PHP_EOL;

        try {
            $rows = $this->migrator->status();

            if (empty($rows)) {
                self::warning(
                    'No migrations found in: '
                    . dirname(__DIR__, 3) . '/database/migrations'
                );
                return;
            }

            // ── Column widths ────────────────────────────────────────────────
            $statusWidth    = 10;
            $batchWidth     = 8;
            $migrationWidth = 60;

            foreach ($rows as $row) {
                $migrationWidth = max($migrationWidth, strlen($row['migration']) + 2);
            }

            // ── Separator ────────────────────────────────────────────────────
            $separator = '+'
                . str_repeat('-', $statusWidth    + 2) . '+'
                . str_repeat('-', $batchWidth     + 2) . '+'
                . str_repeat('-', $migrationWidth + 2) . '+';

            // ── Header ───────────────────────────────────────────────────────
            echo $separator . PHP_EOL;
            printf(
                "| %-{$statusWidth}s | %-{$batchWidth}s | %-{$migrationWidth}s |\n",
                'STATUS', 'BATCH', 'MIGRATION'
            );
            echo $separator . PHP_EOL;

            // ── Rows ─────────────────────────────────────────────────────────
            foreach ($rows as $row) {
                $isRan        = $row['status'] === 'Ran';
                $batch        = $row['batch'] ?? '-';
                $statusText   = $isRan ? 'Ran' : 'Pending';
                $statusPadded = str_pad($statusText, $statusWidth);
                $statusColor  = $isRan
                    ? "\033[32m{$statusPadded}\033[0m"  // green
                    : "\033[33m{$statusPadded}\033[0m"; // yellow

                printf(
                    "| %s | %-{$batchWidth}s | %-{$migrationWidth}s |\n",
                    $statusColor,
                    $batch,
                    $row['migration']
                );
            }

            echo $separator . PHP_EOL;

        } catch (\Throwable $e) {
            self::error($e->getMessage());
            exit(1);
        }
    }

    /**
     * Generates a new migration file stub in the migrations directory.
     * The file name is automatically prefixed with the current timestamp.
     *
     * @example php celestial make:migration create_users_table
     * @example php celestial make:migration add_phone_to_users
     * @example php celestial make:migration drop_legacy_table
     *
     * @return void
     */
    public function makeMigration(): void
    {
        if (count($this->args) < 3) {
            self::error('Migration name is required.');
            self::info('Example: php celestial make:migration create_users_table');
            exit(1);
        }

        $rawName  = $this->args[2];
        $name     = Migrator::generateName($rawName);
        $stub     = $this->resolveStub($rawName);
        $dir      = dirname(__DIR__, 3) . '/database/migrations';

        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            self::error("Could not create directory: {$dir}");
            exit(1);
        }

        $filePath = "{$dir}/{$name}.php";

        if (file_exists($filePath)) {
            self::error("Migration '{$name}' already exists.");
            exit(1);
        }

        if (file_put_contents($filePath, $stub) === false) {
            self::error('Could not write migration file.');
            exit(1);
        }

        self::success('Migration created successfully:');
        echo "  {$filePath}" . PHP_EOL;
    }

    /**
     * Selects the appropriate migration stub based on naming conventions.
     *
     * @param string $name Raw migration name (snake_case, no timestamp).
     * @return string PHP source code for the migration file.
     */
    protected function resolveStub(string $name): string
    {
        if (str_starts_with($name, 'create_') && str_ends_with($name, '_table')) {
            $table = substr($name, 7, -6); // strip 'create_' and '_table'
            return $this->stubCreate($table);
        }

        if (
            str_starts_with($name, 'add_')    ||
            str_starts_with($name, 'remove_') ||
            str_contains($name, '_to_')       ||
            str_contains($name, '_from_')
        ) {
            preg_match('/_(?:to|from)_(\w+)$/', $name, $matches);
            return $this->stubAlter($matches[1] ?? 'table_name');
        }

        if (str_starts_with($name, 'drop_') && str_ends_with($name, '_table')) {
            $table = substr($name, 5, -6); // strip 'drop_' and '_table'
            return $this->stubDrop($table);
        }

        return $this->stubBlank();
    }

    /**
     * Returns a CREATE TABLE migration stub.
     *
     * The stub uses Schema::create() which is fully driver-aware
     * (MySQL, PostgreSQL, SQLite).
     *
     * @param string $table Inferred table name.
     * @return string PHP source code.
     */
    protected function stubCreate(string $table): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

use Slenix\Database\Migrations\Migration;
use Slenix\Database\Migrations\Schema;
use Slenix\Database\Migrations\Blueprint;

return new class extends Migration
{
    /**
     * Run the migration — creates the {$table} table.
     *
     * Compatible with MySQL, PostgreSQL and SQLite.
     * Column types are automatically translated by Schema/Grammar.
     */
    public function up(): void
    {
        Schema::create('{$table}', function (Blueprint \$table) {
            \$table->id();
            // \$table->string('name');
            // \$table->string('email')->unique();
            \$table->timestamps();
        });
    }

    /**
     * Reverse the migration — drops the {$table} table.
     */
    public function down(): void
    {
        Schema::dropIfExists('{$table}');
    }
};
PHP;
    }

    /**
     * Returns an ALTER TABLE migration stub.
     *
     * @param string $table Inferred table name.
     * @return string PHP source code.
     */
    protected function stubAlter(string $table): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

use Slenix\Database\Migrations\Migration;
use Slenix\Database\Migrations\Schema;
use Slenix\Database\Migrations\Blueprint;

return new class extends Migration
{
    /**
     * Run the migration — alters the {$table} table.
     *
     * Note: SQLite only supports ADD COLUMN and RENAME COLUMN.
     * MODIFY / CHANGE COLUMN requires table recreation on SQLite.
     */
    public function up(): void
    {
        Schema::table('{$table}', function (Blueprint \$table) {
            // \$table->string('new_column')->nullable()->after('existing_column');
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::table('{$table}', function (Blueprint \$table) {
            // \$table->dropColumn('new_column');
        });
    }
};
PHP;
    }

    /**
     * Returns a DROP TABLE migration stub.
     *
     * @param string $table Inferred table name.
     * @return string PHP source code.
     */
    protected function stubDrop(string $table): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

use Slenix\Database\Migrations\Migration;
use Slenix\Database\Migrations\Schema;
use Slenix\Database\Migrations\Blueprint;

return new class extends Migration
{
    /**
     * Run the migration — drops the {$table} table.
     *
     * Uses Schema::dropIfExists() which automatically handles
     * FK-check disabling (MySQL) and CASCADE (PostgreSQL).
     */
    public function up(): void
    {
        Schema::dropIfExists('{$table}');
    }

    /**
     * Reverse the migration — recreates the {$table} table.
     */
    public function down(): void
    {
        Schema::create('{$table}', function (Blueprint \$table) {
            \$table->id();
            \$table->timestamps();
        });
    }
};
PHP;
    }

    /**
     * Returns a blank migration stub with empty up() and down() methods.
     *
     * @return string PHP source code.
     */
    protected function stubBlank(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

use Slenix\Database\Migrations\Migration;
use Slenix\Database\Migrations\Schema;
use Slenix\Database\Migrations\Blueprint;

return new class extends Migration
{
    /**
     * Run the migration.
     */
    public function up(): void
    {
        //
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        //
    }
};
PHP;
    }
}