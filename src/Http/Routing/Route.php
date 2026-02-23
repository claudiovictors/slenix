<?php

/*
|--------------------------------------------------------------------------
| Classe Route
|--------------------------------------------------------------------------
|
| Classe auxiliar para representar uma rota e permitir encadeamento de métodos.
| Suporta nomeação de rotas e aplicação de middlewares de forma fluente.
|
*/

declare(strict_types=1);

namespace Slenix\Http\Routing;

class Route 
{
    /**
     * Índice da rota no array de rotas.
     *
     * @var int
     */
    private int $routeIndex;

    /**
     * Construtor da classe Route.
     *
     * @param int $routeIndex O índice da rota no array de rotas.
     */
    public function __construct(int $routeIndex)
    {
        $this->routeIndex = $routeIndex;
    }

    /**
     * Define o nome da rota.
     *
     * @param string $name O nome da rota.
     * @return self
     */
    public function name(string $name): self
    {
        Router::setRouteName($this->routeIndex, $name);
        return $this;
    }

    /**
     * Adiciona middleware(s) à rota.
     *
     * @param array|string $middleware Middleware ou array de middlewares.
     * @return self
     */
    public function middleware(array|string $middleware): self
    {
        Router::setRouteMiddleware($this->routeIndex, $middleware);
        return $this;
    }

    /**
     * Alias para o método middleware()
     *
     * @param array|string $middleware Middleware ou array de middlewares.
     * @return self
     */
    public function middlewares(array|string $middleware): self
    {
        return $this->middleware($middleware);
    }

    /**
     * Define onde essa rota pode ser acessada (domínio).
     * 
     * @param string $domain O domínio permitido.
     * @return self
     */
    public function domain(string $domain): self
    {
        // Esta funcionalidade pode ser implementada futuramente
        // Por agora, apenas retorna $this para manter a fluência
        return $this;
    }

    /**
     * Define parâmetros padrão para a rota.
     *
     * @param array $defaults Array associativo com valores padrão.
     * @return self
     */
    public function defaults(array $defaults): self
    {
        // Esta funcionalidade pode ser implementada futuramente
        return $this;
    }

    /**
     * Adiciona restrições regex aos parâmetros da rota.
     *
     * @param array $where Array associativo com nome do parâmetro => regex.
     * @return self
     */
    public function where(array $where): self
    {
        // Esta funcionalidade pode ser implementada futuramente
        return $this;
    }

    /**
     * Adiciona uma restrição regex a um parâmetro específico.
     *
     * @param string $parameter Nome do parâmetro.
     * @param string $pattern Padrão regex.
     * @return self
     */
    public function whereParameter(string $parameter, string $pattern): self
    {
        return $this->where([$parameter => $pattern]);
    }

    /**
     * Restringe um parâmetro a apenas números.
     *
     * @param string $parameter Nome do parâmetro.
     * @return self
     */
    public function whereNumber(string $parameter): self
    {
        return $this->whereParameter($parameter, '[0-9]+');
    }

    /**
     * Restringe um parâmetro a apenas letras.
     *
     * @param string $parameter Nome do parâmetro.
     * @return self
     */
    public function whereAlpha(string $parameter): self
    {
        return $this->whereParameter($parameter, '[a-zA-Z]+');
    }

    /**
     * Restringe um parâmetro a apenas letras e números.
     *
     * @param string $parameter Nome do parâmetro.
     * @return self
     */
    public function whereAlphaNumeric(string $parameter): self
    {
        return $this->whereParameter($parameter, '[a-zA-Z0-9]+');
    }

    /**
     * Obtém o índice da rota.
     *
     * @return int
     */
    public function getRouteIndex(): int
    {
        return $this->routeIndex;
    }
}