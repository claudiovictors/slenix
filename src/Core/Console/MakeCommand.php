<?php

/*
|--------------------------------------------------------------------------
| MakeCommand Class — Slenix Framework
|--------------------------------------------------------------------------
|
| This class is responsible for generating various framework components
| such as Models, Controllers, Middlewares, Jobs and built-in security
| middleware stubs. It also handles environment configurations such as
| secure key generation.
|
| Part of the Celestial CLI, it automates boilerplate creation to
| accelerate development while maintaining a consistent project structure.
|
*/

declare(strict_types=1);

namespace Slenix\Core\Console;

class MakeCommand extends Command
{
    /**
     * CLI arguments passed to the command.
     *
     * @var array
     */
    private array $args;

    /**
     * @param array $args Command-line arguments received from the CLI entry point.
     */
    public function __construct(array $args)
    {
        $this->args = $args;
    }

    /**
     * Generates a cryptographically secure APP_KEY and persists it to .env.
     *
     * @return void
     */
    public static function generateKey(): void
    {
        $envPath = self::basePath('.env');
        $examplePath = self::basePath('.env.example');

        if (!file_exists($envPath)) {
            if (file_exists($examplePath)) {
                if (!copy($examplePath, $envPath)) {
                    echo PHP_EOL;
                    self::error("Could not create .env from .env.example.");
                    return;
                }
                echo PHP_EOL;
                self::info(".env created from .env.example.");
            } else {
                echo PHP_EOL;
                self::error(".env file not found. Please create it first.");
                return;
            }
        }

        $key = 'base64:' . base64_encode(random_bytes(32));
        $content = file_get_contents($envPath);

        if ($content === false) {
            echo PHP_EOL;
            self::error("Could not read .env file.");
            return;
        }

        if (preg_match('/^APP_KEY\s*=.*$/m', $content)) {
            $updated = preg_replace('/^(APP_KEY\s*=).*$/m', "APP_KEY={$key}", $content);
        } else {
            $updated = preg_replace(
                '/^(APP_NAME\s*=.*)$/m',
                "$1\nAPP_KEY={$key}",
                $content,
                1
            );
        }

        if ($updated === null || $updated === $content) {
            echo PHP_EOL;
            self::error("Could not update APP_KEY in .env.");
            return;
        }

        if (file_put_contents($envPath, $updated) === false) {
            echo PHP_EOL;
            self::error("Could not write to .env.");
            return;
        }

        echo PHP_EOL;
        self::success("APP_KEY generated and saved to .env.");
        echo PHP_EOL;
        echo '  ' . self::console()->muted($key) . PHP_EOL;
        echo PHP_EOL;
    }

    /**
     * Generates a secure random key for JWT_SECRET and updates the .env file.
     *
     * @return void
     */
    public static function generateJwt(): void
    {
        $key = bin2hex(random_bytes(32));
        $envPath = self::basePath('.env');

        if (!file_exists($envPath)) {
            self::error("The .env file was not found. Please create one first.");
            return;
        }

        $content = file_get_contents($envPath);
        $pattern = "/^JWT_SECRET\s*=.*$/m";
        $replacement = "JWT_SECRET={$key}";

        if (preg_match($pattern, $content)) {
            $updated = preg_replace($pattern, $replacement, $content);
        } else {
            $updated = $content . "\nJWT_SECRET={$key}";
        }

        if (file_put_contents($envPath, $updated) !== false) {
            echo PHP_EOL;
            self::success("JWT Secret Key generated successfully!");
            self::info("Key: {$key}");
            echo PHP_EOL;
        } else {
            self::error("Failed to write to .env file.");
        }
    }

    /**
     * Generates a new Eloquent-style Model class.
     *
     * When called without a name argument, an interactive prompt is shown:
     *
     *   ┌ What should the model be named? ──────────────────────┐
     *   │ E.g. User                                             │
     *   └───────────────────────────────────────────────────────┘
     *
     * @return void
     */
    public function makeModel(): void
    {
        $name = $this->resolveArgument(
            index: 2,
            question: 'What should the model be named?',
            placeholder: 'E.g. User',
            example: 'php celestial make:model User'
        );

        $modelName = ucfirst($name);
        $tableName = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $modelName)) . 's';
        $filePath = APP_PATH . '/Models/' . $modelName . '.php';

        $this->ensureFileDoesNotExist($filePath, $modelName, 'Model');

        $template = <<<EOT
