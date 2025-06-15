<?php
/*
|--------------------------------------------------------------------------
| Classe AuthMiddleware
|--------------------------------------------------------------------------
|
| Este middleware verifica se o usuário está autenticado, com base na
| existência de um ID de usuário na sessão.
|
*/
declare(strict_types=1);

namespace Slenix\Middlewares;

use Slenix\Http\Message\Middleware;
use Slenix\Http\Message\Request;
use Slenix\Http\Message\Response;

class AuthMiddleware implements Middleware
{
    /**
     * Verifica se o usuário está autenticado.
     *
     * @param Request $request A requisição HTTP.
     * @param Response $response A resposta HTTP.
     * @param array $param Parâmetros da rota.
     * @return bool Retorna true se autenticado, false caso contrário.
     */
    public function handle(Request $request, Response $response, array $param): bool
    {
        // Garante que a sessão está ativa
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!isset($_SESSION['user_id'])) {
            $response->status(401)->json(['error' => 'Unauthorized: User not authenticated']);
            return false;
        }

        return true;
    }
}