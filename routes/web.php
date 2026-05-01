<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| This is where you can register web routes for your application.
| These routes are loaded by the RouteServiceProvider within a group
| containing the "web" middleware group. Enjoy the elegance!
|
*/

declare(strict_types=1);

use Slenix\Http\Request;
use Slenix\Http\Response;
use Slenix\Http\Routing\Router;

Router::get('/', function(Request $req, Response $res){
    return view('welcome');
});