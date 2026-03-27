<?php

/*
|--------------------------------------------------------------------------
| Classe MigrateCommand
|--------------------------------------------------------------------------
|
| Integra o sistema de migrations ao CLI Celestial.
| Comandos disponíveis:
|   migrate              — executa migrations pendentes
|   migrate:rollback     — reverte o último batch
|   migrate:reset        — reverte todas
|   migrate:fresh        — reset + migrate
|   migrate:status       — exibe o status de cada migration
|   make:migration       — cria um novo arquivo de migration
|
*/

declare(strict_types=1);

namespace Slenix\Core\Console;

use Slenix\Supports\Database\Migrations\Migrator;

class MigrateCommand extends Command
{
    /**
     * Argumentos recebidos via CLI.
     *
     * @var array
     */
    private array $args;

    /**
     * Instância do migrator responsável pelas migrations.
     *
     * @var Migrator
     */
    private Migrator $migrator;

    /**
     * Construtor da classe.
     *
     * Define os argumentos e inicializa o migrator com o caminho das migrations.
     *
     * @param array $args
     */
    public function __construct(array $args)
    {
        $this->args = $args;

        // Raiz do projeto: src/Core/Console/ → sobe 3 níveis
        $projectRoot = dirname(__DIR__, 3);

        $this->migrator = new Migrator($projectRoot . '/database/migrations');
    }

    /**
     * Executa as migrations pendentes.
     *
     * @return void
     */
    public function run(): void
    {
        self::info('Executando migrations pendentes...');
        echo PHP_EOL;

        try {
            $executed = $this->migrator->run();

            if (empty($executed)) {
                self::warning('Nenhuma migration pendente.');
                return;
            }

            foreach ($executed as $name) {
                self::success("  ✔  {$name}");
            }

            echo PHP_EOL;
            self::success(count($executed) . ' migration(s) executada(s) com sucesso.');
        } catch (\Throwable $e) {
            self::error($e->getMessage());
            exit(1);
        }
    }

    /**
     * Reverte o último batch de migrations.
     *
     * Suporta o parâmetro opcional --step=N.
     *
     * @return void
     */
    public function rollback(): void
    {
        // Suporte a --step=N
        $steps = 1;
        foreach ($this->args as $arg) {
            if (str_starts_with($arg, '--step=')) {
                $steps = max(1, (int) substr($arg, 7));
            }
        }

        self::info("Revertendo {$steps} batch(es)...");
        echo PHP_EOL;

        try {
            $reverted = $this->migrator->rollback($steps);

            if (empty($reverted)) {
                self::warning('Nenhuma migration para reverter.');
                return;
            }

            foreach ($reverted as $name) {
                self::warning("  ✖  {$name}");
            }

            echo PHP_EOL;
            self::success(count($reverted) . ' migration(s) revertida(s).');
        } catch (\Throwable $e) {
            self::error($e->getMessage());
            exit(1);
        }
    }

    /**
     * Reverte todas as migrations executadas.
     *
     * @return void
     */
    public function reset(): void
    {
        self::warning('Revertendo TODAS as migrations...');
        echo PHP_EOL;

        try {
            $reverted = $this->migrator->reset();

            if (empty($reverted)) {
                self::warning('Nenhuma migration para reverter.');
                return;
            }

            foreach ($reverted as $name) {
                self::warning("  ✖  {$name}");
            }

            echo PHP_EOL;
            self::success(count($reverted) . ' migration(s) revertida(s).');
        } catch (\Throwable $e) {
            self::error($e->getMessage());
            exit(1);
        }
    }

    /**
     * Executa um reset completo e roda novamente todas as migrations.
     *
     * @return void
     */
    public function fresh(): void
    {
        self::warning('Executando migrate:fresh (reset + migrate)...');
        echo PHP_EOL;

        try {
            $result = $this->migrator->fresh();

            if (!empty($result['reverted'])) {
                self::info('Migrations revertidas:');
                foreach ($result['reverted'] as $name) {
                    self::warning("  ✖  {$name}");
                }
                echo PHP_EOL;
            }

            if (!empty($result['executed'])) {
                self::info('Migrations executadas:');
                foreach ($result['executed'] as $name) {
                    self::success("  ✔  {$name}");
                }
            } else {
                self::warning('Nenhuma migration para executar.');
            }

            echo PHP_EOL;
            self::success('migrate:fresh concluído.');
        } catch (\Throwable $e) {
            self::error($e->getMessage());
            exit(1);
        }
    }

