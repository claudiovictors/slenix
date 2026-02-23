<?php

use Slenix\Http\Request;
use Slenix\Http\Response;
use Slenix\Http\Routing\Router;

Router::get("/", function (Request $req, Response $res) {
      return view("welcome");
});