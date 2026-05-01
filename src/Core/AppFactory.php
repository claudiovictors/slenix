<?php

/*
|--------------------------------------------------------------------------
| AppFactory Class — Slenix Framework
|--------------------------------------------------------------------------
|
| The primary application factory and service container. It centralizes the 
| creation of the Kernel, registers shared core services (Request, Response), 
| and manages the HTTP lifecycle execution.
|
*/

declare(strict_types=1);

namespace Slenix\Core;

use Slenix\Http\Request;
use Slenix\Http\Response;

class AppFactory
{
    /** @var array<string, mixed> Registered services in the simple container. */
    private static array $bindings = [];

    /** @var self|null Singleton instance of the factory. */
    private static ?self $instance = null;

    /**
     * AppFactory constructor (Private to enforce singleton/static usage).
     */
    private function __construct() {}

    /**
     * Creates and runs the application.
     * * @param float $startTime Start timestamp in microtime(true).
     * @return void
     */
    public static function create(float $startTime): void
    {
        static::bootstrap();
        (new Kernel($startTime))->run();
    }

    /**
     * Registers fundamental services into the container before starting the Kernel.
     * * @return void
     */
    private static function bootstrap(): void
    {
        // Register Request as a singleton (created from globals)
        static::singleton('request', static fn () => Request::createFromGlobals());

        // Register Response as a singleton
        static::singleton('response', static fn () => new Response());
    }

    /**
     * Registers a service as a singleton (created once and shared).
     * * @param string   $abstract Service name/key.
     * @param callable $factory  Callable to create the instance.
     * @return void
     */
    public static function singleton(string $abstract, callable $factory): void
    {
        static::$bindings[$abstract] = [
            'factory'   => $factory,
            'singleton' => true,
            'resolved'  => null,
        ];
    }

    /**
     * Registers a simple binding (new instance per resolution).
     * * @param string   $abstract Service name/key.
     * @param callable $factory  Callable to create the instance.
     * @return void
     */
    public static function bind(string $abstract, callable $factory): void
    {
        static::$bindings[$abstract] = [
            'factory'   => $factory,
            'singleton' => false,
            'resolved'  => null,
        ];
    }

    /**
     * Directly registers an already created instance.
     * * @param string $abstract Service name/key.
     * @param mixed  $instance The object instance.
     * @return void
     */
    public static function instance(string $abstract, mixed $instance): void
    {
        static::$bindings[$abstract] = [
            'factory'   => null,
            'singleton' => true,
            'resolved'  => $instance,
        ];
    }

    /**
     * Resolves and returns a registered service.
     * * @param string $abstract Service name/key.
     * @return mixed
     * @throws \InvalidArgumentException If the service is not registered.
     */
    public static function make(string $abstract): mixed
    {
        if (!isset(static::$bindings[$abstract])) {
            throw new \InvalidArgumentException(
                "Service '{$abstract}' is not registered in the container."
            );
        }

        $binding = &static::$bindings[$abstract];

        // Return resolved singleton if available
        if ($binding['singleton'] && $binding['resolved'] !== null) {
            return $binding['resolved'];
        }

        // Resolve via factory callable
        if ($binding['factory'] !== null) {
            $resolved = ($binding['factory'])();

            if ($binding['singleton']) {
                $binding['resolved'] = $resolved;
            }

            return $resolved;
        }

        return $binding['resolved'];
    }

    /**
     * Checks if a service is registered in the container.
     * * @param string $abstract
     * @return bool
     */
    public static function has(string $abstract): bool
    {
        return isset(static::$bindings[$abstract]);
    }

    /**
     * Removes a service from the container (primarily for testing).
     * * @param string $abstract
     * @return void
     */
    public static function forget(string $abstract): void
    {
        unset(static::$bindings[$abstract]);
    }

    /**
     * Clears all registered services.
     * * @return void
     */
    public static function flush(): void
    {
        static::$bindings = [];
    }

    /**
     * Returns an array of all registered service keys.
     * * @return array<string>
     */
    public static function registered(): array
    {
        return array_keys(static::$bindings);
    }

    /**
     * Helper to get the current Request instance.
     * * @return Request
     */
    public static function request(): Request
    {
        return static::make('request');
    }

    /**
     * Helper to get the current Response instance.
     * * @return Response
     */
    public static function response(): Response
    {
        return static::make('response');
    }
}