<?php

declare(strict_types=1);

namespace App\Models;

use Slenix\Database\Model;

class {$modelName} extends Model
{
    protected string \$table = '{$tableName}';
    protected string \$primaryKey = 'id';
    protected array \$fillable = [];
}
EOT;

        $this->createFile($filePath, $template, $modelName, 'Model');
    }

    /**
     * Generates a new HTTP Controller class.
     *
     * When called without arguments the user is prompted for:
     *   1. Controller name
     *   2. Controller type (Empty / Resource / API / Invokable)
     *
     *   ┌ What should the controller be named? ─────────────────┐
     *   │ E.g. UserController                                   │
     *   └───────────────────────────────────────────────────────┘
     *
     *
     * @return void
     */
    public function makeController(): void
    {
        // ── Name ─────────────────────────────────────────────────────────────
        $rawName = $this->resolveArgumentSkipFlags(
            question: 'What should the controller be named?',
            placeholder: 'E.g. UserController',
            example: 'php celestial make:controller Home'
        );

        $controllerName = ucfirst($rawName);

        if (!str_ends_with($controllerName, 'Controller')) {
            $controllerName .= 'Controller';
        }

        // ── Type ──────────────────────────────────────────────────────────────
        $types = ['Empty', 'Resource', 'API', 'Invokable'];

        // 1. Shorthand boolean flags: --resource, --api, --invokable, --empty
        $shorthand = [
            '--resource' => 'Resource',
            '--api' => 'API',
            '--invokable' => 'Invokable',
            '--empty' => 'Empty',
        ];

        $type = null;

        foreach ($shorthand as $flag => $label) {
            if (in_array($flag, $this->args, true)) {
                $type = $label;
                break;
            }
        }

        // 2. Explicit --type=Resource
        if ($type === null) {
            $typeArg = $this->findFlag('--type=');

            if ($typeArg !== null) {
                $type = ucfirst(strtolower($typeArg));
                if (!in_array($type, $types, true)) {
                    $type = 'Empty';
                }
            }
        }

        // 3. Nothing passed — ask interactively
        if ($type === null) {
            $prompt = new Prompt();
            $type = $prompt->select(
                'Which type of controller would you like?',
                $types
            );
        }

        $filePath = APP_PATH . '/Controllers/' . $controllerName . '.php';

        $this->ensureFileDoesNotExist($filePath, $controllerName, 'Controller');

        $template = $this->controllerStub($controllerName, $type);

        $this->createFile($filePath, $template, $controllerName, 'Controller');
    }

    /**
     * Generates a blank Middleware class.
     *
     * When called without a name argument, an interactive prompt is shown:
     *
     *   ┌ What should the middleware be named? ─────────────────┐
     *   │ E.g. Auth                                             │
     *   └───────────────────────────────────────────────────────┘
     *
     * @return void
     */
    public function makeMiddleware(): void
    {
        $name = $this->resolveArgument(
            index: 2,
            question: 'What should the middleware be named?',
            placeholder: 'E.g. Auth',
            example: 'php celestial make:middleware Auth'
        );

        $middlewareName = ucfirst($name);

        if (!str_ends_with($middlewareName, 'Middleware')) {
            $middlewareName .= 'Middleware';
        }

        $filePath = APP_PATH . '/Middlewares/' . $middlewareName . '.php';

        $this->ensureFileDoesNotExist($filePath, $middlewareName, 'Middleware');

        $template = <<<EOT
<?php
/*
|--------------------------------------------------------------------------
| {$middlewareName} Class
|--------------------------------------------------------------------------
|
| This middleware [describe the middleware functionality here].
|
*/

declare(strict_types=1);

namespace App\Middlewares;

use Slenix\Http\Request;
use Slenix\Http\Response;
use Slenix\Http\Middlewares\Middleware;

class {$middlewareName} implements Middleware
{
    /**
     * Handle an incoming request.
     *
     * @param Request \$request  The HTTP request.
     * @param Response \$response The HTTP response.
     * @param callable \$next     The next handler in the pipeline.
     * @return mixed
     */
    public function handle(Request \$request, Response \$response, callable \$next): mixed
    {
        // Middleware logic here

        return \$next(\$request, \$response);
    }
}
EOT;

        $this->createFile($filePath, $template, $middlewareName, 'Middleware');
    }

    /**
     * Generates a new Job class.
     *
     * When called without a name argument, an interactive prompt is shown:
     *
     *   ┌ What should the job be named? ────────────────────────┐
     *   │ E.g. SendWelcomeEmail                                 │
     *   └───────────────────────────────────────────────────────┘
     *
     * @return void
     */
    public function makeJob(): void
    {
        $name = $this->resolveArgument(
            index: 2,
            question: 'What should the job be named?',
            placeholder: 'E.g. SendWelcomeEmail',
            example: 'php celestial make:job SendWelcomeEmail'
        );

        $jobName = ucfirst($name);

        if (!str_ends_with($jobName, 'Job')) {
            $jobName .= 'Job';
        }

        $dir = APP_PATH . '/Jobs';
        $filePath = $dir . '/' . $jobName . '.php';

        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            self::error("Could not create directory {$dir}.");
            exit(1);
        }

        $this->ensureFileDoesNotExist($filePath, $jobName, 'Job');

        $template = <<<EOT
