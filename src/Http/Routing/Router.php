<?php

/*
|--------------------------------------------------------------------------
| Router Class — Slenix Framework
|--------------------------------------------------------------------------
|
| This class manages application routes, mapping URIs to handlers
| (closures or controller methods) and applying middlewares. It supports
| all standard HTTP verbs, route grouping with prefixes and middlewares,
| route naming, WebSocket routes, and Laravel-style response rendering
| (returning strings, arrays, or view output directly from handlers).
|
*/

declare(strict_types=1);

namespace Slenix\Http\Routing;

use Slenix\Http\Request;
use Slenix\Http\Response;
use Slenix\Supports\Security\CSRF;
use Slenix\Http\Middlewares\Middleware;

/**
 * Class Router
 *
 * Central routing engine for the Slenix Framework.
 *
 * Provides static methods to register routes for all HTTP verbs, group
 * routes under shared prefixes and middlewares, generate named route URLs,
 * and dispatch incoming requests through the middleware pipeline to the
 * appropriate handler.
 *
 * @package Slenix\Http\Routing
 */
class Router
{
    // -----------------------------------------------------------------------
    // Internal State
    // -----------------------------------------------------------------------

    /**
     * All registered routes.
     *
     * Each entry is an associative array with keys:
     *   - `method`     (string)       HTTP verb in uppercase.
     *   - `pathUri`    (string)       The URI pattern, e.g. `/users/{id}`.
     *   - `handler`    (callable|array) Closure or [ControllerClass, method].
     *   - `middleware` (array)        Ordered list of middleware class names/aliases.
     *   - `name`       (string|null)  Optional route name.
     *
     * @var array<int, array{method: string, pathUri: string, handler: callable|array, middleware: array, name: string|null}>
     */
    private static array $routes = [];

    /**
     * Stack of active group URI prefixes.
     *
     * Each `group()` call pushes its prefix onto this stack and pops it
     * after the group callback returns, allowing unlimited nesting.
     *
     * @var string[]
     */
    private static array $prefix = [];

    /**
     * Middlewares accumulated from the current group nesting.
     *
     * Merged with route-level middlewares when a route is registered.
     *
     * @var string[]
     */
    private static array $groupMiddlewares = [];

    /**
     * Middlewares applied to every route regardless of grouping.
     *
     * @var string[]
     */
    private static array $globalMiddlewares = [];

    /**
     * Registered WebSocket routes.
     *
     * Maps URI paths to fully-qualified handler class names that extend
     * `WebSocketHandler`.
     *
     * @var array<string, string>
     */
    private static array $webSocketRoutes = [];

    // -----------------------------------------------------------------------
    // Route Registration — HTTP Verbs
    // -----------------------------------------------------------------------

    /**
     * Registers a GET route.
     *
     * GET routes are also used to serve views, redirects, and any
     * read-only resource.
     *
     * @param  string         $path_uri   URI pattern, e.g. `/users/{id}`.
     * @param  callable|array $handle     Closure or `[Controller::class, 'method']`.
     * @param  string[]       $middleware Middleware class names or aliases.
     * @return Route                      Fluent route object for chaining (e.g. `->name()`).
     */
    public static function get(string $path_uri, callable|array $handle, array $middleware = []): Route
    {
        return self::add('GET', $path_uri, $handle, $middleware);
    }

    /**
     * Registers a POST route.
     *
     * Typically used for form submissions and resource creation.
     * CSRF validation is applied automatically when appropriate.
     *
     * @param  string         $path_uri   URI pattern.
     * @param  callable|array $handle     Handler closure or controller tuple.
     * @param  string[]       $middleware Middleware aliases or class names.
     * @return Route
     */
    public static function post(string $path_uri, callable|array $handle, array $middleware = []): Route
    {
        return self::add('POST', $path_uri, $handle, $middleware);
    }

    /**
     * Registers a PUT route.
     *
     * Used for full resource replacement (idempotent).
     *
     * @param  string         $path_uri   URI pattern.
     * @param  callable|array $handle     Handler closure or controller tuple.
     * @param  string[]       $middleware Middleware aliases or class names.
     * @return Route
     */
    public static function put(string $path_uri, callable|array $handle, array $middleware = []): Route
    {
        return self::add('PUT', $path_uri, $handle, $middleware);
    }

