<?php

return [
    'APP_DEBUG' => env('APP_DEBUG'),
    'mysql' => [
        'drive' => env('DB_CONNECTION'),
        'hostname' =>  env('DB_HOSTNAME'),
        'port' => env('DB_PORT'),
        'dbname' =>  env('DB_NAME'),
        'username' => env('DB_USERNAME'),
        'password' =>  env('DB_PASSWORD'),
        'charset' => env('DB_CHARSET'),
    ]
];