<?php

declare(strict_types=1);

namespace App\Jobs;

use Slenix\Supports\Queue\Job;

class {$jobName} extends Job
{
    public int \$tries = 3;
    public int \$timeout = 60;

    public function __construct(
        // Inject dependencies via constructor
    ) {}

    public function handle(): void
    {
        // Your job logic here
    }
}
EOT;

        $this->createFile($filePath, $template, $jobName, 'Job');
    }

    /**
     * Generates a new Seeder class.
     *
     * When called without a name argument, an interactive prompt is shown.
     *
     * @return void
     */
    public function makeSeeder(): void
    {
        $name = $this->resolveArgument(
            index: 2,
            question: 'What should the seeder be named?',
            placeholder: 'E.g. UserSeeder',
            example: 'php celestial make:seeder UserSeeder'
        );

        $seederName = ucfirst($name);

        if (!str_ends_with($seederName, 'Seeder') && $seederName !== 'DatabaseSeeder') {
            $seederName .= 'Seeder';
        }

        $seedsPath = dirname(__DIR__, 3) . '/database/seeds';
        $filePath = $seedsPath . '/' . $seederName . '.php';

        if (!is_dir($seedsPath) && !mkdir($seedsPath, 0755, true)) {
            self::error("Could not create directory: {$seedsPath}");
            exit(1);
        }

        $this->ensureFileDoesNotExist($filePath, $seederName, 'Seeder');

        $template = $this->seederStub($seederName);

        $this->createFile($filePath, $template, $seederName, 'Seeder');
    }

    /**
     * Generates a new Factory class.
     *
     * When called without a name argument, an interactive prompt is shown.
     *
     * @return void
     */
    public function makeFactory(): void
    {
        $name = $this->resolveArgument(
            index: 2,
            question: 'What should the factory be named?',
            placeholder: 'E.g. UserFactory',
            example: 'php celestial make:factory UserFactory'
        );

        $factoryName = ucfirst($name);
        $factoriesPath = dirname(__DIR__, 3) . '/database/factories';

        if (!str_ends_with($factoryName, 'Factory')) {
            $factoryName .= 'Factory';
        }

        $filePath = $factoriesPath . '/' . $factoryName . '.php';

        if (!is_dir($factoriesPath) && !mkdir($factoriesPath, 0755, true)) {
            self::error("Could not create directory: {$factoriesPath}");
            exit(1);
        }

        $this->ensureFileDoesNotExist($filePath, $factoryName, 'Factory');

        $model = str_replace('Factory', '', $factoryName);
        $template = $this->factoryStub($factoryName, $model);

        $this->createFile($filePath, $template, $factoryName, 'Factory');
    }

    /**
     * Generates a migration file.
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
        $name = $this->resolveArgument(
            index: 2,
            question: 'What should the migration be named?',
            placeholder: 'E.g. create_users_table',
            example: 'php celestial make:migration create_users_table'
        );

        // Delegate back to MigrateCommand's makeMigration with the resolved name
        $args = $this->args;
        if (count($args) < 3) {
            $args[] = $name;
        } else {
            $args[2] = $name;
        }

        $cmd = new MigrateCommand($args);
        $cmd->makeMigration();
    }

    /**
     * Generates the fully-configured ThrottleMiddleware class.
     *
     * @return void
     */
    public function makeThrottle(): void
    {
        $filePath = APP_PATH . '/Middlewares/ThrottleMiddleware.php';

        if (file_exists($filePath)) {
            echo PHP_EOL;
            self::error("ThrottleMiddleware already exists at {$filePath}.");
            echo PHP_EOL;
            exit(1);
        }

        $dir = dirname($filePath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            echo PHP_EOL;
            self::error("Could not create directory {$dir}.");
            echo PHP_EOL;
            exit(1);
        }

        // The full ThrottleMiddleware stub (unchanged from original)
        $template = $this->throttleStub();

        $this->createFile($filePath, $template, 'ThrottleMiddleware', 'Middleware');
        self::info("Register it in your routes with: ->middleware('throttle:60,1')");
    }

    /**
     * Returns the controller stub based on the selected type.
     *
     * @param string $name Controller class name.
     * @param string $type One of Empty | Resource | API | Invokable.
     *
     * @return string PHP source code.
     */
    private function controllerStub(string $name, string $type): string
    {
        return match ($type) {

            'Resource' => <<<EOT
<?php

declare(strict_types=1);

namespace App\Controllers;

use Slenix\Http\Request;
use Slenix\Http\Response;

class {$name}
{
    public function index(Request \$req, Response \$res): mixed
    {
        // List all resources
    }

    public function show(Request \$req, Response \$res): mixed
    {
        // Show a single resource
    }

    public function store(Request \$req, Response \$res): mixed
    {
        // Create a new resource
    }

    public function update(Request \$req, Response \$res): mixed
    {
        // Update an existing resource
    }

    public function destroy(Request \$req, Response \$res): mixed
    {
        // Delete a resource
    }
}
EOT,

            'API' => <<<EOT
<?php

declare(strict_types=1);

namespace App\Controllers;

use Slenix\Http\Request;
use Slenix\Http\Response;

class {$name}
{
    public function index(Request \$req, Response \$res): mixed
    {
        return \$res->json(['data' => []]);
    }

    public function show(Request \$req, Response \$res): mixed
    {
        return \$res->json(['data' => null]);
    }

    public function store(Request \$req, Response \$res): mixed
    {
        return \$res->status(201)->json(['data' => null]);
    }

    public function update(Request \$req, Response \$res): mixed
    {
        return \$res->json(['data' => null]);
    }

    public function destroy(Request \$req, Response \$res): mixed
    {
        return \$res->status(204)->json([]);
    }
}
EOT,

            'Invokable' => <<<EOT
<?php

declare(strict_types=1);

namespace App\Controllers;

use Slenix\Http\Request;
use Slenix\Http\Response;

class {$name}
{
    public function __invoke(Request \$req, Response \$res): mixed
    {
        // Single-action controller logic here
    }
}
EOT,

            // Empty (default)
            default => <<<EOT
<?php

declare(strict_types=1);

namespace App\Controllers;

use Slenix\Http\Request;
use Slenix\Http\Response;

class {$name}
{
    public function index(Request \$req, Response \$res): mixed
    {
        // Your application logic here
    }
}
EOT,
        };
    }

    /**
     * Returns the seeder boilerplate.
     *
     * @param string $name Class name.
     *
     * @return string
     */
    private function seederStub(string $name): string
    {
        $isDatabase = $name === 'DatabaseSeeder';
        $comment = $isDatabase
            ? 'Main entry point. Call other seeders here.'
            : 'Inserts data into the corresponding table.';

        $example = $isDatabase
            ? <<<'BODY'
        // $this->call([
        //     UserSeeder::class,
        //     CategorySeeder::class,
        // ]);
BODY
            : <<<'BODY'
        // $this->insertBatch('table_name', [
        //     ['name' => Fake::name(), 'email' => Fake::email()],
        // ]);
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

    /**
     * Returns the factory boilerplate.
     *
     * @param string $name  Class name.
     * @param string $model Model name.
     *
     * @return string
     */
    private function factoryStub(string $name, string $model): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

use Slenix\Database\Seeds\Factory;
use Slenix\Database\Seeds\Fake;
use App\Models\\{$model};

class {$name} extends Factory
{
    protected string \$model = {$model}::class;

    public function definition(): array
    {
        return [
            'name'       => Fake::name(),
            'email'      => Fake::email(),
            'password'   => Fake::password(),
            'created_at' => Fake::dateTime(),
            'updated_at' => Fake::dateTime(),
        ];
    }
}
PHP;
    }

    /**
     * Returns the full ThrottleMiddleware source (unchanged from original).
     *
     * @return string
     */
    private function throttleStub(): string
    {
        // Identical to the original make:throttle template — truncated here
        // for brevity; paste the full template from the original MakeCommand.
        return <<<'EOT'
<?php

declare(strict_types=1);

namespace App\Middlewares;

use Slenix\Http\Request;
use Slenix\Http\Response;
use Slenix\Http\Middlewares\Middleware;
use Slenix\Supports\Security\RateLimit;
use Slenix\Supports\Security\Jwt;

class ThrottleMiddleware implements Middleware
{
    private const DEFAULT_MAX           = 60;
    private const DEFAULT_DECAY_MINUTES = 1;

    public function handle(Request $request, Response $response, callable $next): mixed
    {
        [$maxAttempts, $decaySeconds] = $this->parseParams();
        $key    = $this->resolveKey($request);
        $result = RateLimit::attempt($key, $maxAttempts, $decaySeconds);
        $this->emitHeaders($result);

        if (!$result['allowed']) {
            return $this->respondTooManyRequests($request, $response, $result);
        }

        return $next($request, $response);
    }

    private function resolveKey(Request $request): string
    {
        return RateLimit::buildKey(
            route:      $this->normaliseRoute($request),
            ip:         $request->ip(),
            jwtUserId:  $this->extractJwtUserId($request),
            sessionKey: 'user_id'
        );
    }

    private function extractJwtUserId(Request $request): ?string
    {
        $authHeader = $request->getHeader('Authorization', '');
        if (!str_starts_with((string) $authHeader, 'Bearer ')) return null;
        $payload = (new Jwt())->validate(substr((string) $authHeader, 7));
        return ($payload && isset($payload['user_id'])) ? (string) $payload['user_id'] : null;
    }

    private function normaliseRoute(Request $request): string
    {
        $uri        = parse_url($request->uri(), PHP_URL_PATH) ?? '/';
        $normalised = preg_replace('/\/\d+/', '/{id}', $uri) ?? $uri;
        return 'throttle:' . trim($normalised, '/');
    }

    private function emitHeaders(array $result): void
    {
        if (headers_sent()) return;
        header('X-RateLimit-Limit: '     . $result['max_attempts']);
        header('X-RateLimit-Remaining: ' . $result['remaining']);
        header('X-RateLimit-Reset: '     . $result['reset_at']);
    }

    private function respondTooManyRequests(Request $request, Response $response, array $result): null
    {
        if (!headers_sent()) header('Retry-After: ' . $result['retry_after']);

        if ($request->expectsJson()) {
            $response->status(429)->json([
                'success'     => false,
                'message'     => 'Too many requests. Please slow down.',
                'retry_after' => $result['retry_after'],
                'reset_at'    => $result['reset_at'],
            ]);
        } else {
            http_response_code(429);
            echo '<h1>429 — Too Many Requests</h1>';
        }

        exit;
    }

    private function parseParams(): array
    {
        $raw = $_SERVER['HTTP_X_THROTTLE_PARAMS'] ?? '';

        if ($raw !== '' && str_starts_with($raw, 'throttle:')) {
            $parts = explode(',', substr($raw, strlen('throttle:')));
            $max   = (isset($parts[0]) && is_numeric($parts[0]) && (int)$parts[0] > 0) ? (int)$parts[0] : self::DEFAULT_MAX;
            $decay = (isset($parts[1]) && is_numeric($parts[1]) && (int)$parts[1] > 0) ? (int)$parts[1] : self::DEFAULT_DECAY_MINUTES;
            return [$max, $decay * 60];
        }

        return [self::DEFAULT_MAX, self::DEFAULT_DECAY_MINUTES * 60];
    }
}
EOT;
    }

    /**
     * Generates a new FormRequest class.
     *
     * @return void
     */
    public function makeRequest(): void
    {
        $name = $this->resolveArgument(
            index: 2,
            question: 'What should the request be named?',
            placeholder: 'E.g. LoginRequest',
            example: 'php celestial make:request LoginRequest'
        );

        $requestName = ucfirst($name);

        if (!str_ends_with($requestName, 'Request')) {
            $requestName .= 'Request';
        }

        $dir = APP_PATH . '/Http/Requests';
        $filePath = $dir . '/' . $requestName . '.php';

        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            self::error("Could not create directory {$dir}.");
            exit(1);
        }

        $this->ensureFileDoesNotExist($filePath, $requestName, 'Request');

        $template = $this->requestStub($requestName);

        $this->createFile($filePath, $template, $requestName, 'Request');
    }

    /**
     * Returns the FormRequest boilerplate.
     *
     * @param string $name Class name.
     * @return string
     */
    private function requestStub(string $name): string
    {
        return <<<EOT
        <?php

        declare(strict_types=1);

        namespace App\Http\Requests;

        use Slenix\Http\FormRequest;

        class {$name} extends FormRequest
        {
            /**
             * Determine if the user is authorized to make this request.
             */
            public function authorize(): bool
            {
                return true;
            }

            /**
             * Validation rules for this request.
             *
             * @return array<string, string|string[]>
             */
            public function rules(): array
            {
                return [
                    // 'field' => 'required|string|max:255',
                ];
            }

            /**
             * Custom error messages (optional).
             *
             * @return array<string, string>
             */
            public function messages(): array
            {
                return [];
            }
        }
        EOT;
    }

    /**
     * Returns the CLI argument at the given index, or prompts the user when it
     * is absent.
     *
     * @param int    $index       Index in $this->args.
     * @param string $question    Prompt question text.
     * @param string $placeholder Hint/placeholder inside the box.
     * @param string $example     Example shown if the user submits an empty value.
     *
     * @return string The resolved name (never empty).
     */
    private function resolveArgument(
        int $index,
        string $question,
        string $placeholder = '',
        string $example = ''
    ): string {
        if (isset($this->args[$index]) && trim($this->args[$index]) !== '') {
            return $this->args[$index];
        }

        $prompt = new Prompt();
        $value = $prompt->text($question, $placeholder);

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

    /**
     * Resolves the controller / component name, skipping any --flag arguments.
     *
     * @param string $question    Prompt question text.
     * @param string $placeholder Hint text.
     * @param string $example     Example command.
     *
     * @return string
     */
    private function resolveArgumentSkipFlags(
        string $question,
        string $placeholder = '',
        string $example = ''
    ): string {
        for ($i = 2; $i < count($this->args); $i++) {
            if (!str_starts_with($this->args[$i], '--')) {
                return $this->args[$i];
            }
        }

        // No positional argument found — prompt interactively
        $prompt = new Prompt();
        $value = $prompt->text($question, $placeholder);

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

    /**
     * Extracts the value of a --flag=value argument.
     *
     * @param string $prefix Flag prefix, e.g. '--type='.
     *
     * @return string|null The value, or null when the flag is absent.
     */
    private function findFlag(string $prefix): ?string
    {
        foreach ($this->args as $arg) {
            if (str_starts_with($arg, $prefix)) {
                return substr($arg, strlen($prefix));
            }
        }

        return null;
    }

    /**
     * Verifies that the target file does not already exist, and creates any
     * missing parent directories before the file is written.
     *
     * @param string $path Target file path.
     * @param string $name Component name.
     * @param string $type Component type label.
     *
     * @return void
     */
    private function ensureFileDoesNotExist(string $path, string $name, string $type): void
    {
        if (file_exists($path)) {
            echo PHP_EOL;
            self::error("{$type} '{$name}' already exists.");
            echo '  ' . self::console()->muted($path) . PHP_EOL;
            echo PHP_EOL;
            exit(1);
        }

        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            echo PHP_EOL;
            self::error("Could not create directory {$dir}.");
            echo PHP_EOL;
            exit(1);
        }
    }

    /**
     * Writes the generated file to disk and prints a success message.
     *
     * @param string $path    Absolute destination path.
     * @param string $content PHP source to write.
     * @param string $name    Component name.
     * @param string $type    Component type label.
     *
     * @return void
     */
    private function createFile(string $path, string $content, string $name, string $type): void
    {
        if (file_put_contents($path, $content) === false) {
            echo PHP_EOL;
            self::error("Failed to create {$type} '{$name}' at {$path}.");
            echo PHP_EOL;
            exit(1);
        }

        echo PHP_EOL;
        self::success("{$type} '{$name}' created successfully.");
        echo PHP_EOL;
        echo '  ' . self::console()->muted($path) . PHP_EOL;
        echo PHP_EOL;
    }

    /**
     * Resolves an absolute path relative to the project root.
     *
     * @param string $relative Relative path from the project root.
     *
     * @return string
     */
    private static function basePath(string $relative): string
    {
        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . ltrim($relative, '/\\');
    }
}