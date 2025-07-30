<?php
/*
|--------------------------------------------------------------------------
| Classe Router
|--------------------------------------------------------------------------
|
| Esta classe gerencia as rotas da aplicação, mapeando URIs para handlers
| (funções ou métodos de classes) e aplicando middlewares. Suporta diferentes
| verbos HTTP, agrupamento de rotas com prefixos e middlewares, e nomeação de rotas.
|
*/
declare(strict_types=1);

namespace Slenix\Http\Message;

class Router
{
    /**
     * @var array Armazena todas as rotas definidas.
     */
    private static array $routes = [];

    /**
     * @var array Armazena os prefixos de grupo de rotas.
     */
    private static array $prefix = [];

    /**
     * @var array Armazena os middlewares de grupo de rotas.
     */
    private static array $groupMiddlewares = [];

    /**
     * Define o nome de uma rota com base no seu índice.
     *
     * @param int $routeIndex O índice da rota no array $routes.
     * @param string $name O nome da rota.
     * @return void
     */
    public static function setRouteName(int $routeIndex, string $name): void
    {
        if (isset(self::$routes[$routeIndex])) {
            self::$routes[$routeIndex]['name'] = $name;
        }
    }

    /**
     * Define uma rota para o método HTTP GET.
     *
     * @param string $path_uri A URI da rota.
     * @param callable|array $handle A função ou array [classe, método] a ser executado.
     * @param array $middleware Um array de classes de middlewares a serem aplicadas.
     * @return Route
     */
    public static function get(string $path_uri, callable|array $handle, array $middleware = []): Route
    {
        return self::add('GET', $path_uri, $handle, $middleware);
    }

    /**
     * Define uma rota para o método HTTP POST.
     *
     * @param string $path_uri A URI da rota.
     * @param callable|array $handle A função ou array [classe, método] a ser executado.
     * @param array $middleware Um array de classes de middlewares a serem aplicadas.
     * @return Route
     */
    public static function post(string $path_uri, callable|array $handle, array $middleware = []): Route
    {
        return self::add('POST', $path_uri, $handle, $middleware);
    }

    /**
     * Define uma rota para o método HTTP PUT.
     *
     * @param string $path_uri A URI da rota.
     * @param callable|array $handle A função ou array [classe, método] a ser executado.
     * @param array $middleware Um array de classes de middlewares a serem aplicadas.
     * @return Route
     */
    public static function put(string $path_uri, callable|array $handle, array $middleware = []): Route
    {
        return self::add('PUT', $path_uri, $handle, $middleware);
    }

    /**
     * Define uma rota para o método HTTP PATCH.
     *
     * @param string $path_uri A URI da rota.
     * @param callable|array $handle A função ou array [classe, método] a ser executado.
     * @param array $middleware Um array de classes de middlewares a serem aplicadas.
     * @return Route
     */
    public static function patch(string $path_uri, callable|array $handle, array $middleware = []): Route
    {
        return self::add('PATCH', $path_uri, $handle, $middleware);
    }

    /**
     * Define uma rota para o método HTTP DELETE.
     *
     * @param string $path_uri A URI da rota.
     * @param callable|array $handle A função ou array [classe, método] a ser executado.
     * @param array $middleware Um array de classes de middlewares a serem aplicadas.
     * @return Route
     */
    public static function delete(string $path_uri, callable|array $handle, array $middleware = []): Route
    {
        return self::add('DELETE', $path_uri, $handle, $middleware);
    }

    /**
     * Define uma rota para o método HTTP OPTIONS.
     *
     * @param string $path_uri A URI da rota.
     * @param callable|array $handle A função ou array [classe, método] a ser executado.
     * @param array $middleware Um array de classes de middlewares a serem aplicadas.
     * @return Route
     */
    public static function options(string $path_uri, callable|array $handle, array $middleware = []): Route
    {
        return self::add('OPTIONS', $path_uri, $handle, $middleware);
    }

