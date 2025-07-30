<?php

declare(strict_types=1);

namespace Slenix\Http\Message;

interface Middleware {
    public function handle(Request $request, Response $response, array $params): bool;
}