    /**
     * Registers a PATCH route.
     *
     * Used for partial resource updates.
     *
     * @param  string         $path_uri   URI pattern.
     * @param  callable|array $handle     Handler closure or controller tuple.
     * @param  string[]       $middleware Middleware aliases or class names.
     * @return Route
     */
    public static function patch(string $path_uri, callable|array $handle, array $middleware = []): Route
    {
        return self::add('PATCH', $path_uri, $handle, $middleware);
    }

    /**
     * Registers a DELETE route.
     *
     * Used for resource deletion. CSRF validation is applied automatically.
     *
     * @param  string         $path_uri   URI pattern.
     * @param  callable|array $handle     Handler closure or controller tuple.
     * @param  string[]       $middleware Middleware aliases or class names.
     * @return Route
     */
    public static function delete(string $path_uri, callable|array $handle, array $middleware = []): Route
    {
        return self::add('DELETE', $path_uri, $handle, $middleware);
    }

    /**
     * Registers an OPTIONS route.
     *
     * Primarily used for CORS pre-flight responses.
     *
     * @param  string         $path_uri   URI pattern.
     * @param  callable|array $handle     Handler closure or controller tuple.
     * @param  string[]       $middleware Middleware aliases or class names.
     * @return Route
     */
    public static function options(string $path_uri, callable|array $handle, array $middleware = []): Route
    {
        return self::add('OPTIONS', $path_uri, $handle, $middleware);
    }

    /**
     * Registers a route that matches the current request's HTTP method.
     *
     * Useful for dynamic or catch-all routes where the verb is not known
     * at definition time.
     *
     * @param  string         $path_uri   URI pattern.
     * @param  callable|array $handle     Handler closure or controller tuple.
     * @param  string[]       $middleware Middleware aliases or class names.
     * @return Route
     */
    public static function any(string $path_uri, callable|array $handle, array $middleware = []): Route
    {
        return self::add($_SERVER['REQUEST_METHOD'] ?? 'GET', $path_uri, $handle, $middleware);
    }

    /**
     * Registers a route that responds to multiple HTTP methods.
     *
     * Example:
     * ```php
     * Router::match(['GET', 'POST'], '/contact', [ContactController::class, 'handle']);
     * ```
     *
     * @param  string[]       $methods    HTTP verbs, e.g. `['GET', 'POST']`.
     * @param  string         $path_uri   URI pattern.
     * @param  callable|array $handle     Handler closure or controller tuple.
     * @param  string[]       $middleware Middleware aliases or class names.
     * @return Route                      Returns the Route object for the last method registered.
     */
    public static function match(array $methods, string $path_uri, callable|array $handle, array $middleware = []): Route
    {
        $lastRoute = null;
        foreach ($methods as $method) {
            $lastRoute = self::add(strtoupper($method), $path_uri, $handle, $middleware);
        }
        return $lastRoute;
    }

    // -----------------------------------------------------------------------
    // Convenience Routes
    // -----------------------------------------------------------------------

    /**
     * Registers a redirect route.
     *
     * Responds to GET requests on `$from` with an HTTP redirect to `$to`.
     *
     * Example:
     * ```php
     * Router::redirect('/old-path', '/new-path', 301);
     * ```
     *
     * @param  string $from   Source URI to match.
     * @param  string $to     Destination URI to redirect to.
     * @param  int    $status HTTP status code (default `302` — temporary redirect).
     * @return Route
     */
    public static function redirect(string $from, string $to, int $status = 302): Route
    {
        return self::add('GET', $from, function (Request $request, Response $response) use ($to, $status): void {
            $response->status($status)->redirect($to);
        });
    }

    /**
     * Registers a route that renders a view directly, without a controller.
     *
     * Example:
     * ```php
     * Router::view('/about', 'pages.about', ['company' => 'Slenix']);
     * ```
     *
     * @param  string  $path_uri URI pattern.
     * @param  string  $view     View name (dot-notation or file path depending on engine).
     * @param  array   $data     Data array passed to the view.
     * @return Route
     */
    public static function view(string $path_uri, string $view, array $data = []): Route
    {
        return self::add('GET', $path_uri, function (Request $request, Response $response) use ($view, $data) {
            return view($view, $data);
        });
    }

