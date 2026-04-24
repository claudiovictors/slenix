<?php

/*
|--------------------------------------------------------------------------
| Router Class — Slenix Framework
|--------------------------------------------------------------------------
|
| This class manages application routes, mapping URIs to handlers 
| (functions or class methods) and applying middlewares. It supports 
| different HTTP verbs, route grouping with prefixes and middlewares, 
| and route naming.
|
*/

declare(strict_types=1);

namespace Slenix\Http\Routing;

use Slenix\Http\Request;
use Slenix\Http\Response;
use Slenix\Supports\Security\CSRF;
use Slenix\Http\Middlewares\Middleware;

class Router
{
    /** @var array Stores all defined routes. */
    private static array $routes = [];

    /** @var array Stores route group prefixes. */
    private static array $prefix = [];

    /** @var array Stores route group middlewares. */
    private static array $groupMiddlewares = [];

    /** @var array Stores global middlewares. */
    private static array $globalMiddlewares = [];

    /** @var array<string, string> WebSocket path => handler class mapping. */
    private static array $webSocketRoutes = [];

    /**
     * Sets the name of a route based on its index.
     *
     * @param int    $routeIndex The index of the route in the $routes array.
     * @param string $name       The name of the route.
     * @return void
     */
    public static function setRouteName(int $routeIndex, string $name): void
    {
        if (isset(self::$routes[$routeIndex])) {
            self::$routes[$routeIndex]['name'] = $name;
        }
    }

    /**
     * Adds middleware to a specific route by index.
     *
     * @param int          $routeIndex The route index.
     * @param array|string $middleware Middleware(s) to be added.
     * @return void
     */
    public static function setRouteMiddleware(int $routeIndex, array|string $middleware): void
    {
        if (isset(self::$routes[$routeIndex])) {
            $middlewareArray = is_string($middleware) ? [$middleware] : $middleware;
            self::$routes[$routeIndex]['middleware'] = array_merge(
                self::$routes[$routeIndex]['middleware'],
                $middlewareArray
            );
        }
    }

    /**
     * Defines global middlewares that will be applied to all routes.
     *
     * @param array $middlewares Array of global middlewares.
     * @return void
     */
    public static function globalMiddleware(array $middlewares): void
    {
        self::$globalMiddlewares = array_merge(self::$globalMiddlewares, $middlewares);
    }

    /**
     * Defines a route for the GET HTTP method.
     *
     * @param string         $path_uri   The route URI.
     * @param callable|array $handle     The function or [class, method] array to execute.
     * @param array          $middleware Array of middlewares.
     * @return Route
     */
    public static function get(string $path_uri, callable|array $handle, array $middleware = []): Route
    {
        return self::add('GET', $path_uri, $handle, $middleware);
    }

    /**
     * Defines a route for the POST HTTP method.
     */
    public static function post(string $path_uri, callable|array $handle, array $middleware = []): Route
    {
        return self::add('POST', $path_uri, $handle, $middleware);
    }

    /**
     * Defines a route for the PUT HTTP method.
     */
    public static function put(string $path_uri, callable|array $handle, array $middleware = []): Route
    {
        return self::add('PUT', $path_uri, $handle, $middleware);
    }

    /**
     * Defines a route for the PATCH HTTP method.
     */
    public static function patch(string $path_uri, callable|array $handle, array $middleware = []): Route
    {
        return self::add('PATCH', $path_uri, $handle, $middleware);
    }

    /**
     * Defines a route for the DELETE HTTP method.
     */
    public static function delete(string $path_uri, callable|array $handle, array $middleware = []): Route
    {
        return self::add('DELETE', $path_uri, $handle, $middleware);
    }

    /**
     * Defines a route for the OPTIONS HTTP method.
     */
    public static function options(string $path_uri, callable|array $handle, array $middleware = []): Route
    {
        return self::add('OPTIONS', $path_uri, $handle, $middleware);
    }

    /**
     * Defines a route for any HTTP method.
     */
    public static function any(string $path_uri, callable|array $handle, array $middleware = []): Route
    {
        return self::add($_SERVER['REQUEST_METHOD'] ?? 'GET', $path_uri, $handle, $middleware);
    }

    /**
     * Defines a route for multiple HTTP methods.
     *
     * @param array          $methods  Array of HTTP methods (e.g., ['GET', 'POST']).
     * @param string         $path_uri The route URI.
     * @param callable|array $handle   Action handler.
     * @param array          $middleware Array of middlewares.
     * @return Route
     */
    public static function match(array $methods, string $path_uri, callable|array $handle, array $middleware = []): Route
    {
        $lastRoute = null;
        foreach ($methods as $method) {
            $lastRoute = self::add($method, $path_uri, $handle, $middleware);
        }
        return $lastRoute;
    }

