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
        Router::setRouteDomain($this->routeIndex, $domain);
        return $this;
    }

    /**
     * Makes the route match only HTTPS requests.
     *
     * @return self
     */
    public function secure(): self
    {
        Router::setRouteSecure($this->routeIndex, true);
        return $this;
    }

    /**
     * Sets default values for parameters. Used when an optional parameter is
     * missing from the URL and when generating URLs with Router::route().
     *
     *   Router::get('/blog/{page?}', ...)->defaults(['page' => 1]);
     *
     * @param  array<string, string|int|float> $defaults
     * @return self
     */
    public function defaults(array $defaults): self
    {
        Router::setRouteDefaults($this->routeIndex, $defaults);
        return $this;
    }

    /**
     * Adds regex constraints to parameters. Do not include delimiters or
     * anchors, and prefer non-capturing groups: '(?:a|b)'.
     *
     * @param  array<string, string> $where [parameter => regex].
     * @return self
     */
    public function where(array $where): self
    {
        Router::setRouteConstraints($this->routeIndex, $where);
        return $this;
    }

    /**
     * Constrains one parameter with a regex.
     *
     * @param  string $parameter
     * @param  string $pattern
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
     * Lowercase URL slug: 'my-first-post'.
     *
     * @param  string|array $parameter One name or a list of names.
     * @return self
     */
    public function whereSlug(string|array $parameter): self
    {
        return $this->whereMany($parameter, '[a-z0-9]+(?:-[a-z0-9]+)*');
    }

    /**
     * UUID in the canonical 8-4-4-4-12 format.
     *
     * @param  string|array $parameter One name or a list of names.
     * @return self
     */
    public function whereUuid(string|array $parameter): self
    {
        return $this->whereMany(
            $parameter,
            '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}'
        );
    }

    /**
     * Restricts a parameter to a fixed set of values.
     *
     *   ->whereIn('status', ['draft', 'published'])
     *
     * @param  string            $parameter
     * @param  array<int,string> $values
     * @return self
     */
    public function whereIn(string $parameter, array $values): self
    {
        $escaped = array_map(static fn($v): string => preg_quote((string) $v, '@'), $values);

        return $this->whereParameter($parameter, '(?:' . implode('|', $escaped) . ')');
    }

    /**
     * Returns the internal route index.
     * * @return int
     */
    public function getRouteIndex(): int
    {
        return $this->routeIndex;
    }

    /** @return string|null Route name, or null when unnamed. */
    public function getName(): ?string
    {
        return $this->toArray()['name'] ?? null;
    }

    /** @return string HTTP method in uppercase. */
    public function getMethod(): string
    {
        return $this->toArray()['method'] ?? '';
    }

    /** @return string URI pattern, including group prefixes. */
    public function getUri(): string
    {
        return $this->toArray()['pathUri'] ?? '';
    }

    /** @return array<int,string> Middlewares applied to the route. */
    public function getMiddleware(): array
    {
        return $this->toArray()['middleware'] ?? [];
    }

    /** @return array<string,string> Parameter constraints. */
    public function getConstraints(): array
    {
        return $this->toArray()['where'] ?? [];
    }

    /**
     * Returns the full stored definition of the route.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return Router::getRoute($this->routeIndex) ?? [];
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    /**
     * Applies the same regex to one or several parameters.
     *
     * @param  string|array $parameters
     * @param  string       $pattern
     * @return self
     */
    private function whereMany(string|array $parameters, string $pattern): self
    {
        $map = [];
        foreach ((array) $parameters as $name) {
            $map[$name] = $pattern;
        }

        return $this->where($map);
    }
}