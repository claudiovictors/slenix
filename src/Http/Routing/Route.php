<?php

/*
|--------------------------------------------------------------------------
| Route Class — Slenix Framework
|--------------------------------------------------------------------------
|
| A helper class representing a registered route. It enables method 
| chaining for fluent configuration of route names, middlewares, 
| and pattern constraints.
|
*/

declare(strict_types=1);

namespace Slenix\Http\Routing;

class Route 
{
    /** @var int The index of the route in the Router's storage. */
    private int $routeIndex;

    /**
     * Route constructor.
     * * @param int $routeIndex The index of the route in the main collection.
     */
    public function __construct(int $routeIndex)
    {
        $this->routeIndex = $routeIndex;
    }

    /**
     * Sets a unique name for the route.
     * * @param string $name The route name.
     * @return self
     */
    public function name(string $name): self
    {
        Router::setRouteName($this->routeIndex, $name);
        return $this;
    }

    /**
     * Assigns one or more middlewares to the route.
     * * @param array|string $middleware Single middleware class name or array of names.
     * @return self
     */
    public function middleware(array|string $middleware): self
    {
        Router::setRouteMiddleware($this->routeIndex, $middleware);
        return $this;
    }

    /**
     * Alias for the middleware() method.
     * * @param array|string $middleware
     * @return self
     */
    public function middlewares(array|string $middleware): self
    {
        return $this->middleware($middleware);
    }

    /**
     * Restricts the route to a specific domain (Future Implementation).
     * * @param string $domain The permitted domain.
     * @return self
     */
    public function domain(string $domain): self
    {
        // Placeholder for future multi-domain routing logic
        return $this;
    }

    /**
     * Sets default values for route parameters (Future Implementation).
     * * @param array $defaults Associative array of default values.
     * @return self
     */
    public function defaults(array $defaults): self
    {
        return $this;
    }

    /**
     * Adds regex constraints to route parameters (Future Implementation).
     * * @param array $where Associative array [parameter => regex_pattern].
     * @return self
     */
    public function where(array $where): self
    {
        return $this;
    }

    /**
     * Constrains a specific parameter with a regex pattern.
     * * @param string $parameter The parameter name.
     * @param string $pattern   The regex pattern.
     * @return self
     */
    public function whereParameter(string $parameter, string $pattern): self
    {
        return $this->where([$parameter => $pattern]);
    }

    /**
     * Constrains a parameter to be numeric only [0-9]+.
     * * @param string $parameter
     * @return self
     */
    public function whereNumber(string $parameter): self
    {
        return $this->whereParameter($parameter, '[0-9]+');
    }

    /**
     * Constrains a parameter to be alphabetic only [a-zA-Z]+.
     * * @param string $parameter
     * @return self
     */
    public function whereAlpha(string $parameter): self
    {
        return $this->whereParameter($parameter, '[a-zA-Z]+');
    }

    /**
     * Constrains a parameter to be alphanumeric [a-zA-Z0-9]+.
     * * @param string $parameter
     * @return self
     */
    public function whereAlphaNumeric(string $parameter): self
    {
        return $this->whereParameter($parameter, '[a-zA-Z0-9]+');
    }

    /**
     * Returns the internal route index.
     * * @return int
     */
    public function getRouteIndex(): int
    {
        return $this->routeIndex;
    }
}