<?php

declare(strict_types=1);

namespace Slenix\Middlewares;

use Slenix\Http\Auth\Jwt;
use Slenix\Http\Message\Request;
use Slenix\Http\Message\Response;

class JwtMiddleware
{
    private Jwt $jwt;

    public function __construct()
    {
        $this->jwt = new Jwt();
    }

    /**
     * Executa o middleware para verificar o token JWT.
     *
     * @param Request $request
     * @param Response $response
     * @param callable $next
     * @return Response
     */
    public function handle(Request $request, Response $response, callable $next): Response
    {
        $authHeader = $request->getHeader('Authorization');
        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            $response->status(401)->json(['error' => 'Token não fornecido']);
        }

        $token = substr($authHeader, 7); // Remove "Bearer " do início
        $payload = $this->jwt->validate($token);

        if (!$payload) {
            $response->status(401)->json(['error' => 'Token inválido ou expirado']);
        }

        // Adiciona o payload ao request para uso posterior
        $request->setAttribute('jwt_payload', $payload);

        // Prossegue para o próximo middleware ou controlador
        return $next($request, $response);
    }
}