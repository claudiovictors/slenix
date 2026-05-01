<?php

/*
|--------------------------------------------------------------------------
| Relation Class (Base)
|--------------------------------------------------------------------------
|
| Abstract base class for all ORM relationship types.
| Defines the interface and shared behaviour across HasOne, HasMany,
| BelongsTo, and BelongsToMany. Initialises the query and exposes
| the getters required by the QueryBuilder eager loading mechanism.
|
*/

declare(strict_types=1);

namespace Slenix\Database\Relations;

use Slenix\Database\Model;
use Slenix\Database\QueryBuilder;

abstract class Relation
{
    /** @var Model Related model (child/target) */
    protected Model $related;

    /** @var Model Parent model (relation owner) */
    protected Model $parent;

    /**
     * For HasOne/HasMany: FK column on the related model
     * For BelongsTo:      FK column on the parent model
     */
    protected string $foreignKey;

    /**
     * For HasOne/HasMany: PK column on the parent model (usually 'id')
     * For BelongsTo:      PK column on the related model (usually 'id')
     */
    protected string $localKey;

    /** @var QueryBuilder Base query for the relation */
    protected QueryBuilder $query;

    public function __construct(Model $related, Model $parent, string $foreignKey, string $localKey)
    {
        $this->related    = $related;
        $this->parent     = $parent;
        $this->foreignKey = $foreignKey;
        $this->localKey   = $localKey;

        // Create the base query for the related model
        $this->query = $related::newQuery();

        // Apply the relation's default constraints
        $this->addConstraints();
    }

    /**
     * Applies the default constraints (WHERE) for the relation
     */
    abstract public function addConstraints(): void;

    /**
     * Associates eager load results to the parent models
     *
     * @param array  $models   Parent models
     * @param array  $results  Results from the relation query
     * @param string $relation Relation name (used by setRelation)
     * @return array Models with the relation set
     */
    abstract public function match(array $models, array $results, string $relation): array;

    /**
     * Executes the query and returns the results (lazy loading)
     */
    abstract public function getResults(array $columns = ['*']): mixed;

    /** Returns the related model */
    public function getRelated(): Model
    {
        return $this->related;
    }

    /** Returns the parent model */
    public function getParent(): Model
    {
        return $this->parent;
    }

    /**
     * Returns the foreign key.
     * HasOne/HasMany: FK is on the related model.
     * BelongsTo:      FK is on the parent model.
     */
    public function getForeignKey(): string
    {
        return $this->foreignKey;
    }

    /**
     * Returns the local key.
     * HasOne/HasMany: PK of the parent.
     * BelongsTo:      PK of the related model (owner key).
     */
    public function getLocalKey(): string
    {
        return $this->localKey;
    }

    /** Returns the relation's QueryBuilder instance */
    public function getQuery(): QueryBuilder
    {
        return $this->query;
    }

    /**
     * Forwards method calls to the relation's QueryBuilder
     *
     * @example $user->posts()->where('active', 1)->get()
     */
    public function __call(string $method, array $parameters): mixed
    {
        $result = $this->query->$method(...$parameters);

        // If the QueryBuilder returns itself, return $this for chaining
        if ($result instanceof QueryBuilder) {
            return $this;
        }

        return $result;
    }
}