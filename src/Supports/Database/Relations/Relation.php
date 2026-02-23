<?php

/*
|--------------------------------------------------------------------------
| Classe Relation (Base)
|--------------------------------------------------------------------------
|
| Classe abstrata base para todos os tipos de relacionamento do ORM.
| Define a interface e comportamento comum entre HasOne, HasMany,
| BelongsTo e BelongsToMany. Inicializa a query e expõe getters
| necessários para o eager loading do QueryBuilder.
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Database\Relations;

use Slenix\Supports\Database\QueryBuilder;
use Slenix\Supports\Database\Model;

abstract class Relation
{
    /** @var Model Modelo relacionado (filho/alvo) */
    protected Model $related;

    /** @var Model Modelo pai (dono da relação) */
    protected Model $parent;

    /**
     * Para HasOne/HasMany: coluna FK no modelo relacionado
     * Para BelongsTo: coluna FK no modelo pai
     */
    protected string $foreignKey;

    /**
     * Para HasOne/HasMany: coluna PK no modelo pai (geralmente 'id')
     * Para BelongsTo: coluna PK no modelo relacionado (geralmente 'id')
     */
    protected string $localKey;

    /** @var QueryBuilder Query base da relação */
    protected QueryBuilder $query;

    public function __construct(Model $related, Model $parent, string $foreignKey, string $localKey)
    {
        $this->related    = $related;
        $this->parent     = $parent;
        $this->foreignKey = $foreignKey;
        $this->localKey   = $localKey;

        // Cria a query base para o modelo relacionado
        $this->query = $related::newQuery();

        // Aplica as constraints padrão da relação
        $this->addConstraints();
    }

    /**
     * Aplica as constraints (WHERE) padrão para a relação
     */
    abstract public function addConstraints(): void;

    /**
     * Associa os resultados do eager load aos modelos pais
     *
     * @param array  $models   Modelos pais
     * @param array  $results  Resultados da query da relação
     * @param string $relation Nome da relação (para setRelation)
     * @return array Modelos com a relação definida
     */
    abstract public function match(array $models, array $results, string $relation): array;

    /**
     * Executa a query e retorna os resultados (lazy loading)
     */
    abstract public function getResults(array $columns = ['*']): mixed;

    // =========================================================
    // GETTERS — usados pelo QueryBuilder::loadEagerRelations
    // =========================================================

    /** Retorna o modelo relacionado */
    public function getRelated(): Model
    {
        return $this->related;
    }

    /** Retorna o modelo pai */
    public function getParent(): Model
    {
        return $this->parent;
    }

    /**
     * Retorna a chave estrangeira.
     * HasOne/HasMany: FK está no modelo relacionado.
     * BelongsTo: FK está no modelo pai.
     */
    public function getForeignKey(): string
    {
        return $this->foreignKey;
    }

    /**
     * Retorna a chave local.
     * HasOne/HasMany: PK do pai.
     * BelongsTo: PK do relacionado (owner key).
     */
    public function getLocalKey(): string
    {
        return $this->localKey;
    }

    /** Retorna a instância do QueryBuilder da relação */
    public function getQuery(): QueryBuilder
    {
        return $this->query;
    }

    // =========================================================
    // QUERY FORWARDING (encadeamento)
    // =========================================================

    /**
     * Encaminha chamadas de método para o QueryBuilder da relação
     *
     * @example $user->posts()->where('active', 1)->get()
     */
    public function __call(string $method, array $parameters): mixed
    {
        $result = $this->query->$method(...$parameters);

        // Se o QueryBuilder retornar a si mesmo, retorna $this para encadeamento
        if ($result instanceof QueryBuilder) {
            return $this;
        }

        return $result;
    }
}