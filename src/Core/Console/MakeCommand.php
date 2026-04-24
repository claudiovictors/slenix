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

    // =========================================================================
    // Application
    // =========================================================================

    /**
     * Generates a cryptographically secure APP_KEY and persists it to .env.
     *
     * Uses random_bytes(32) encoded as base64 with the "base64:" prefix,
     * which is the secure standard used by the framework's encrypt() helper.
     *
     * If the .env file does not exist, the method attempts to clone it from
     * .env.example before writing the key.
     *
     * @return void
     */
    public static function generateKey(): void
    {
        $envPath     = self::basePath('.env');
        $examplePath = self::basePath('.env.example');

        if (!file_exists($envPath)) {
            if (file_exists($examplePath)) {
                if (!copy($examplePath, $envPath)) {
                    self::error("Could not create .env from .env.example.");
                    return;
                }
                self::info(".env created from .env.example.");
            } else {
                self::error(".env file not found. Please create it first.");
                return;
            }
        }

        $key     = 'base64:' . base64_encode(random_bytes(32));
        $content = file_get_contents($envPath);

        if ($content === false) {
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
            self::error("Could not update APP_KEY in .env.");
            return;
        }

        if (file_put_contents($envPath, $updated) === false) {
            self::error("Could not write to .env.");
            return;
        }

        self::success("APP_KEY generated and saved to .env.");
        self::info("Key: {$key}");
    }

    // =========================================================================
    // Generators
    // =========================================================================

    /**
     * Generates a new Eloquent-style Model class.
     *
     * The table name is automatically derived from the model name by converting
     * it to snake_case and appending a plural suffix (e.g. User → users).
     *
     * Example:
     *   php celestial make:model Product
     *   → app/Models/Product.php
     *
     * @return void
     */
    public function makeModel(): void
    {
        if (count($this->args) < 3) {
            self::error('Model name is required.');
            self::info('Example: php celestial make:model User');
            exit(1);
        }

        $modelName = ucfirst($this->args[2]);
        $tableName = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $modelName)) . 's';
        $filePath  = APP_PATH . '/Models/' . $modelName . '.php';

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
     * The controller name is extracted from the CLI arguments, ignoring any
     * flags (arguments prefixed with --).
     *
     * Example:
     *   php celestial make:controller ProductController
     *   → app/Controllers/ProductController.php
     *
     * @return void
     */
    public function makeController(): void
    {
        if (count($this->args) < 3) {
            self::error('Controller name is required.');
            self::info('Example: php celestial make:controller Home');
            exit(1);
        }

        $controllerName = ucfirst($this->getControllerName());
        $filePath       = APP_PATH . '/Controllers/' . $controllerName . '.php';

        $this->ensureFileDoesNotExist($filePath, $controllerName, 'Controller');

        $template = <<<EOT
<?php

declare(strict_types=1);

namespace App\Controllers;

use Slenix\Http\Request;
use Slenix\Http\Response;

class {$controllerName}
{
    public function index(Request \$request, Response \$response)
    {
        // Your application logic here
    }
}
EOT;

        $this->createFile($filePath, $template, $controllerName, 'Controller');
    }

    /**
     * Generates a blank Middleware class implementing the Slenix Middleware interface.
     *
     * The "Middleware" suffix is appended automatically if not already present
     * in the provided name (e.g. Auth → AuthMiddleware).
     *
     * Example:
     *   php celestial make:middleware Auth
     *   → app/Middlewares/AuthMiddleware.php
     *
     * @return void
     */
    public function makeMiddleware(): void
    {
        if (count($this->args) < 3) {
            self::error('Middleware name is required.');
            self::info('Example: php celestial make:middleware Auth');
            exit(1);
        }

        $middlewareName = ucfirst($this->args[2]);
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

        // Example: check some condition
        // if (!\$someCondition) {
        //     \$response->status(403)->json(['error' => 'Forbidden']);
        //     return false;
        // }

        return \$next(\$request, \$response);
    }
}
EOT;

        $this->createFile($filePath, $template, $middlewareName, 'Middleware');
    }

    /**
     * Generates a new Job class for the background queue system.
     *
     * The "Job" suffix is appended automatically if not already present
     * (e.g. SendWelcomeEmail → SendWelcomeEmailJob).
     *
     * Example:
     *   php celestial make:job SendWelcomeEmail
     *   → app/Jobs/SendWelcomeEmailJob.php
     *
     * @return void
     */
    public function makeJob(): void
    {
        if (count($this->args) < 3) {
            self::error('Job name is required.');
            self::info('Example: php celestial make:job SendWelcomeEmail');
            exit(1);
        }

        $jobName = ucfirst($this->args[2]);
        if (!str_ends_with($jobName, 'Job')) {
            $jobName .= 'Job';
        }

        $dir      = APP_PATH . '/Jobs';
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
    /**
     * Number of times the job may be attempted before being marked as failed.
     */
    public int \$tries = 3;

    /**
     * Number of seconds before the job is considered timed out.
     */
    public int \$timeout = 60;

    public function __construct(
        // Inject dependencies via constructor
    ) {}

    /**
     * Execute the job logic.
     */
    public function handle(): void
    {
        // Your job logic here
    }
}
EOT;

        $this->createFile($filePath, $template, $jobName, 'Job');
    }

    /**
     * Generates the fully-configured ThrottleMiddleware class.
     *
     * Unlike make:middleware (which produces a blank stub), this command
     * generates the complete, production-ready rate-limiting middleware
     * pre-wired to the RateLimit class and the JWT + Session + IP identity
     * resolution chain.
     *
     * The generated file is placed directly in app/Middlewares/ and is
     * immediately usable via the 'throttle' alias in route definitions:
     *
     *   Router::post('/auth/login', [AuthController::class, 'login'])
     *       ->middleware('throttle:5,10');
     *
     * Example:
     *   php celestial make:throttle
     *   → app/Middlewares/ThrottleMiddleware.php
     *
     * @return void
     */
    public function makeThrottle(): void
    {
        $filePath = APP_PATH . '/Middlewares/ThrottleMiddleware.php';

        if (file_exists($filePath)) {
            self::error("ThrottleMiddleware already exists at {$filePath}.");
            exit(1);
        }

        $dir = dirname($filePath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            self::error("Could not create directory {$dir}.");
            exit(1);
        }

        $template = <<<'EOT'
<?php

/*
|--------------------------------------------------------------------------
| ThrottleMiddleware Class
|--------------------------------------------------------------------------
|
| Rate-limiting middleware for Slenix routes.
|
| Reads its configuration from the parameterised 'throttle:max,decay' alias
| supported by the Router. Parameters are injected via the
| $_SERVER['HTTP_X_THROTTLE_PARAMS'] variable by the Router just before this
| middleware is instantiated, so no constructor arguments are required.
|
| Usage in routes/web.php:
|
|   // 60 requests per minute — default
|   Router::get('/api/products', [ProductController::class, 'index'])
|       ->middleware('throttle');
|
|   // 100 requests per minute
|   Router::get('/api/products', [ProductController::class, 'index'])
|       ->middleware('throttle:100,1');
|
|   // 5 attempts per 10 minutes — login brute-force protection
|   Router::post('/auth/login', [AuthController::class, 'login'])
|       ->middleware('throttle:5,10');
|
|   // Applied to a route group
|   Router::group(['prefix' => 'api/v1', 'middleware' => ['jwt', 'throttle:120,1']], function () {
|       Router::get('/users',  [UserController::class, 'index']);
|       Router::post('/orders', [OrderController::class, 'store']);
|   });
|
| Response headers emitted on every request:
|
|   X-RateLimit-Limit:     60
|   X-RateLimit-Remaining: 45
|   X-RateLimit-Reset:     1700000060
|
| Additional header emitted only when the request is blocked (HTTP 429):
|
|   Retry-After: 47
|
| Identity resolution priority:
|   1. JWT user_id   — stateless API clients authenticated via Bearer token.
|   2. Session user_id — authenticated web users with an active PHP session.
|   3. IP address    — universal fallback for anonymous callers.
|
*/

declare(strict_types=1);

namespace App\Middlewares;

use Slenix\Http\Request;
use Slenix\Http\Response;
use Slenix\Http\Middlewares\Middleware;
use Slenix\Supports\Security\RateLimit;
use Slenix\Supports\Security\Jwt;

class ThrottleMiddleware implements Middleware
{
    /**
     * Default maximum number of requests allowed per window.
     *
     * @var int
     */
    private const DEFAULT_MAX = 60;

    /**
     * Default window duration in minutes.
     *
     * @var int
     */
    private const DEFAULT_DECAY_MINUTES = 1;

    /**
     * Handles an incoming HTTP request and enforces rate limiting.
     *
     * Execution flow:
     *   1. Parse throttle parameters from the Router-injected server variable.
     *   2. Resolve the best rate-limit key for the current caller (JWT → Session → IP).
     *   3. Attempt the rate-limited action via RateLimit::attempt().
     *   4. Emit X-RateLimit-* response headers regardless of the outcome.
     *   5. If the limit is exceeded return a 429 response and halt the pipeline.
     *   6. Otherwise pass the request to the next handler in the pipeline.
     *
     * @param Request  $request  The incoming HTTP request.
     * @param Response $response The outgoing HTTP response.
     * @param callable $next     The next middleware or route handler.
     *
     * @return mixed
     */
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

    /**
     * Resolves the most appropriate rate-limit key for the current request.
     *
     * Identity is resolved in the following priority order:
     *   1. JWT user_id   — extracted from the Bearer token in the Authorization header.
     *   2. Session user_id — read from the active PHP session (key: 'user_id').
     *   3. IP address    — resolved automatically by RateLimit::buildKey().
     *
     * @param Request $request The incoming HTTP request.
     *
     * @return string The resolved rate-limit bucket key.
     */
    private function resolveKey(Request $request): string
    {
        $route     = $this->normaliseRoute($request);
        $jwtUserId = $this->extractJwtUserId($request);

        return RateLimit::buildKey(
            route:      $route,
            ip:         $request->ip(),
            jwtUserId:  $jwtUserId,
            sessionKey: 'user_id'
        );
    }

    /**
     * Attempts to extract the user_id claim from a Bearer JWT token.
     *
     * Returns null if no Authorization header is present, the header does not
     * use the Bearer scheme, or the token fails JWT validation.
     *
     * @param Request $request The incoming HTTP request.
     *
     * @return string|null The user_id from the JWT payload, or null.
     */
    private function extractJwtUserId(Request $request): ?string
    {
        $authHeader = $request->getHeader('Authorization', '');

        if (!str_starts_with((string) $authHeader, 'Bearer ')) {
            return null;
        }

        $payload = (new Jwt())->validate(substr((string) $authHeader, 7));

        if ($payload === null || !isset($payload['user_id'])) {
            return null;
        }

        return (string) $payload['user_id'];
    }

    /**
     * Produces a short, normalised route string from the request URI.
     *
     * Digit-only dynamic segments are replaced with {id} so that
     * /users/42/orders and /users/99/orders share the same rate-limit bucket.
     *
     * @param Request $request The incoming HTTP request.
     *
     * @return string Normalised route prefix, e.g. 'throttle:users/{id}/orders'.
     */
    private function normaliseRoute(Request $request): string
    {
        $uri        = parse_url($request->uri(), PHP_URL_PATH) ?? '/';
        $normalised = preg_replace('/\/\d+/', '/{id}', $uri) ?? $uri;

        return 'throttle:' . trim($normalised, '/');
    }

    /**
     * Emits standard X-RateLimit-* HTTP headers on every request.
     *
     * Headers emitted:
     *   - X-RateLimit-Limit     : Maximum allowed requests in the window.
     *   - X-RateLimit-Remaining : Requests still available in the current window.
     *   - X-RateLimit-Reset     : Unix timestamp when the window resets.
     *
     * @param array $result The result returned by RateLimit::attempt().
     *
     * @return void
     */
    private function emitHeaders(array $result): void
    {
        if (headers_sent()) {
            return;
        }

        header('X-RateLimit-Limit: '     . $result['max_attempts']);
        header('X-RateLimit-Remaining: ' . $result['remaining']);
        header('X-RateLimit-Reset: '     . $result['reset_at']);
    }

    /**
     * Sends a 429 Too Many Requests response and terminates the pipeline.
     *
     * Automatically detects the expected response format:
     *   - JSON : when the client sends Accept: application/json or X-Requested-With: XMLHttpRequest.
     *   - HTML : for all other browser-originated requests.
     *
     * Also emits the Retry-After header so compliant clients know how long to wait.
     *
     * @param Request  $request  The incoming HTTP request.
     * @param Response $response The outgoing HTTP response.
     * @param array    $result   The result returned by RateLimit::attempt().
     *
     * @return null Always returns null after halting execution via exit.
     */
    private function respondTooManyRequests(Request $request, Response $response, array $result): null
    {
        if (!headers_sent()) {
            header('Retry-After: ' . $result['retry_after']);
        }

        if ($request->expectsJson()) {
            $response->status(429)->json([
                'success'     => false,
                'message'     => 'Too many requests. Please slow down.',
                'retry_after' => $result['retry_after'],
                'reset_at'    => $result['reset_at'],
            ]);
        } else {
            http_response_code(429);
            echo '<!DOCTYPE html>'
                . '<html lang="en"><head><meta charset="UTF-8">'
                . '<title>429 — Too Many Requests</title></head><body>'
                . '<h1>429 — Too Many Requests</h1>'
                . '<p>You have sent too many requests. '
                . 'Please wait <strong>' . $result['retry_after'] . '</strong> '
                . 'second(s) before trying again.</p>'
                . '</body></html>';
        }

        exit;
    }

    /**
     * Parses the throttle parameters injected by the Router.
     *
     * The Router writes 'throttle:{max},{decay}' into
     * $_SERVER['HTTP_X_THROTTLE_PARAMS'] before instantiating this middleware.
     *
     * Format  : 'throttle:{maxAttempts},{decayMinutes}'
     * Examples:
     *   'throttle:60,1'  → [60,  60]   (60 req / 1 min)
     *   'throttle:5,10'  → [5,  600]   (5  req / 10 min)
     *   'throttle'       → [60,  60]   (defaults)
     *
     * @return array{0: int, 1: int} [maxAttempts, decaySeconds]
     */
    private function parseParams(): array
    {
        $raw = $_SERVER['HTTP_X_THROTTLE_PARAMS'] ?? '';

        if ($raw !== '' && str_starts_with($raw, 'throttle:')) {
            $parts = explode(',', substr($raw, strlen('throttle:')));
            $max   = isset($parts[0]) && is_numeric($parts[0]) && (int) $parts[0] > 0
                ? (int) $parts[0]
                : self::DEFAULT_MAX;
            $decay = isset($parts[1]) && is_numeric($parts[1]) && (int) $parts[1] > 0
                ? (int) $parts[1]
                : self::DEFAULT_DECAY_MINUTES;

            return [$max, $decay * 60];
        }

        return [self::DEFAULT_MAX, self::DEFAULT_DECAY_MINUTES * 60];
    }
}
EOT;

        $this->createFile($filePath, $template, 'ThrottleMiddleware', 'Middleware');

        self::info("Register it in your routes with: ->middleware('throttle:60,1')");
    }

    // =========================================================================
    // Internal helpers
    // =========================================================================

    /**
     * Extracts the controller name from the CLI arguments, skipping any flags
     * (arguments that begin with --).
     *
     * @return string The extracted controller name.
     */
    private function getControllerName(): string
    {
        for ($i = 2; $i < count($this->args); $i++) {
            if (!str_starts_with($this->args[$i], '--')) {
                return $this->args[$i];
            }
        }

        self::error('Controller name is required.');
        self::info('Example: php celestial make:controller Home');
        exit(1);
    }

    /**
     * Verifies that the target file does not already exist, and creates any
     * missing parent directories before the file is written.
     *
     * Exits the CLI process with code 1 if the file already exists or if the
     * directory cannot be created.
     *
     * @param string $path Target file path.
     * @param string $name Component name (used in error messages).
     * @param string $type Component type label, e.g. 'Model', 'Controller'.
     *
     * @return void
     */
    private function ensureFileDoesNotExist(string $path, string $name, string $type): void
    {
        if (file_exists($path)) {
            self::error("{$type} '{$name}' already exists at {$path}.");
            exit(1);
        }

        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            self::error("Could not create directory {$dir}.");
            exit(1);
        }
    }

    /**
     * Writes the generated file content to disk and prints a success message.
     *
     * Exits the CLI process with code 1 if the file cannot be written.
     *
     * @param string $path    Absolute path where the file will be written.
     * @param string $content Full PHP source content to write.
     * @param string $name    Component name (used in success / error messages).
     * @param string $type    Component type label, e.g. 'Model', 'Job'.
     *
     * @return void
     */
    private function createFile(string $path, string $content, string $name, string $type): void
    {
        if (file_put_contents($path, $content) === false) {
            self::error("Failed to create {$type} '{$name}' at {$path}.");
            exit(1);
        }

        self::success("{$type} '{$name}' created successfully at:");
        echo "  {$path}" . PHP_EOL;
    }

    /**
     * Resolves an absolute filesystem path relative to the project root.
     *
     * @param string $relative Relative path from the project root (e.g. '.env').
     *
     * @return string Absolute path.
     */
    private static function basePath(string $relative): string
    {
        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . ltrim($relative, '/\\');
    }
}