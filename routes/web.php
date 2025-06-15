<?php

declare(strict_types=1);

use Slenix\Http\Message\Request;
use Slenix\Http\Message\Response;
use Slenix\Http\Message\Router;

Router::get('/', function (Request $request, Response $response) {
    return view('welcome');
});