    // -----------------------------------------------------------------------
    // Grouping & Middleware
    // -----------------------------------------------------------------------

    /**
     * Applies global middlewares to every route registered after this call.
     *
     * Global middlewares are always the outermost layer in the middleware
     * pipeline, running before group and route-level middlewares.
     *
     * Example:
     * ```php
     * Router::globalMiddleware(['cors', 'throttle:60,1']);
     * ```
     *
     * @param  string[] $middlewares Middleware class names or aliases.
     * @return void
     */
    public static function globalMiddleware(array $middlewares): void
    {
        self::$globalMiddlewares = array_merge(self::$globalMiddlewares, $middlewares);
    }

    /**
     * Wraps a set of routes under a shared middleware without a URI prefix.
     *
     * Syntactic sugar for `group(['middleware' => ...], $callback)`.
     *
     * Example:
     * ```php
     * Router::middleware('auth', function () {
     *     Router::get('/dashboard', [DashboardController::class, 'index']);
     * });
     * ```
     *
     * @param  array|string $middleware One or more middleware aliases/class names.
     * @param  callable     $handle     Callback that registers the grouped routes.
     * @return void
     */
    public static function middleware(array|string $middleware, callable $handle): void
    {
        self::group(['middleware' => is_string($middleware) ? [$middleware] : $middleware], $handle);
    }

    /**
     * Groups routes under a shared URI prefix and/or middleware set.
     *
     * Groups may be nested. Each nesting level inherits the prefix and
     * middlewares of its parent.
     *
     * Supported `$configs` keys:
     *   - `prefix`     (string)   URI segment prepended to all routes in the group.
     *   - `middleware` (string[]) Middlewares applied to all routes in the group.
     *
     * Example:
     * ```php
     * Router::group(['prefix' => '/api/v1', 'middleware' => ['jwt']], function () {
     *     Router::get('/users',    [UserController::class, 'index']);
     *     Router::post('/users',   [UserController::class, 'store']);
     *     Router::delete('/users/{id}', [UserController::class, 'destroy']);
     * });
     * ```
     *
     * @param  array    $configs Configuration array (`prefix`, `middleware`).
     * @param  callable $handle  Callback that registers routes within the group.
     * @return void
     */
    public static function group(array $configs, callable $handle): void
    {
        $previousPrefix = self::$prefix;
        $previousMiddleware = self::$groupMiddlewares;

        if (isset($configs['prefix'])) {
            self::$prefix[] = rtrim($configs['prefix'], '/') . '/';
        }

        if (isset($configs['middleware'])) {
            self::$groupMiddlewares = array_merge(
                self::$groupMiddlewares,
                (array) $configs['middleware']
            );
        }

        $handle();

        // Restore state after the group callback finishes (supports nesting).
        self::$prefix = $previousPrefix;
        self::$groupMiddlewares = $previousMiddleware;
    }

    // -----------------------------------------------------------------------
    // WebSocket Routes
    // -----------------------------------------------------------------------

    /**
     * Registers a WebSocket route.
     *
     * The handler class must extend `WebSocketHandler` and implement the
     * required event methods (`onOpen`, `onMessage`, `onClose`, etc.).
     *
     * Example:
     * ```php
     * Router::websocket('/ws/chat', App\WebSockets\ChatHandler::class);
     * ```
     *
     * @param  string $path         URI path for the WebSocket upgrade, e.g. `/ws/chat`.
     * @param  string $handlerClass Fully-qualified class name of the WebSocket handler.
     * @return void
     */
    public static function websocket(string $path, string $handlerClass): void
    {
        self::$webSocketRoutes[$path] = $handlerClass;
    }

    /**
     * Returns all registered WebSocket routes.
     *
     * @return array<string, string> Associative map of URI path => handler class name.
     */
    public static function getWebSocketRoutes(): array
    {
        return self::$webSocketRoutes;
    }

    // -----------------------------------------------------------------------
    // Route Metadata
    // -----------------------------------------------------------------------

    /**
     * Sets a name on a registered route by its internal index.
     *
     * This method is called internally by {@see Route::name()} and should
     * not typically be used directly.
     *
     * @param  int    $routeIndex Internal index of the route in `$routes`.
     * @param  string $name       Human-readable route name, e.g. `'users.show'`.
     * @return void
     */
    public static function setRouteName(int $routeIndex, string $name): void
    {
        if (isset(self::$routes[$routeIndex])) {
            self::$routes[$routeIndex]['name'] = $name;
        }
    }

