<?php

/*
|--------------------------------------------------------------------------
| CorsMiddleware — Application Middleware
|--------------------------------------------------------------------------
|
| Handles Cross-Origin Resource Sharing (CORS) for routes/groups that
| explicitly opt in via ->middleware('cors'). Not applied globally —
| Slenix is fullstack, and web routes serving Luna views never need this.
|
*/

declare(strict_types=1);

namespace App\Middlewares;

use Slenix\Core\EnvLoader;
use Slenix\Http\Request;
use Slenix\Http\Response;
use Slenix\Http\Middlewares\Middleware;

class CorsMiddleware implements Middleware
{
    /**
     * @param Request  $request
     * @param Response $response
     * @param callable $next
     * @return mixed
     */
    public function handle(Request $request, Response $response, callable $next): mixed
    {
        $origin = $request->getHeader('Origin');

        // No Origin header — not a cross-origin request (curl, server-to-server,
        // same-origin fetch). Nothing to negotiate; pass through untouched.
        if (!$origin) {
            return $next($request, $response);
        }

        $allowedOrigins = $this->allowedOrigins();
        $isAllowed = in_array('*', $allowedOrigins, true) || in_array($origin, $allowedOrigins, true);

        if (!$isAllowed) {
            if ($request->isMethod('OPTIONS')) {
                $response->status(403)->send('');
            }
            // For non-preflight requests, simply omit CORS headers — the
            // browser will block the response on its own side.
            return $next($request, $response);
        }

        $credentials = (bool) EnvLoader::get('CORS_ALLOW_CREDENTIALS', false);

        // Per the CORS spec, "*" is invalid alongside credentials — always
        // reflect the exact origin when credentials are allowed.
        $resolvedOrigin = ($credentials || !in_array('*', $allowedOrigins, true))
            ? $origin
            : '*';

        $response->withCors([
            'origin'      => $resolvedOrigin,
            'methods'     => (string) EnvLoader::get('CORS_ALLOWED_METHODS', 'GET,POST,PUT,PATCH,DELETE,OPTIONS'),
            'headers'     => (string) EnvLoader::get('CORS_ALLOWED_HEADERS', 'Content-Type,Authorization,X-Requested-With,X-CSRF-Token'),
            'credentials' => $credentials,
            'max_age'     => (int) EnvLoader::get('CORS_MAX_AGE', 86400),
        ]);

        if ($resolvedOrigin !== '*') {
            // Tells caches/CDNs the response varies by Origin, so two
            // different frontends never get served each other's cached CORS headers.
            $response->withHeader('Vary', 'Origin');
        }

        // Preflight — answer immediately with no body, don't run route logic.
        if ($request->isMethod('OPTIONS')) {
            $response->status(204)->send('');
        }

        return $next($request, $response);
    }

    /**
     * @return string[]
     */
    private function allowedOrigins(): array
    {
        $raw = (string) EnvLoader::get('CORS_ALLOWED_ORIGINS', '*');
        return array_map('trim', explode(',', $raw));
    }
}