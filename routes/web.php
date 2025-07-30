<?php

use Slenix\Http\Message\Router;
use Slenix\Http\Message\Request;
use Slenix\Http\Message\Response;

Router::get('/', function(Request $request, Response $response){
    return view('welcome');
});