    /**
     * Appends middleware(s) to a registered route by its internal index.
     *
     * This method is called internally by {@see Route::middleware()} and
     * should not typically be used directly.
     *
     * @param  int          $routeIndex Internal route index.
     * @param  array|string $middleware Middleware class name(s) or alias(es) to append.
     * @return void
     */
    public static function setRouteMiddleware(int $routeIndex, array|string $middleware): void
    {
        if (isset(self::$routes[$routeIndex])) {
            self::$routes[$routeIndex]['middleware'] = array_merge(
                self::$routes[$routeIndex]['middleware'],
                (array) $middleware
            );
        }
    }

    // -----------------------------------------------------------------------
    // URL Generation
    // -----------------------------------------------------------------------

    /**
     * Generates the URL for a named route, substituting URI parameters.
     *
     * Required parameters must be present in `$params`. Optional parameters
     * (declared as `{param?}`) are simply omitted when not provided.
     *
     * Example:
     * ```php
     * // Route: /users/{id}  named 'users.show'
     * Router::route('users.show', ['id' => 42]); // → '/users/42'
     *
     * // Route: /search/{query?}  named 'search'
     * Router::route('search');                   // → '/search'
     * Router::route('search', ['query' => 'php']); // → '/search/php'
     * ```
     *
     * @param  string  $name   The route name.
     * @param  array   $params Key-value pairs for URI parameter substitution.
     * @return string|null     The generated URL, or `null` if the route is not found.
     *
     * @throws \RuntimeException If a required URI parameter is missing from `$params`.
     */
    public static function route(string $name, array $params = []): ?string
    {
        foreach (self::$routes as $route) {
            if (!isset($route['name']) || $route['name'] !== $name) {
                continue;
            }

            $url = $route['pathUri'];
            $matches = [];

            if (preg_match_all('/\{([a-zA-Z0-9_]+)(\?)?\}/', $url, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $placeholder = $match[1];
                    $isOptional = isset($match[2]);
                    $token = $match[0]; // e.g. {id} or {id?}

                    if (!$isOptional && !isset($params[$placeholder])) {
                        throw new \RuntimeException(
                            "Missing required parameter '{$placeholder}' for route '{$name}'."
                        );
                    }

                    $url = str_replace($token, $params[$placeholder] ?? '', $url);
                }
            }

            // Collapse double slashes and strip trailing slash (keep root '/').
            $url = preg_replace('#/+#', '/', $url);
            return rtrim($url, '/') ?: '/';
        }

        return null;
    }

    /**
     * Checks whether a route exists by name or by HTTP method + URI pattern.
     *
     * Accepts two call signatures:
     *   - `Router::has('route.name')`          → looks up by route name.
     *   - `Router::has('GET', '/users/{id}')` → looks up by method and URI pattern.
     *
     * Example:
     * ```php
     * Router::has('users.show');          // true if a named route exists
     * Router::has('POST', '/login');      // true if that method+path is registered
     * ```
     *
     * @param  string      $nameOrMethod  Route name, or HTTP method when $pathUri is provided.
     * @param  string|null $pathUri       URI pattern to match against (when checking by method).
     * @return bool                       `true` if a matching route is found.
     */
    public static function has(string $nameOrMethod, ?string $pathUri = null): bool
    {
        // Signature: has('GET', '/users/{id}') — method + URI lookup
        if ($pathUri !== null) {
            $method = strtoupper($nameOrMethod);

            foreach (self::$routes as $route) {
                if (
                    $route['method'] === $method &&
                    $route['pathUri'] === $pathUri
                ) {
                    return true;
                }
            }

            return false;
        }

        // Signature: has('users.show') — named route lookup
        foreach (self::$routes as $route) {
            if (isset($route['name']) && $route['name'] === $nameOrMethod) {
                return true;
            }
        }

        return false;
    }

    // -----------------------------------------------------------------------
    // Inspection & Testing Helpers
    // -----------------------------------------------------------------------

