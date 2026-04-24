<?php

/*
|--------------------------------------------------------------------------
| Middleware Interface — Slenix Framework
|--------------------------------------------------------------------------
|
| Contract for HTTP middlewares using the $next callback pattern.
| A middleware must call $next($request, $response) to continue the 
| execution chain, or return a response to interrupt it.
|
*/

declare(strict_types=1);

namespace Slenix\Http\Middlewares;

use Slenix\Http\Request;
use Slenix\Http\Response;

interface Middleware
{
    /**
     * Process an incoming request through the middleware.
     * * @param Request  $request  The current request instance.
     * @param Response $response The current response instance.
     * @param callable $next     The next middleware/action in the stack.
     * @return mixed
     */
    public function handle(Request $request, Response $response, callable $next): mixed;
}