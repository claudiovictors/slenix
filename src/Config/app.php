<?php

/*
|--------------------------------------------------------------------------
| Application Configuration File
|--------------------------------------------------------------------------
|
| Central configuration for the Slenix framework.
| All sensitive values are loaded from the .env file via env().
|
| Supported DB drivers: mysql | pgsql | sqlite
|
| SQLite usage:
|   DB_CONNECTION=sqlite
|   DB_HOST=/absolute/path/to/database.sqlite
|       or DB_HOST=:memory: for an in-memory database (tests only)
|
*/

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    */
    'name' => env('APP_NAME', 'Slenix'),

    /*
    |--------------------------------------------------------------------------
    | Debug Mode
    |--------------------------------------------------------------------------
    */
    'debug' => env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    */
    'base_url' => env('APP_BASE_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Timezone
    |--------------------------------------------------------------------------
    */
    'timezone' => env('APP_TIMEZONE', 'UTC'),

    /*
    |--------------------------------------------------------------------------
    | Locale
    |--------------------------------------------------------------------------
    */
    'locale' => env('APP_LOCALE', 'en'),

    /*
    |--------------------------------------------------------------------------
    | Database Connection
    |--------------------------------------------------------------------------
    |
    | drive      : mysql | pgsql | sqlite
    | hostname   : server host for MySQL/PgSQL; file path for SQLite
    | port       : server port (MySQL default 3306, PgSQL default 5432; ignored for SQLite)
    | dbname     : database name (MySQL/PgSQL; ignored for SQLite)
    | username   : database username (MySQL/PgSQL; ignored for SQLite)
    | password   : database password (MySQL/PgSQL; ignored for SQLite)
    | charset    : connection charset — MySQL only (default utf8mb4)
    | collation  : table collation   — MySQL only (default utf8mb4_unicode_ci)
    |
    */
    'db_connect' => [
        'drive'     => env('DB_CONNECTION', 'mysql'),
        'hostname'  => env('DB_HOST', '127.0.0.1'),
        'port'      => env('DB_PORT', 3306),
        'dbname'    => env('DB_DATABASE', ''),
        'username'  => env('DB_USERNAME', ''),
        'password'  => env('DB_PASSWORD', ''),
        'charset'   => env('DB_CHARSET',    'utf8mb4'),
        'collation' => env('DB_COLLATION',  'utf8mb4_unicode_ci'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    */
    'cache' => [
        'driver' => env('CACHE_DRIVER', 'file'),
        'ttl'    => (int) env('CACHE_TTL', 3600),
        'prefix' => env('CACHE_PREFIX', 'slenix_'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    */
    'logging' => [
        'channel' => env('LOG_CHANNEL', 'file'),
        'level'   => env('LOG_LEVEL', 'debug'),
        'days'    => (int) env('LOG_DAYS', 14),
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    */
    'storage' => [
        'default'    => env('STORAGE_DISK', 'public'),
        'public_url' => env('APP_BASE_URL', 'http://localhost') . '/storage',
    ],

    /*
    |--------------------------------------------------------------------------
    | Uploads
    |--------------------------------------------------------------------------
    */
    'uploads' => [
        'max_size'           => (int) env('UPLOAD_MAX_SIZE', 10240),
        'allowed_mimes'      => [],
        'allowed_extensions' => [],
        'disk'               => env('UPLOAD_DISK', 'public'),
        'path'               => env('UPLOAD_PATH', 'uploads'),
    ],

];