    /**
     * Returns all routes currently registered.
     *
     * @return array<int, array{method: string, pathUri: string, handler: callable|array, middleware: array, name: string|null}>
     */
    public static function getRoutes(): array
    {
        return self::$routes;
    }

    /**
     * Resets all internal router state.
     *
     * Clears routes, prefixes, group middlewares, global middlewares, and
     * WebSocket routes. Useful in test suites to start each test with a
     * clean slate.
     *
     * @return void
     */
    public static function clearRoutes(): void
    {
        self::$routes = [];
        self::$prefix = [];
        self::$groupMiddlewares = [];
        self::$globalMiddlewares = [];
        self::$webSocketRoutes = [];
    }

    // -----------------------------------------------------------------------
    // Dispatching
    // -----------------------------------------------------------------------

    /**
     * Dispatches the incoming HTTP request to the first matching route.
     *
     * Process:
     *   1. Builds `Request` and `Response` objects.
     *   2. Iterates registered routes and matches URI + method via regex.
     *   3. Validates the CSRF token for mutating methods when required.
     *   4. Wraps the handler in the middleware pipeline (outermost first).
     *   5. Executes the pipeline and resolves the handler return value:
     *      - `string` → sent as `text/html`.
     *      - `array`  → encoded and sent as `application/json`.
     *      - `int`    → sets HTTP status with an empty body.
     *      - `null`   → assumes the handler managed output itself.
     *   6. Responds with 404 if no route matches.
     *
     * @return void
     */
    public static function dispatch(): void
    {
        $request = new Request();
        $response = new Response();
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

        foreach (self::$routes as $route) {
            $pattern = self::buildPattern($route['pathUri']);

            if ($route['method'] !== $method || !preg_match($pattern, $uriPath, $matches)) {
                continue;
            }

            // Extract named URI parameters, discarding numeric keys.
            $params = array_filter($matches, fn($key) => !is_int($key), ARRAY_FILTER_USE_KEY);

            // CSRF guard for mutating requests.
            if (self::shouldValidateCsrf($method) && !self::validateCsrfToken()) {
                $response->status(419);
                $message = '419 — Invalid or expired CSRF token.';
                if ($request->expectsJson()) {
                    header('Content-Type: application/json');
                    echo json_encode(['error' => $message]);
                } else {
                    echo $message;
                }
                return;
            }

            // Core handler — resolves the return value into an HTTP response.
            $handler = function (Request $req, Response $res) use ($route, $params): void {
                $result = is_callable($route['handler'])
                    ? call_user_func($route['handler'], $req, $res, $params)
                    : self::callControllerMethod($route['handler'], $req, $res, $params);

                self::resolveHandlerReturn($result);
            };

            // Wrap handler in the middleware pipeline (reversed for correct order).
            $pipeline = self::buildPipeline($handler, $route['middleware']);
            $pipeline($request, $response);
            return;
        }

        $response->status(404);
        self::handleNotFound();
    }

    // -----------------------------------------------------------------------
    // Internal Helpers
    // -----------------------------------------------------------------------

    /**
     * Adds a new route to the internal collection.
     *
     * Prepends any active group prefixes to the URI and merges all
     * middleware layers (global → group → route-level).
     *
     * @param  string         $method     HTTP verb (uppercase).
     * @param  string         $path_uri   URI pattern relative to the current prefix stack.
     * @param  callable|array $handle     Handler closure or `[Controller::class, 'method']`.
     * @param  string[]       $middleware Route-specific middlewares.
     * @return Route                      Fluent Route object for optional chaining.
     */
    public static function add(string $method, string $path_uri, callable|array $handle, array $middleware = []): Route
    {
        $path = !empty(self::$prefix)
            ? implode('', self::$prefix) . ltrim($path_uri, '/')
            : $path_uri;

        $routeIndex = count(self::$routes);

        self::$routes[$routeIndex] = [
            'method' => strtoupper($method),
            'pathUri' => $path,
            'handler' => $handle,
            'middleware' => array_merge(self::$globalMiddlewares, self::$groupMiddlewares, $middleware),
            'name' => null,
        ];

        return new Route($routeIndex);
    }