    /**
     * Exibe o status das migrations em formato de tabela.
     *
     * @return void
     */
    public function status(): void
    {
        self::info('Status das Migrations:');
        echo PHP_EOL;

        try {
            $rows = $this->migrator->status();

            if (empty($rows)) {
                self::warning(
                    'Nenhuma migration encontrada em: ' . dirname(__DIR__, 3) . '/database/migrations'
                );
                return;
            }

            // Cabeçalhos
            $headers = ['STATUS', 'BATCH', 'MIGRATION'];

            // Calcula largura dinâmica
            $statusWidth = 10;
            $batchWidth = 8;
            $migrationWidth = 60;

            foreach ($rows as $row) {
                $migrationWidth = max($migrationWidth, strlen($row['migration']) + 2);
            }

            // Função para linha separadora
            $separator = '+'
                . str_repeat('-', $statusWidth + 2) . '+'
                . str_repeat('-', $batchWidth + 2) . '+'
                . str_repeat('-', $migrationWidth + 2) . '+';

            echo $separator . PHP_EOL;

            // Cabeçalho
            printf(
                "| %-{$statusWidth}s | %-{$batchWidth}s | %-{$migrationWidth}s |\n",
                $headers[0],
                $headers[1],
                $headers[2]
            );

            echo $separator . PHP_EOL;

            // Linhas
            foreach ($rows as $row) {
                $isRan = $row['status'] === 'Ran';

                $status = $isRan
                    ? "\033[32mRan\033[0m"
                    : "\033[33mPending\033[0m";

                $batch = $row['batch'] ?? '-';

                // Texto puro (sem cor)
                $statusText = $isRan ? 'Ran' : 'Pending';

                // Aplica padding ANTES da cor
                $statusPadded = str_pad($statusText, $statusWidth);

                // Agora aplica cor
                $statusColored = $isRan
                    ? "\033[32m{$statusPadded}\033[0m"
                    : "\033[33m{$statusPadded}\033[0m";

                // Render
                printf(
                    "| %s | %-{$batchWidth}s | %-{$migrationWidth}s |\n",
                    $statusColored,
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
     * Cria um novo arquivo de migration.
     *
     * Espera o nome da migration como argumento.
     *
     * @return void
     */
    public function makeMigration(): void
    {
        if (count($this->args) < 3) {
            self::error('Nome da migration é obrigatório.');
            self::info('Exemplo: php celestial make:migration create_users_table');
            exit(1);
        }

        $rawName = $this->args[2];
        $name = Migrator::generateName($rawName);
        $stub = $this->resolveStub($rawName);

        // Raiz do projeto → database/migrations/
        $dir = dirname(__DIR__, 3) . '/database/migrations';

        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            self::error("Não foi possível criar o diretório: {$dir}");
            exit(1);
        }

        $filePath = "{$dir}/{$name}.php";

        if (file_exists($filePath)) {
            self::error("Migration '{$name}' já existe.");
            exit(1);
        }

        if (file_put_contents($filePath, $stub) === false) {
            self::error("Não foi possível criar o arquivo da migration.");
            exit(1);
        }

        self::success("Migration criada com sucesso:");
        echo "  {$filePath}" . PHP_EOL;
    }

    /**
     * Resolve qual stub usar com base no nome da migration.
     *
     * @param string $name
     * @return string
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
            $table = $matches[1] ?? 'table_name';
            return $this->stubAlter($table);
        }

        if (str_starts_with($name, 'drop_') && str_ends_with($name, '_table')) {
            $table = substr($name, 5, -6);
            return $this->stubDrop($table);
        }

        return $this->stubBlank();
    }

    /**
     * Stub para criação de tabela.
     *
     * @param string $table
     * @return string
     */
    protected function stubCreate(string $table): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

use Slenix\Supports\Database\Migrations\Migration;
use Slenix\Supports\Database\Migrations\Schema;
use Slenix\Supports\Database\Migrations\Blueprint;

return new class extends Migration
{
    /**
     * Executa a migration.
     */
    public function up(): void
    {
        Schema::create('{$table}', function (Blueprint \$table) {
            \$table->id();
            // \$table->string('name');
            \$table->timestamps();
        });
    }

    /**
     * Reverte a migration.
     */
    public function down(): void
    {
        Schema::dropIfExists('{$table}');
    }
};
PHP;
    }

    /**
     * Stub para alteração de tabela.
     *
     * @param string $table
     * @return string
     */
    protected function stubAlter(string $table): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

use Slenix\Supports\Database\Migrations\Migration;
use Slenix\Supports\Database\Migrations\Schema;
use Slenix\Supports\Database\Migrations\Blueprint;

return new class extends Migration
{
    /**
     * Executa a migration.
     */
    public function up(): void
    {
        Schema::table('{$table}', function (Blueprint \$table) {
            // \$table->string('new_column')->nullable()->after('existing_column');
        });
    }

    /**
     * Reverte a migration.
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
     * Stub para remoção de tabela.
     *
     * @param string $table
     * @return string
     */
    protected function stubDrop(string $table): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

use Slenix\Supports\Database\Migrations\Migration;
use Slenix\Supports\Database\Migrations\Schema;
use Slenix\Supports\Database\Migrations\Blueprint;

return new class extends Migration
{
    /**
     * Executa a migration.
     */
    public function up(): void
    {
        Schema::dropIfExists('{$table}');
    }

    /**
     * Reverte a migration.
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
     * Stub padrão vazio.
     *
     * @return string
     */
    protected function stubBlank(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

use Slenix\Supports\Database\Migrations\Migration;
use Slenix\Supports\Database\Migrations\Schema;
use Slenix\Supports\Database\Migrations\Blueprint;

return new class extends Migration
{
    /**
     * Executa a migration.
     */
    public function up(): void
    {
        //
    }

    /**
     * Reverte a migration.
     */
    public function down(): void
    {
        //
    }
};
PHP;
    }
}