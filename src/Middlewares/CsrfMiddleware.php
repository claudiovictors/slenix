<?php
/*
|--------------------------------------------------------------------------
| Classe CsrfMiddleware
|--------------------------------------------------------------------------
|
| Este middleware valida tokens CSRF em requisições POST, PUT, PATCH e DELETE,
| garantindo proteção contra ataques de Cross-Site Request Forgery.
|
*/
declare(strict_types=1);

namespace Slenix\Middlewares;

use Slenix\Http\Auth\Csrf;
use Slenix\Http\Message\Request;
use Slenix\Http\Message\Response;
use Slenix\Http\Message\Middleware;

class CsrfMiddleware implements Middleware
{
    private Csrf $csrf;

    public function __construct()
    {
        $this->csrf = new Csrf();
    }

    /**
     * Valida o token CSRF na requisição.
     *
     * @param Request $request A requisição HTTP.
     * @param Response $response A resposta HTTP.
     * @param array $param Parâmetros da rota.
     * @return bool Retorna true se a validação passar, false caso contrário.
     */
    public function handle(Request $request, Response $response, array $param): bool
    {
        $method = $request->method();

        // Ignora validação para métodos seguros
        if (in_array($method, ['GET', 'OPTIONS', 'HEAD'])) {
            return true;
        }
        $tokenKey = $this->csrf::getTokenKey();
        $token = $_POST[$tokenKey] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

        if (!$this->csrf->checkToken($token)) {
            $response->status(403)->json(['error' => 'Invalid CSRF token']);
            return false;
        }

        return true;
    }
}