<?php

/*
|--------------------------------------------------------------------------
| Application Bootstrapper
|--------------------------------------------------------------------------
|
| This file initializes the Slenix framework. It loads the Composer 
| autoloader and uses the AppFactory to create a new application 
| instance, starting the request/response lifecycle.
|
*/

declare(strict_types=1);

use Slenix\Core\AppFactory;

/**
 * Load the Composer Autoloader.
 * Enables automatic class loading for the framework and dependencies.
 */
require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Create the Application Instance.
 * Uses the AppFactory to bootstrap the core components using the 
 * SLENIX_START constant as the initial execution timestamp.
 * * @var \Slenix\Core\Application $app
 */
$app = AppFactory::create(SLENIX_START);