    /**
     * Instantiates a controller and injects a FormRequest when the method
     * type-hints one, otherwise passes the standard Request.
     *
     * @param array   $handler [ControllerClass, method]
     * @param Request $req
     * @param Response $res
     * @param array   $params
     * @return mixed
     */
    private static function callControllerMethod(
        array $handler,
        Request $req,
        Response $res,
        array $params
    ): mixed {
        [$controllerClass, $method] = $handler;
        $controller = new $controllerClass();

        // Inspect the method's first parameter type hint
        try {
            $reflection = new \ReflectionMethod($controllerClass, $method);
            $parameters = $reflection->getParameters();

            if (!empty($parameters)) {
                $firstParam = $parameters[0];
                $type = $firstParam->getType();

                if (
                    $type instanceof \ReflectionNamedType &&
                    !$type->isBuiltin() &&
                    is_subclass_of($type->getName(), \Slenix\Http\FormRequest::class)
                ) {
                    $formRequestClass = $type->getName();
                    $formRequest = \Slenix\Http\FormRequest::createAndValidate($formRequestClass);

                    return $controller->$method($formRequest, $res, $params);
                }
            }
        } catch (\ReflectionException) {
            // Fall through to standard call
        }

        return $controller->$method($req, $res, $params);
    }

    /**
     * Converts a URI pattern with `{param}` / `{param?}` tokens into a
     * named-capture regex pattern.
     *
     * Examples:
     *   `/users/{id}`       → `@^/users/(?P<id>[a-zA-Z0-9_-]+)$@`
     *   `/search/{query?}`  → `@^/search/(?P<query>[a-zA-Z0-9_-]*)$@`
     *
     * @param  string $pathUri Route URI pattern.
     * @return string          PCRE regex string ready for `preg_match()`.
     */
    private static function buildPattern(string $pathUri): string
    {
        $pattern = preg_replace('/\{([a-zA-Z0-9_]+)\?\}/', '(?P<$1>[a-zA-Z0-9_-]*)', $pathUri);
        $pattern = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<$1>[a-zA-Z0-9_-]+)', $pattern);
        return '@^' . $pattern . '$@';
    }

    /**
     * Builds the middleware pipeline as a chain of nested closures.
     *
     * Middlewares are reversed so the first item in the array is the
     * outermost wrapper (runs first on request, last on response).
     *
     * @param  callable $handler     The resolved route handler.
     * @param  string[] $middlewares Ordered list of middleware aliases or class names.
     * @return callable              The outermost pipeline closure.
     *
     * @throws \Exception If a middleware class does not implement {@see Middleware}.
     */
    private static function buildPipeline(callable $handler, array $middlewares): callable
    {
        $pipeline = $handler;

        foreach (array_reverse($middlewares) as $middlewareClass) {
            $resolvedClass = self::resolveMiddlewareAlias($middlewareClass);
            $middlewareInstance = new $resolvedClass();

            if (!$middlewareInstance instanceof Middleware) {
                throw new \Exception(
                    sprintf(
                        'Middleware "%s" must implement the Middleware interface.',
                        get_class($middlewareInstance)
                    )
                );
            }

            $pipeline = function (Request $req, Response $res) use ($middlewareInstance, $pipeline): mixed {
                return $middlewareInstance->handle($req, $res, $pipeline);
            };
        }

        return $pipeline;
    }

    /**
     * Resolves a handler's return value and sends the appropriate HTTP response.
     *
     * This enables Laravel-style handlers where the return value drives output:
     *
     * | Return type | Behaviour                                                   |
     * |-------------|-------------------------------------------------------------|
     * | `string`    | Sends the string as `text/html; charset=UTF-8`.             |
     * | `array`     | JSON-encodes and sends as `application/json; charset=UTF-8`.|
     * | `int`       | Sets the HTTP status code; sends an empty body.             |
     * | `null`      | No-op — the handler is assumed to have managed output.      |
     *
     * @param  mixed $result Value returned by the route handler.
     * @return void
     */
    private static function resolveHandlerReturn(mixed $result): void
    {
        if (is_string($result)) {
            if (!headers_sent()) {
                header('Content-Type: text/html; charset=UTF-8');
            }
            echo $result;
            return;
        }

        if (is_array($result)) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=UTF-8');
            }
            echo json_encode($result);
            return;
        }

        if (is_int($result)) {
            http_response_code($result);
            return;
        }

        // null / void — handler managed its own output.
    }

    /**
     * Determines whether CSRF validation is required for the current request.
     *
     * Validation is triggered when:
     *   - The HTTP method is `POST`, `PUT`, `PATCH`, or `DELETE`, **and**
     *   - A CSRF token field/header is present, **or**
     *   - The request is a cross-origin form submission.
     *
     * @param  string $method Current HTTP method in uppercase.
     * @return bool           `true` if CSRF validation should run, `false` otherwise.
     */
    private static function shouldValidateCsrf(string $method): bool
    {
        $writeMethods = ['POST', 'PUT', 'PATCH', 'DELETE'];

        if (!in_array($method, $writeMethods, true)) {
            return false;
        }

        if (isset($_POST['_csrf_token']) || isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            return true;
        }

        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $isFormSubmit = str_contains($contentType, 'application/x-www-form-urlencoded')
            || str_contains($contentType, 'multipart/form-data');

        if ($isFormSubmit) {
            $host = $_SERVER['HTTP_HOST'] ?? '';
            $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
            $referer = $_SERVER['HTTP_REFERER'] ?? '';

            if ($origin && !str_contains($origin, $host)) {
                return true;
            }

            if ($referer && !str_contains($referer, $host)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validates the CSRF token present in the current request.
     *
     * Checks `$_POST['_csrf_token']` first, then the
     * `X-CSRF-TOKEN` HTTP header.
     *
     * @return bool `true` if the token is valid, `false` otherwise.
     */
    private static function validateCsrfToken(): bool
    {
        $token = $_POST['_csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

        if (empty($token)) {
            return false;
        }

        return CSRF::verify();
    }

    /**
     * Resolves a middleware alias to its fully-qualified class name.
     *
     * Built-in aliases:
     *   - `auth`          → `App\Middlewares\AuthMiddleware`
     *   - `guest`         → `App\Middlewares\GuestMiddleware`
     *   - `cors`          → `App\Middlewares\CorsMiddleware`
     *   - `jwt`           → `App\Middlewares\JwtMiddleware`
     *   - `throttle`      → `App\Middlewares\ThrottleMiddleware`
     *   - `throttle:60,1` → same class; parameters are forwarded via server var.
     *
     * If `$alias` is not a known alias, it is returned unchanged (assumed to
     * be a fully-qualified class name already).
     *
     * @param  string $alias Middleware alias or class name, optionally with params (e.g. `throttle:60,1`).
     * @return string        Fully-qualified middleware class name.
     */
    private static function resolveMiddlewareAlias(string $alias): string
    {
        $base = $alias;
        $params = '';

        if (str_contains($alias, ':')) {
            [$base, $params] = explode(':', $alias, 2);
        }

        // Forward throttle params via server variable so the middleware can read them.
        if ($base === 'throttle') {
            if ($params !== '') {
                $_SERVER['HTTP_X_THROTTLE_PARAMS'] = "throttle:{$params}";
            } else {
                unset($_SERVER['HTTP_X_THROTTLE_PARAMS']);
            }
        }

        $aliases = [
            'auth' => 'App\\Middlewares\\AuthMiddleware',
            'guest' => 'App\\Middlewares\\GuestMiddleware',
            'cors' => 'App\\Middlewares\\CorsMiddleware',
            'jwt' => 'App\\Middlewares\\JwtMiddleware',
            'throttle' => 'App\\Middlewares\\ThrottleMiddleware',
        ];

        return $aliases[$base] ?? $alias;
    }

    /**
     * Handles unmatched requests by rendering the 404 error page.
     *
     * Searches a list of conventional view paths for a `404.php` file.
     * If none is found, throws a `RuntimeException`.
     *
     * @return void
     * @throws \RuntimeException If no 404 view file can be located.
     */
    private static function handleNotFound(): void
    {
        $errorPaths = [
            __DIR__ . '/../../../views/errors/404.php',
            __DIR__ . '/../../../views/error/404.php',
            __DIR__ . '/../../../views/erro/404.php',
            __DIR__ . '/../../Core/Exceptions/errors/404.php',
        ];

        foreach ($errorPaths as $path) {
            if (file_exists($path)) {
                require_once $path;
                return;
            }
        }

        throw new \RuntimeException('404 — Page not found.');
    }
}