<?php

declare(strict_types=1);

use Slenix\Http\Message\Router;

Router::get('/', function($request, $response, $param){
    return view('welcome');
});