    /**
     * Defines a redirect route.
     *
     * @param string $from   Source URI.
     * @param string $to     Destination URI.
     * @param int    $status HTTP status code (default 302).
     * @return Route
     */
    public static function redirect(string $from, string $to, int $status = 302): Route
    {
        return self::add('GET', $from, function ($request, $response) use ($to, $status) {
            $response->status($status)->redirect($to);
        });
    }

    /**
     * Defines a view directly for a route.
     *
     * @param string $path_uri The route URI.
     * @param string $view     View name.
     * @param array  $data     Data to pass to the view.
     * @return Route
     */
    public static function view(string $path_uri, string $view, array $data = []): Route
    {
        return self::add('GET', $path_uri, function ($request, $response) use ($view, $data) {
            return view($view, $data);
        });
    }

    /**
     * Creates a route group with middleware only (no prefix).
     *
     * @param array|string $middleware Middleware(s) to apply.
     * @param callable     $handle     Function defining the routes in the group.
     * @return void
     */
    public static function middleware(array|string $middleware, callable $handle): void
    {
        self::group(['middleware' => is_string($middleware) ? [$middleware] : $middleware], $handle);
    }

    /**
     * Registers a WebSocket route.
     *
     * @param string $path         URI path, e.g. '/ws/chat'
     * @param string $handlerClass Fully-qualified class name (extends WebSocketHandler)
     */
    public static function websocket(string $path, string $handlerClass): void
    {
        self::$webSocketRoutes[$path] = $handlerClass;
    }

    /**
     * Returns all registered WebSocket routes.
     *
     * @return array<string, string> path => handlerClass
     */
    public static function getWebSocketRoutes(): array
    {
        return self::$webSocketRoutes;
    }

    /**
     * Internal method to add a new route to the collection.
     *
     * @param string         $method     HTTP method.
     * @param string         $path_uri   The route URI.
     * @param callable|array $handle     Action handler.
     * @param array          $middleware Array of middlewares.
     * @return Route
     */
    private static function add(string $method, string $path_uri, callable|array $handle, array $middleware = []): Route
    {
        $path = !empty(self::$prefix) ? implode('', self::$prefix) . ltrim($path_uri, '/') : $path_uri;

        $routeIndex = array_key_last(self::$routes) !== null ? array_key_last(self::$routes) + 1 : 0;
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
     * Groups routes under a prefix and/or middlewares.
     *
     * @param array    $configs Configuration for the group ('prefix', 'middleware').
     * @param callable $handle  Function defining the routes.
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
            self::$groupMiddlewares = array_merge(self::$groupMiddlewares, $configs['middleware']);
        }

        $handle();

