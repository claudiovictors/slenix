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
use Slenix\Database\Migrations\DatabaseCreator;

class MigrateCommand extends Command
{

    /** @var array<int, string> CLI arguments received from argv. */
    private array $args;

    /** @var Migrator Migrator service instance. */
    private Migrator $migrator;

    /** @var bool Whether --pretend flag is present. */
    private bool $pretend;

    /** @var bool Whether --force flag is present (skip confirmations). */
    private bool $force;

    /**
     * Initialises the command with the CLI argument list and configures
     * the Migrator with the correct migrations directory path.
     *
     * @param array<int, string> $args CLI argument list (argv).
     */
    public function __construct(array $args)
    {
        $this->args = $args;
        $this->pretend = in_array('--pretend', $args, true);
        $this->force = in_array('--force', $args, true);

        $projectRoot = dirname(__DIR__, 3);
        $this->migrator = new Migrator($projectRoot . '/database/migrations');

        if ($this->pretend) {
            $this->migrator->setPretend(true);
        }

        // When --force is set, auto-confirm any interactive prompts
        if ($this->force) {
            $this->migrator->setPromptHandler(static fn(string $q): string => 'yes');
        }
    }

    /**
     * Runs all pending migrations in chronological order.
     *
     * @return void
     */
    public function run(): void
    {
        $this->ensureDatabase();

        echo PHP_EOL;

        if ($this->pretend) {
            self::info('[PRETEND MODE] No changes will be made to the database.');
            echo PHP_EOL;
        }

        self::info('Running pending migrations...');
        echo PHP_EOL;

        try {
            $executed = $this->migrator->run();

            if (empty($executed)) {
                self::warning('No pending migrations.');
                echo PHP_EOL;
                return;
            }

            foreach ($executed as $name) {
                self::success("  ✔  {$name}");
            }

            echo PHP_EOL;

            if ($this->pretend) {
                self::info(count($executed) . ' migration(s) would be executed.');
                $this->dumpPretendLog();
            } else {
                self::success(count($executed) . ' migration(s) executed successfully.');
            }

            echo PHP_EOL;

        } catch (\Throwable $e) {
            self::error($e->getMessage());
            exit(1);
        }
    }

