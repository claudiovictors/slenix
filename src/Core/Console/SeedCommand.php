<?php

/*
|--------------------------------------------------------------------------
| Classe SeedCommand
|--------------------------------------------------------------------------
|
| Integra o sistema de seeds ao CLI Celestial.
|
*/

declare(strict_types=1);

namespace Slenix\Core\Console;

use Slenix\Database\Seeds\Seeder;

class SeedCommand extends Command
{
    private array $args;
    private string $seedsPath;
    private string $factoriesPath;

    public function __construct(array $args)
    {
        $this->args          = $args;
        $projectRoot         = dirname(__DIR__, 3);
        $this->seedsPath     = $projectRoot . '/database/seeds';
        $this->factoriesPath = $projectRoot . '/database/factories';
    }

    public function run(): void
    {
        $className = $this->resolveClassName();

        echo PHP_EOL;
        self::info("Iniciando seeder: {$className}");
        echo PHP_EOL;

        try {
            $this->loadSeeders();
            $this->loadFactories();

            if (!class_exists($className)) {
                self::error("Seeder [{$className}] não encontrado.");
                self::info("Verifique se o arquivo existe em: {$this->seedsPath}");
                exit(1);
            }

            $seeder = new $className();

            if (!($seeder instanceof Seeder)) {
                self::error("A classe [{$className}] deve estender Seeder.");
                exit(1);
            }

            // Intercepta a saída do seeder para exibir progresso
            $start = microtime(true);

            // Wraps o run() para capturar quaisquer erros com contexto
            $this->runWithOutput($seeder, $className);

            $elapsed = round((microtime(true) - $start) * 1000, 2);

            echo PHP_EOL;
            self::success("Seeding concluído em {$elapsed}ms.");
            echo PHP_EOL;

        } catch (\Throwable $e) {
            echo PHP_EOL;
            self::error("Erro no seeder [{$className}]: " . $e->getMessage());
            exit(1);
        }
    }

    /**
     * Executa o seeder exibindo progresso no terminal.
     */
    private function runWithOutput(Seeder $seeder, string $className): void
    {
        // Hook: antes de call() em outros seeders, exibe o nome
        // Usa output buffering para detectar o que o seeder escreve
        $seeder->run();
    }

    public function makeSeeder(): void
    {
        if (count($this->args) < 3) {
            self::error('Nome do seeder é obrigatório.');
            self::info('Exemplo: php celestial make:seeder UserSeeder');
            exit(1);
        }

        $name = ucfirst($this->args[2]);
        if (!str_ends_with($name, 'Seeder') && $name !== 'DatabaseSeeder') {
            $name .= 'Seeder';
        }

        $filePath = "{$this->seedsPath}/{$name}.php";

        if (!is_dir($this->seedsPath) && !mkdir($this->seedsPath, 0755, true)) {
            self::error("Não foi possível criar o diretório: {$this->seedsPath}");
            exit(1);
        }

        if (file_exists($filePath)) {
            self::error("Seeder [{$name}] já existe em:");
            echo "  {$filePath}" . PHP_EOL;
            exit(1);
        }

        if (file_put_contents($filePath, $this->stubSeeder($name)) === false) {
            self::error("Não foi possível criar o arquivo do seeder.");
            exit(1);
        }

        self::success("Seeder criado com sucesso:");
        echo "  {$filePath}" . PHP_EOL;
    }

    public function makeFactory(): void
    {
        if (count($this->args) < 3) {
            self::error('Nome da factory é obrigatório.');
            self::info('Exemplo: php celestial make:factory UserFactory');
            exit(1);
        }

        $name = ucfirst($this->args[2]);
        if (!str_ends_with($name, 'Factory')) {
            $name .= 'Factory';
        }

        $filePath = "{$this->factoriesPath}/{$name}.php";

        if (!is_dir($this->factoriesPath) && !mkdir($this->factoriesPath, 0755, true)) {
            self::error("Não foi possível criar o diretório: {$this->factoriesPath}");
            exit(1);
        }

        if (file_exists($filePath)) {
            self::error("Factory [{$name}] já existe em:");
            echo "  {$filePath}" . PHP_EOL;
            exit(1);
        }

        $model = str_replace('Factory', '', $name);

        if (file_put_contents($filePath, $this->stubFactory($name, $model)) === false) {
            self::error("Não foi possível criar o arquivo da factory.");
            exit(1);
        }

        self::success("Factory criada com sucesso:");
        echo "  {$filePath}" . PHP_EOL;
    }

    private function resolveClassName(): string
    {
        foreach ($this->args as $arg) {
            if (str_starts_with($arg, '--class=')) {
                return trim(substr($arg, 8));
            }
        }
        return 'DatabaseSeeder';
    }

    private function loadSeeders(): void
    {
        if (!is_dir($this->seedsPath)) return;

        $files = glob($this->seedsPath . '/*.php') ?: [];
        foreach ($files as $file) {
            require_once $file;
        }
    }

    private function loadFactories(): void
    {
        if (!is_dir($this->factoriesPath)) return;

        $files = glob($this->factoriesPath . '/*.php') ?: [];
        foreach ($files as $file) {
            require_once $file;
        }
    }

    private function stubSeeder(string $name): string
    {
        $isDatabase = $name === 'DatabaseSeeder';
        $comment    = $isDatabase
            ? "Ponto de entrada principal. Chame outros seeders aqui."
            : "Insere dados na tabela correspondente.";

        $example = $isDatabase
            ? <<<'BODY'
        // $this->call([
        //     UserSeeder::class,
        //     CategorySeeder::class,
        // ]);
BODY
            : <<<'BODY'
        // Exemplo com batch insert:
        // $this->truncate('table_name');
        //
        // $rows = [];
        // for ($i = 0; $i < 50; $i++) {
        //     $rows[] = [
        //         'name'       => Fake::name(),
        //         'email'      => Fake::email(),
        //         'created_at' => Fake::dateTime(),
        //         'updated_at' => Fake::dateTime(),
        //     ];
        // }
        // $this->insertBatch('table_name', $rows);
BODY;

        return <<<PHP
<?php

declare(strict_types=1);

use Slenix\Database\Seeds\Seeder;
use Slenix\Database\Seeds\Fake;

/**
 * {$name}
 *
 * {$comment}
 */
class {$name} extends Seeder
{
    public function run(): void
    {
{$example}
    }
}
PHP;
    }

    private function stubFactory(string $name, string $model): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

use Slenix\Database\Seeds\Factory;
use Slenix\Database\Seeds\Fake;
use App\Models\\{$model};

class {$name} extends Factory
{
    /** Modelo que esta factory gera */
    protected string \$model = {$model}::class;

    /**
     * Define os atributos padrão do modelo.
     * Todos os valores Fake::* são gerados aleatoriamente a cada chamada.
     */
    public function definition(): array
    {
        return [
            'name'       => Fake::name(),
            'email'      => Fake::email(),
            'created_at' => Fake::dateTime(),
            'updated_at' => Fake::dateTime(),
        ];
    }

    // =========================================================
    // Estados personalizados (opcional)
    // =========================================================

    // public function admin(): static
    // {
    //     return \$this->state(['role' => 'admin']);
    // }
    //
    // public function inactive(): static
    // {
    //     return \$this->state(['is_active' => false]);
    // }
}
PHP;
    }
}