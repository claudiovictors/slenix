<?php

/*
|--------------------------------------------------------------------------
| SeedCommand Class — Slenix Framework
|--------------------------------------------------------------------------
|
| This class integrates the seeding system into the Celestial CLI.
| It provides tools to populate the database with dummy data using
| Seeders and Factories, essential for testing and development environments.
|
*/

declare(strict_types=1);

namespace Slenix\Core\Console;

use Slenix\Database\Seeds\Seeder;

class SeedCommand extends Command
{
    /** @var array CLI arguments */
    private array $args;
    
    /** @var string Path to the seeders directory */
    private string $seedsPath;
    
    /** @var string Path to the factories directory */
    private string $factoriesPath;

    /**
     * SeedCommand constructor.
     * * @param array $args CLI arguments passed to the script.
     */
    public function __construct(array $args)
    {
        $this->args          = $args;
        $projectRoot         = dirname(__DIR__, 3);
        $this->seedsPath     = $projectRoot . '/database/seeds';
        $this->factoriesPath = $projectRoot . '/database/factories';
    }

    /**
     * Executes the seeder process.
     * * @return void
     */
    public function run(): void
    {
        $className = $this->resolveClassName();

        echo PHP_EOL;
        self::info("Starting seeder: {$className}");
        echo PHP_EOL;

        try {
            $this->loadSeeders();
            $this->loadFactories();

            if (!class_exists($className)) {
                self::error("Seeder [{$className}] not found.");
                self::info("Check if the file exists in: {$this->seedsPath}");
                exit(1);
            }

            $seeder = new $className();

            if (!($seeder instanceof Seeder)) {
                self::error("The class [{$className}] must extend Seeder.");
                exit(1);
            }

            // Capture start time for performance monitoring
            $start = microtime(true);

            // Execute the seeder logic
            $this->runWithOutput($seeder, $className);

            $elapsed = round((microtime(true) - $start) * 1000, 2);

            echo PHP_EOL;
            self::success("Seeding completed in {$elapsed}ms.");
            echo PHP_EOL;

        } catch (\Throwable $e) {
            echo PHP_EOL;
            self::error("Error in seeder [{$className}]: " . $e->getMessage());
            exit(1);
        }
    }

    /**
     * Executes the seeder while displaying progress in the terminal.
     * * @param Seeder $seeder The seeder instance.
     * @param string $className The name of the seeder class.
     * @return void
     */
    private function runWithOutput(Seeder $seeder, string $className): void
    {
        // Executes the run method of the seeder
        $seeder->run();
    }

    /**
     * Creates a new Seeder file.
     * * @return void
     */
    public function makeSeeder(): void
    {
        if (count($this->args) < 3) {
            self::error('Seeder name is required.');
            self::info('Example: php celestial make:seeder UserSeeder');
            exit(1);
        }

        $name = ucfirst($this->args[2]);
        if (!str_ends_with($name, 'Seeder') && $name !== 'DatabaseSeeder') {
            $name .= 'Seeder';
        }

        $filePath = "{$this->seedsPath}/{$name}.php";

        if (!is_dir($this->seedsPath) && !mkdir($this->seedsPath, 0755, true)) {
            self::error("Could not create directory: {$this->seedsPath}");
            exit(1);
        }

        if (file_exists($filePath)) {
            self::error("Seeder [{$name}] already exists at:");
            echo "  {$filePath}" . PHP_EOL;
            exit(1);
        }

        if (file_put_contents($filePath, $this->stubSeeder($name)) === false) {
            self::error("Could not create seeder file.");
            exit(1);
        }

        self::success("Seeder created successfully:");
        echo "  {$filePath}" . PHP_EOL;
    }

    /**
     * Creates a new Factory file.
     * * @return void
     */
    public function makeFactory(): void
    {
        if (count($this->args) < 3) {
            self::error('Factory name is required.');
            self::info('Example: php celestial make:factory UserFactory');
            exit(1);
        }

        $name = ucfirst($this->args[2]);
        if (!str_ends_with($name, 'Factory')) {
            $name .= 'Factory';
        }

        $filePath = "{$this->factoriesPath}/{$name}.php";

        if (!is_dir($this->factoriesPath) && !mkdir($this->factoriesPath, 0755, true)) {
            self::error("Could not create directory: {$this->factoriesPath}");
            exit(1);
        }

        if (file_exists($filePath)) {
            self::error("Factory [{$name}] already exists at:");
            echo "  {$filePath}" . PHP_EOL;
            exit(1);
        }

        $model = str_replace('Factory', '', $name);

        if (file_put_contents($filePath, $this->stubFactory($name, $model)) === false) {
            self::error("Could not create factory file.");
            exit(1);
        }

        self::success("Factory created successfully:");
        echo "  {$filePath}" . PHP_EOL;
    }

    /**
     * Resolves the class name from arguments or defaults to DatabaseSeeder.
     * * @return string
     */
    private function resolveClassName(): string
    {
        foreach ($this->args as $arg) {
            if (str_starts_with($arg, '--class=')) {
                return trim(substr($arg, 8));
            }
        }
        return 'DatabaseSeeder';
    }

    /**
     * Requires all seeder files in the seeds directory.
     * * @return void
     */
    private function loadSeeders(): void
    {
        if (!is_dir($this->seedsPath)) return;

        $files = glob($this->seedsPath . '/*.php') ?: [];
        foreach ($files as $file) {
            require_once $file;
        }
    }

    /**
     * Requires all factory files in the factories directory.
     * * @return void
     */
    private function loadFactories(): void
    {
        if (!is_dir($this->factoriesPath)) return;

        $files = glob($this->factoriesPath . '/*.php') ?: [];
        foreach ($files as $file) {
            require_once $file;
        }
    }

    /**
     * Returns the seeder boilerplate code.
     * * @param string $name Class name.
     * @return string
     */
    private function stubSeeder(string $name): string
    {
        $isDatabase = $name === 'DatabaseSeeder';
        $comment    = $isDatabase
            ? "Main entry point. Call other seeders here."
            : "Inserts data into the corresponding table.";

        $example = $isDatabase
            ? <<<'BODY'
        // $this->call([
        //     UserSeeder::class,
        //     CategorySeeder::class,
        // ]);
BODY
            : <<<'BODY'
        // Example with batch insert:
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
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run(): void
    {
{$example}
    }
}
PHP;
    }

    /**
     * Returns the factory boilerplate code.
     * * @param string $name Class name.
     * @param string $model Model name.
     * @return string
     */
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
    /** @var string The model that this factory generates */
    protected string \$model = {$model}::class;

    /**
     * Define the model's default state.
     * All Fake::* values are randomly generated on each call.
     *
     * @return array
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
    // Custom States (Optional)
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