    /**
     * Define uma rota para qualquer método HTTP.
     *
     * @param string $path_uri A URI da rota.
     * @param callable|array $handle A função ou array [classe, método] a ser executado.
     * @param array $middleware Um array de classes de middlewares a serem aplicadas.
     * @return Route
     */
    public static function any(string $path_uri, callable|array $handle, array $middleware = []): Route
    {
        return self::add($_SERVER['REQUEST_METHOD'] ?? 'GET', $path_uri, $handle, $middleware);
    }

    /**
     * Define uma rota para múltiplos métodos HTTP.
     *
     * @param array $methods Array de métodos HTTP (ex: ['GET', 'POST']).
     * @param string $path_uri A URI da rota.
     * @param callable|array $handle A função ou array [classe, método] a ser executado.
     * @param array $middleware Um array de classes de middlewares a serem aplicadas.
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
     * Adiciona uma nova rota ao array de rotas.
     *
     * @param string $method O método HTTP da rota.
     * @param string $path_uri A URI da rota.
     * @param callable|array $handle A função ou array [classe, método] a ser executado.
     * @param array $middleware Um array de classes de middlewares a serem aplicadas.
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
            'middleware' => array_merge(self::$groupMiddlewares, $middleware),
            'name' => null, // Inicialmente, a rota não tem nome
        ];

        return new Route($routeIndex);
    }

    /**
     * Agrupa rotas sob um prefixo e/ou middlewares.
     *
     * @param array $configs Configurações do grupo ('prefix' e/ou 'middleware').
     * @param callable $handle Função que define as rotas do grupo.
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
     * Gera a URL para uma rota com base no seu nome.
     *
     * @param string $name O nome da rota.
     * @param array $params Parâmetros para substituir na URL.
     * @return string|null A URL gerada ou null se a rota não for encontrada.
     * @throws \Exception Se parâmetros obrigatórios estiverem faltando.
     */
    public static function route(string $name, array $params = []): ?string
    {
        foreach (self::$routes as $route) {
            if (isset($route['name']) && $route['name'] === $name) {
                $url = $route['pathUri'];
                $matches = [];
                if (preg_match_all('/\{([a-zA-Z0-9_]+)\}/', $url, $matches)) {
                    $placeholders = $matches[1];
                    foreach ($placeholders as $placeholder) {
                        if (!isset($params[$placeholder])) {
                            throw new \Exception("Parâmetro obrigatório '$placeholder' não fornecido para a rota '$name'.");
                        }
                        $url = str_replace('{' . $placeholder . '}', $params[$placeholder], $url);
                    }
                }
                return $url;
            }
        }
        return null;
    }

    /**
     * Despacha a requisição para a rota correspondente.
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
            $pattern = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<$1>[a-zA-Z0-9_-]+)', $route['pathUri']);
            $pattern = '@^' . $pattern . '$@';

            if ($route['method'] === $method && preg_match($pattern, $uriPath, $matches)) {
                $paramskey = array_filter($matches, fn($key) => !is_int($key), ARRAY_FILTER_USE_KEY);

                $next = function () use ($route, $paramskey, $request, $response): void {
                    if (is_callable($route['handler'])) {
                        call_user_func($route['handler'], $request, $response, $paramskey);
                    } elseif (is_array($route['handler'])) {
                        [$classCurrent, $methodCurrent] = $route['handler'];
                        call_user_func_array([new $classCurrent, $methodCurrent], [$request, $response, $paramskey]);
                    }
                };

                $middlewareStack = array_reverse($route['middleware']);
                foreach ($middlewareStack as $middleware) {
                    $middlewareInstance = new $middleware();
                    if (!$middlewareInstance instanceof Middleware) {
                        throw new \Exception("Middleware $middleware must implement Middleware interface");
                    }
                    $result = $middlewareInstance->handle($request, $response, $paramskey);
                    if ($result === false) {
                        return; // Interrompe a execução se o middleware retornar false
                    }
                }

                $next();
                return;
            }
        }

        $response->status(404);
        require_once __DIR__ . '/../../Helpers/errors/404.php';
    }
}