    /**
     * Rolls back the last migration batch.
     * Supports --step=N to roll back N batches at once.
     *
     * @return void
     */
    public function rollback(): void
    {
        $this->ensureDatabase();

        $steps = $this->parseStep(1);

        echo PHP_EOL;

        if (!$this->force && !$this->pretend) {
            if (!$this->confirm("  You are about to roll back \033[1m{$steps}\033[0m batch(es). Continue?")) {
                self::warning('Rollback cancelled.');
                echo PHP_EOL;
                return;
            }
        }

        if ($this->pretend) {
            self::info('[PRETEND MODE] No changes will be made to the database.');
            echo PHP_EOL;
        }

        self::info("Rolling back {$steps} batch(es)...");
        echo PHP_EOL;

        try {
            $reverted = $this->migrator->rollback($steps);

            if (empty($reverted)) {
                self::warning('Nothing to rollback.');
                echo PHP_EOL;
                return;
            }

            foreach ($reverted as $name) {
                self::warning("  ↩  {$name}");
            }

            echo PHP_EOL;

            if ($this->pretend) {
                self::info(count($reverted) . ' migration(s) would be rolled back.');
                $this->dumpPretendLog();
            } else {
                self::success(count($reverted) . ' migration(s) rolled back.');
            }

            echo PHP_EOL;

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
        $this->ensureDatabase();

        echo PHP_EOL;

        if (!$this->force && !$this->pretend) {
            if (!$this->confirm('  \033[31mThis will revert ALL migrations.\033[0m Continue?')) {
                self::warning('Reset cancelled.');
                echo PHP_EOL;
                return;
            }
        }

        if ($this->pretend) {
            self::info('[PRETEND MODE] No changes will be made to the database.');
            echo PHP_EOL;
        }

        self::warning('Reverting ALL migrations...');
        echo PHP_EOL;

        try {
            $reverted = $this->migrator->reset();

            if (empty($reverted)) {
                self::warning('Nothing to revert.');
                echo PHP_EOL;
                return;
            }

            foreach ($reverted as $name) {
                self::warning("  ↩  {$name}");
            }

            echo PHP_EOL;

            if ($this->pretend) {
                self::info(count($reverted) . ' migration(s) would be reverted.');
                $this->dumpPretendLog();
            } else {
                self::success(count($reverted) . ' migration(s) reverted.');
            }

            echo PHP_EOL;

        } catch (\Throwable $e) {
            self::error($e->getMessage());
            exit(1);
        }
    }

    /**
     * Rolls back the last N batches (or all) then re-runs all migrations.
     * Supports --step=N to limit the rollback scope.
     *
     * @return void
     */
    public function refresh(): void
    {
        $this->ensureDatabase();

        $steps = $this->parseStep(0); // 0 = full reset

        echo PHP_EOL;

        if (!$this->force && !$this->pretend) {
            $scope = $steps > 0 ? "the last \033[1m{$steps}\033[0m batch(es)" : '\033[1mall\033[0m migrations';
            if (!$this->confirm("  This will roll back {$scope} and re-run. Continue?")) {
                self::warning('Refresh cancelled.');
                echo PHP_EOL;
                return;
            }
        }

        if ($this->pretend) {
            self::info('[PRETEND MODE] No changes will be made to the database.');
            echo PHP_EOL;
        }

        self::warning('Running migrate:refresh...');
        echo PHP_EOL;

        try {
            $result = $this->migrator->refresh($steps);

            if (!empty($result['reverted'])) {
                self::info('  Rolled back:');
                foreach ($result['reverted'] as $name) {
                    self::warning("    ↩  {$name}");
                }
                echo PHP_EOL;
            }

            if (!empty($result['executed'])) {
                self::info('  Executed:');
                foreach ($result['executed'] as $name) {
                    self::success("    ✔  {$name}");
                }
            } else {
                self::warning('  No migrations to re-run.');
            }

            echo PHP_EOL;

            if ($this->pretend) {
                $this->dumpPretendLog();
            } else {
                self::success('migrate:refresh completed.');
            }

            echo PHP_EOL;

        } catch (\Throwable $e) {
            self::error($e->getMessage());
            exit(1);
        }
    }

    /**
     * Performs a full database reset followed by a fresh migration run.
     * Equivalent to migrate:reset + migrate.
     * Pass --seed to also run the database seeder after.
     *
     * @return void
     */
    public function fresh(): void
    {
        $this->ensureDatabase();

        echo PHP_EOL;

        if (!$this->force && !$this->pretend) {
            if (!$this->confirm('  \033[31mThis will drop all tables and re-run every migration.\033[0m Continue?')) {
                self::warning('Fresh cancelled.');
                echo PHP_EOL;
                return;
            }
        }

        if ($this->pretend) {
            self::info('[PRETEND MODE] No changes will be made to the database.');
            echo PHP_EOL;
        }

        self::warning('Running migrate:fresh (reset + migrate)...');
        echo PHP_EOL;

        try {
            $result = $this->migrator->fresh();

            if (!empty($result['reverted'])) {
                self::info('  Reverted:');
                foreach ($result['reverted'] as $name) {
                    self::warning("    ↩  {$name}");
                }
                echo PHP_EOL;
            }

            if (!empty($result['executed'])) {
                self::info('  Executed:');
                foreach ($result['executed'] as $name) {
                    self::success("    ✔  {$name}");
                }
            } else {
                self::warning('  No migrations to execute.');
            }

            echo PHP_EOL;

            if ($this->pretend) {
                $this->dumpPretendLog();
            } else {
                self::success('migrate:fresh completed.');
            }

            echo PHP_EOL;

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
        $this->ensureDatabase();

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
            $statusWidth = 10;
            $batchWidth = 8;
            $migrationWidth = 60;

            foreach ($rows as $row) {
                $migrationWidth = max($migrationWidth, strlen($row['migration']) + 2);
            }

            $total = count($rows);
            $ran = count(array_filter($rows, fn($r) => $r['status'] === 'Ran'));
            $pending = $total - $ran;

            // ── Separator ────────────────────────────────────────────────────
            $separator = '+'
                . str_repeat('-', $statusWidth + 2) . '+'
                . str_repeat('-', $batchWidth + 2) . '+'
                . str_repeat('-', $migrationWidth + 2) . '+';

            // ── Header ───────────────────────────────────────────────────────
            echo $separator . PHP_EOL;
            printf(
                "| %-{$statusWidth}s | %-{$batchWidth}s | %-{$migrationWidth}s |\n",
                'STATUS',
                'BATCH',
                'MIGRATION'
            );
            echo $separator . PHP_EOL;

            // ── Rows ─────────────────────────────────────────────────────────
            foreach ($rows as $row) {
                $isRan = $row['status'] === 'Ran';
                $batch = $row['batch'] ?? '-';
                $statusText = $isRan ? 'Ran' : 'Pending';
                $statusPadded = str_pad($statusText, $statusWidth);
                $statusColor = $isRan
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
            echo PHP_EOL;

            // ── Summary ───────────────────────────────────────────────────────
            self::info("  Total: {$total}  |  \033[32mRan: {$ran}\033[0m  |  \033[33mPending: {$pending}\033[0m");
            echo PHP_EOL;

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

        $rawName = $this->args[2];
        $name = Migrator::generateName($rawName);
        $stub = $this->resolveStub($rawName);
        $dir = dirname(__DIR__, 3) . '/database/migrations';

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

        echo PHP_EOL;
        self::success('Migration created successfully:');
        echo PHP_EOL;
        echo "  \033[90m{$filePath}\033[0m" . PHP_EOL;
        echo PHP_EOL;
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
            $table = substr($name, 7, -6);
            return $this->stubCreate($table);
        }

        if (
            str_starts_with($name, 'add_') ||
            str_starts_with($name, 'remove_') ||
            str_contains($name, '_to_') ||
            str_contains($name, '_from_')
        ) {
            preg_match('/_(?:to|from)_(\w+)$/', $name, $matches);
            return $this->stubAlter($matches[1] ?? 'table_name');
        }

        if (str_starts_with($name, 'drop_') && str_ends_with($name, '_table')) {
            $table = substr($name, 5, -6);
            return $this->stubDrop($table);
        }

        return $this->stubBlank();
    }

    /**
     * Returns a CREATE TABLE migration stub.
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
            // \$table->string('password');
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

    /**
     * Probes the configured database and, when it does not exist, offers to
     * create it interactively. Aborts with exit(1) if the user declines or if
     * creation fails. Propagates any non-"missing database" PDO exception so
     * the caller can handle credential / host / driver errors normally.
     *
     * Respects --force (auto-confirms) and --pretend (skips the probe entirely,
     * since pretend mode never touches the database).
     *
     * @return void
     */
    private function ensureDatabase(): void
    {
        // Pretend mode never connects to the database — nothing to check.
        if ($this->pretend) {
            return;
        }

        $config = $this->resolveDatabaseConfig();
        $error = DatabaseCreator::probe($config);

        if ($error === null) {
            return; // database is reachable
        }

        if (!DatabaseCreator::isMissingDatabase($error)) {
            // Connection error unrelated to a missing database (bad credentials,
            // wrong host, unsupported driver, etc.) — let it bubble up.
            throw $error;
        }

        $dbName = $config['database'] ?? 'unknown';
        $driver = strtoupper($config['driver'] ?? 'mysql');

        echo PHP_EOL;
        self::warning("  [{$driver}] Database \"{$dbName}\" not found.");
        echo PHP_EOL;

        if (!$this->confirm("  Would you like to create it now?")) {
            self::error('Aborted — database does not exist.');
            echo PHP_EOL;
            exit(1);
        }

        try {
            DatabaseCreator::create($config);
            self::success("  Database \"{$dbName}\" created successfully.");
            echo PHP_EOL;
        } catch (\Throwable $e) {
            self::error('Failed to create database: ' . $e->getMessage());
            echo PHP_EOL;
            exit(1);
        }
    }

    /**
     * Builds the database configuration array from environment variables.
     *
     * Falls back to sensible defaults so the command works even when some
     * variables are not defined (e.g. during generator-only usage).
     *
     * @return array{
     *   driver: string,
     *   host: string,
     *   port: int,
     *   database: string,
     *   username: string,
     *   password: string,
     *   charset: string,
     *   collation: string,
     * }
     */
    private function resolveDatabaseConfig(): array
    {
        return [
            'driver' => $_ENV['DB_CONNECTION'] ?? 'mysql',
            'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
            'port' => (int) ($_ENV['DB_PORT'] ?? 3306),
            'database' => $_ENV['DB_DATABASE'] ?? '',
            'username' => $_ENV['DB_USERNAME'] ?? '',
            'password' => $_ENV['DB_PASSWORD'] ?? '',
            'charset' => $_ENV['DB_CHARSET'] ?? 'utf8mb4',
            'collation' => $_ENV['DB_COLLATION'] ?? 'utf8mb4_unicode_ci',
        ];
    }

    // =========================================================================
    // Internal helpers
    // =========================================================================

    /**
     * Parses the --step=N flag from the argument list.
     *
     * @param int $default Value to return when the flag is absent.
     * @return int
     */
    private function parseStep(int $default): int
    {
        foreach ($this->args as $arg) {
            if (str_starts_with($arg, '--step=')) {
                return max(1, (int) substr($arg, 7));
            }
        }

        return $default;
    }

    /**
     * Prints a yes/no confirmation prompt and returns the boolean result.
     * Always returns true when --force is active.
     *
     * @param string $question Question to display.
     * @return bool
     */
    private function confirm(string $question): bool
    {
        if ($this->force) {
            return true;
        }

        echo PHP_EOL;
        echo $question . " \033[90m[yes/no]\033[0m: ";
        $answer = strtolower(trim((string) fgets(STDIN)));
        echo PHP_EOL;

        return in_array($answer, ['y', 'yes', 's', 'sim'], true);
    }

    /**
     * Prints the collected pretend-mode SQL log.
     *
     * @return void
     */
    private function dumpPretendLog(): void
    {
        $log = $this->migrator->getPretendLog();

        if (empty($log)) {
            return;
        }

        self::info('  SQL that would be executed:');
        echo PHP_EOL;

        foreach ($log as $sql) {
            echo "  \033[90m" . $sql . "\033[0m" . PHP_EOL;
        }

        echo PHP_EOL;
    }
}