        self::$prefix = $previousPrefix;
        self::$groupMiddlewares = $previousMiddleware;
    }

    /**
     * Generates a URL for a route based on its name.
     *
     * @param string $name   The name of the route.
     * @param array  $params Parameters to replace in the URL.
     * @return string|null The generated URL or null if not found.
     * @throws \RuntimeException If required parameters are missing.
     */
    public static function route(string $name, array $params = []): ?string
    {
        foreach (self::$routes as $route) {
            if (isset($route['name']) && $route['name'] === $name) {
                $url = $route['pathUri'];
                $matches = [];
                if (preg_match_all('/\{([a-zA-Z0-9_]+)\??\}/', $url, $matches)) {
                    $placeholders = $matches[1];
                    foreach ($placeholders as $placeholder) {
                        $isOptional = str_contains($matches[0][array_search($placeholder, $placeholders)], '?');
                        if (!$isOptional && !isset($params[$placeholder])) {
                            throw new \RuntimeException("Missing required parameter '$placeholder' for route '$name'.");
                        }
                        $replacement = $params[$placeholder] ?? '';
                        $url = str_replace('{' . $placeholder . '}', $replacement, $url);
                        $url = str_replace('{' . $placeholder . '?}', $replacement, $url);
                    }
                }
                $url = preg_replace('#/+#', '/', $url);
                return rtrim($url, '/') ?: '/';
            }
        }
        return null;
    }

    /**
     * Returns all registered routes.
     */
    public static function getRoutes(): array
    {
        return self::$routes;
    }

    /**
     * Clears all registered routes (useful for testing).
     */
    public static function clearRoutes(): void
    {
        self::$routes = [];
        self::$prefix = [];
        self::$groupMiddlewares = [];
        self::$globalMiddlewares = [];
        self::$webSocketRoutes = [];
    }

    /**
     * Dispatches the incoming request to the matching route.
     * * @return void
     */
    public static function dispatch(): void
    {
        $request = new Request();
        $response = new Response();
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

        foreach (self::$routes as $route) {
            $pattern = preg_replace('/\{([a-zA-Z0-9_]+)\?\}/', '(?P<$1>[a-zA-Z0-9_-]*)', $route['pathUri']);
            $pattern = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<$1>[a-zA-Z0-9_-]+)', $pattern);
            $pattern = '@^' . $pattern . '$@';

            if ($route['method'] === $method && preg_match($pattern, $uriPath, $matches)) {
                $paramskey = array_filter($matches, fn($key) => !is_int($key), ARRAY_FILTER_USE_KEY);

                // Automatic CSRF validation
                if (self::shouldValidateCsrf($method)) {
                    if (!self::validateCsrfToken()) {
                        $response->status(419);
                        if ($request->expectsJson()) {
                            header('Content-Type: application/json');
                            throw new \RuntimeException('Invalid or expired CSRF token.');
                        } else {
                            throw new \RuntimeException('419 — Invalid or expired CSRF token.');
                        }
                        return;
                    }
                }

                // Final Action Handler
                $handler = function (Request $req, Response $res) use ($route, $paramskey): void {
                    if (is_callable($route['handler'])) {
                        call_user_func($route['handler'], $req, $res, $paramskey);
                    } elseif (is_array($route['handler'])) {
                        [$class, $methodName] = $route['handler'];
                        call_user_func_array([new $class, $methodName], [$req, $res, $paramskey]);
                    }
                };

                // Middleware Pipeline
                $pipeline = $handler;
                foreach (array_reverse($route['middleware']) as $middlewareClass) {
                    $resolvedClass = self::resolveMiddlewareAlias($middlewareClass);
                    $middlewareInstance = new $resolvedClass();

                    if (!$middlewareInstance instanceof Middleware) {
                        throw new \Exception("Middleware " . get_class($middlewareInstance) . " must implement Middleware interface");
                    }

                    $pipeline = function (Request $req, Response $res) use ($middlewareInstance, $pipeline): mixed {
                        return $middlewareInstance->handle($req, $res, $pipeline);
                    };
                }

                $pipeline($request, $response);
                return;
            }
        }

        $response->status(404);
        self::handleNotFound();
    }

    /**
     * Determines if CSRF validation should be performed based on the HTTP method.
     */
    private static function shouldValidateCsrf(string $method): bool
    {
        $writeMethods = ['POST', 'PUT', 'PATCH', 'DELETE'];

        if (!in_array($method, $writeMethods)) {
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
     * Validates the CSRF token from the request.
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
     * Resolves middleware aliases, injecting parameters if needed.
     *
     * @param string $alias
     * @return string Fully-qualified middleware class name.
     */
    private static function resolveMiddlewareAlias(string $alias): string
    {
        $base   = $alias;
        $params = '';
 
        if (str_contains($alias, ':')) {
            [$base, $params] = explode(':', $alias, 2);
        }
 
        if ($base === 'throttle' && $params !== '') {
            $_SERVER['HTTP_X_THROTTLE_PARAMS'] = "throttle:{$params}";
        } elseif ($base === 'throttle') {
            unset($_SERVER['HTTP_X_THROTTLE_PARAMS']);
        }
 
        $aliases = [
            'auth'     => 'App\\Middlewares\\AuthMiddleware',
            'guest'    => 'App\\Middlewares\\GuestMiddleware',
            'cors'     => 'App\\Middlewares\\CorsMiddleware',
            'jwt'      => 'App\\Middlewares\\JwtMiddleware',
            'throttle' => 'App\\Middlewares\\ThrottleMiddleware',
        ];
 
        return $aliases[$base] ?? $alias;
    }

    /**
     * Handles 404 - Not Found requests.
     */
    private static function handleNotFound(): void
    {
        $errorPaths = [
            __DIR__ . '/../../../views/errors/404.php',
            __DIR__ . '/../../../views/error/404.php',
            __DIR__ . '/../../../views/erro/404.php',
            __DIR__ . '/../../Core/Exceptions/errors/404.php'
        ];

        foreach ($errorPaths as $path) {
            if (file_exists($path)) {
                require_once $path;
                return;
            }
        }

        throw new \RuntimeException('404 - Page not found');
    }
}