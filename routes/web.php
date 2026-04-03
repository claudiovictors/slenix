<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Aqui é onde você pode registrar as rotas web para sua aplicação.
| Essas rotas são carregadas pelo RouteServiceProvider dentro de um grupo
| que contém o grupo de middleware "web". Aproveite a elegância!
|
*/

declare(strict_types=1);

use Slenix\Http\Routing\Router;

Router::get('/', function(){
      return view('welcome');
});