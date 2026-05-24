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
| Interactive prompts are shown when required arguments are missing,
| styled consistently with the rest of the Slenix CLI.
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
     * @param array<int, string> $args CLI argument list (argv).
     */
    public function __construct(array $args)
    {
        $this->args    = $args;
        $this->pretend = in_array('--pretend', $args, true);
        $this->force   = in_array('--force', $args, true);

        $projectRoot    = dirname(__DIR__, 3);
        $this->migrator = new Migrator($projectRoot . '/database/migrations');

        if ($this->pretend) {
            $this->migrator->setPretend(true);
        }

        if ($this->force) {
            $this->migrator->setPromptHandler(static fn(string $q): string => 'yes');
        }
    }

    // =========================================================================
    // Migration runners (unchanged logic, same output style)
    // =========================================================================

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
     * Rolls back then re-runs all migrations (refresh).
     *
     * @return void
     */
    public function refresh(): void
    {
        $this->ensureDatabase();

        $steps = $this->parseStep(0);

        echo PHP_EOL;

        if (!$this->force && !$this->pretend) {
            $scope = $steps > 0
                ? "the last \033[1m{$steps}\033[0m batch(es)"
                : '\033[1mall\033[0m migrations';

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
     * Drops all tables and re-runs every migration (fresh).
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
     * Renders the migration status table.
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

            $statusWidth    = 10;
            $batchWidth     = 8;
            $migrationWidth = 60;

            foreach ($rows as $row) {
                $migrationWidth = max($migrationWidth, strlen($row['migration']) + 2);
            }

            $total   = count($rows);
            $ran     = count(array_filter($rows, fn($r) => $r['status'] === 'Ran'));
            $pending = $total - $ran;

            $separator = '+'
                . str_repeat('-', $statusWidth + 2) . '+'
                . str_repeat('-', $batchWidth + 2) . '+'
                . str_repeat('-', $migrationWidth + 2) . '+';

            echo $separator . PHP_EOL;
            printf(
                "| %-{$statusWidth}s | %-{$batchWidth}s | %-{$migrationWidth}s |\n",
                'STATUS', 'BATCH', 'MIGRATION'
            );
            echo $separator . PHP_EOL;

            foreach ($rows as $row) {
                $isRan        = $row['status'] === 'Ran';
                $batch        = $row['batch'] ?? '-';
                $statusText   = $isRan ? 'Ran' : 'Pending';
                $statusPadded = str_pad($statusText, $statusWidth);
                $statusColor  = $isRan
                    ? "\033[32m{$statusPadded}\033[0m"
                    : "\033[33m{$statusPadded}\033[0m";

                printf(
                    "| %s | %-{$batchWidth}s | %-{$migrationWidth}s |\n",
                    $statusColor, $batch, $row['migration']
                );
            }

            echo $separator . PHP_EOL;
            echo PHP_EOL;
            self::info("  Total: {$total}  |  \033[32mRan: {$ran}\033[0m  |  \033[33mPending: {$pending}\033[0m");
            echo PHP_EOL;

        } catch (\Throwable $e) {
            self::error($e->getMessage());
            exit(1);
        }
    }

    // =========================================================================
    // make:migration — with interactive prompt
    // =========================================================================

    /**
     * Generates a new migration file stub.
     *
     * When called without a name argument, an interactive prompt is shown:
     *
     *   ┌ What should the migration be named? ──────────────────┐
     *   │ E.g. create_users_table                               │
     *   └───────────────────────────────────────────────────────┘
     *
     * @return void
     */
    public function makeMigration(): void
    {
        // Resolve the migration name: CLI arg or interactive prompt
        $rawName = $this->resolveArgument(
            index:       2,
            question:    'What should the migration be named?',
            placeholder: 'E.g. create_users_table',
            example:     'php celestial make:migration create_users_table'
        );

        $name  = Migrator::generateName($rawName);
        $stub  = $this->resolveStub($rawName);
        $dir   = dirname(__DIR__, 3) . '/database/migrations';

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
        self::success('Migration created successfully.');
        echo PHP_EOL;
        echo '  ' . self::console()->muted($filePath) . PHP_EOL;
        echo PHP_EOL;
    }

    // =========================================================================
    // Stubs (unchanged from original)
    // =========================================================================

    /**
     * Selects the appropriate migration stub based on naming conventions.
     *
     * @param string $name Raw migration name.
     *
     * @return string PHP source code.
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

    /** @return string */
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
    public function up(): void
    {
        Schema::create('{$table}', function (Blueprint \$table) {
            \$table->id();
            // \$table->string('name');
            // \$table->string('email')->unique();
            \$table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('{$table}');
    }
};
PHP;
    }

    /** @return string */
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
    public function up(): void
    {
        Schema::table('{$table}', function (Blueprint \$table) {
            // \$table->string('new_column')->nullable()->after('existing_column');
        });
    }

    public function down(): void
    {
        Schema::table('{$table}', function (Blueprint \$table) {
            // \$table->dropColumn('new_column');
        });
    }
};
PHP;
    }

    /** @return string */
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
    public function up(): void
    {
        Schema::dropIfExists('{$table}');
    }

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

    /** @return string */
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
    public function up(): void
    {
        //
    }

    public function down(): void
    {
        //
    }
};
PHP;
    }

    // =========================================================================
    // Interactive argument resolution
    // =========================================================================

    /**
     * Returns the CLI argument at the given index, or prompts the user.
     *
     * @param int    $index       Index in $this->args.
     * @param string $question    Prompt question.
     * @param string $placeholder Hint text inside the box.
     * @param string $example     Example shown on empty input.
     *
     * @return string
     */
    private function resolveArgument(
        int    $index,
        string $question,
        string $placeholder = '',
        string $example = ''
    ): string {
        if (isset($this->args[$index]) && trim($this->args[$index]) !== '') {
            return $this->args[$index];
        }

        $prompt = new Prompt();
        $value  = $prompt->text($question, $placeholder);

        if (trim($value) === '') {
            echo PHP_EOL;
            self::error('A name is required.');
            if ($example !== '') {
                self::info("Example: {$example}");
            }
            echo PHP_EOL;
            exit(1);
        }

        return $value;
    }

    // =========================================================================
    // Database helpers (unchanged)
    // =========================================================================

    /**
     * Probes the configured database and offers to create it if missing.
     *
     * @return void
     */
    private function ensureDatabase(): void
    {
        if ($this->pretend) {
            return;
        }

        $config = $this->resolveDatabaseConfig();
        $error  = DatabaseCreator::probe($config);

        if ($error === null) {
            return;
        }

        if (!DatabaseCreator::isMissingDatabase($error)) {
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
     * Builds the database config array from environment variables.
     *
     * @return array
     */
    private function resolveDatabaseConfig(): array
    {
        return [
            'driver'    => env('DB_CONNECTION') ?? 'mysql',
            'host'      => env('DB_HOST') ?? '127.0.0.1',
            'port'      => (int) env('DB_PORT') ?? 3306,
            'database'  => env('DB_DATABASE') ?? '',
            'username'  => env('DB_USERNAME') ?? '',
            'password'  => env('DB_PASSWORD') ?? '',
            'charset'   => env('DB_CHARSET') ?? 'utf8mb4',
            'collation' => env('DB_COLLATION') ?? 'utf8mb4_unicode_ci',
        ];
    }

    /**
     * Parses the --step=N flag.
     *
     * @param int $default Fallback when the flag is absent.
     *
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
     * Prints a yes/no confirmation prompt.
     *
     * @param string $question
     *
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
     * Prints the pretend-mode SQL log.
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