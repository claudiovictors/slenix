<?php
/*
|--------------------------------------------------------------------------
| Classe Router
|--------------------------------------------------------------------------
|
| Classe auxiliar para representar uma rota e permitir encadeamento de métodos.
|
*/
declare(strict_types=1);

namespace Slenix\Http\Message;

class Route {
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
}