<?php

/*
|--------------------------------------------------------------------------
| Interface Middleware
|--------------------------------------------------------------------------
|
| Interface para middlewares que usa o padrão $next.
| O middleware deve chamar $next($request, $response) para continuar
| a cadeia de execução, ou retornar/não chamar para interromper.
|
*/

declare(strict_types=1);

namespace Slenix\Http\Middlewares;

use Slenix\Http\Request;
use Slenix\Http\Response;

interface Middleware {
    /**
     * Processa a requisição através do middleware.
     * @param Request $request
     * @param Response $response
     * @param callable $next
     * @return void
     */
    public function handle(Request $request, Response $response, callable $next): mixed;
}