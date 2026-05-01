<?php

/*
|--------------------------------------------------------------------------
| HasMany Class
|--------------------------------------------------------------------------
|
| Represents the "has many" (1:N) relationship between two models.
| Example: A User has many Posts. The FK (user_id) lives in the posts table.
| Supports lazy loading (returns Collection) and eager loading via match().
|
*/

declare(strict_types=1);

namespace Slenix\Database\Relations;

use Slenix\Database\Collection;

class HasMany extends Relation
{
    /**
     * Applies the default constraint: WHERE foreign_key = local_key_value
     */
    public function addConstraints(): void
    {
        $parentKeyValue = $this->parent->{$this->localKey};

        if (!empty($parentKeyValue)) {
            $this->query->where($this->foreignKey, '=', $parentKeyValue);
        }
    }

    /**
     * Associates eager load results to each parent model (1:N)
     *
     * @param array  $models   Array of parent models
     * @param array  $results  Database results (array of related models)
     * @param string $relation Relation name
     * @return array Models with the relation set (Collection per parent)
     */
    public function match(array $models, array $results, string $relation): array
    {
        // Build dictionary: localKeyValue => [related models]
        $dictionary = [];
        foreach ($results as $result) {
            $key = $result->{$this->foreignKey};
            if ($key !== null) {
                $dictionary[$key][] = $result;
            }
        }

        // Assign the Collection (or empty Collection) to each parent model
        foreach ($models as $model) {
            $localValue = $model->{$this->localKey} ?? $model->getKey();
            $related    = $dictionary[$localValue] ?? [];
            $model->setRelation($relation, new Collection($related));
        }

        return $models;
    }

    /**
     * Executes the query and returns a Collection of related models (lazy loading)
     */
    public function getResults(array $columns = ['*']): Collection
    {
        $parentKeyValue = $this->parent->{$this->localKey};

        if (empty($parentKeyValue)) {
            return new Collection();
        }

        return $this->query->select($columns)->get();
    }

    /**
     * Adds a WHERE clause to the relation query
     *
     * @example $user->posts()->where('active', 1)->get()
     */
    public function where(string $column, mixed $operator = null, mixed $value = null): static
    {
        $this->query->where($column, $operator, $value);
        return $this;
    }

    /**
     * Adds an ORDER BY clause to the relation query
     *
     * @example $user->posts()->orderBy('created_at', 'DESC')->get()
     */
    public function orderBy(string $column, string $direction = 'ASC'): static
    {
        $this->query->orderBy($column, $direction);
        return $this;
    }

    /**
     * Limits the relation results
     *
     * @example $user->posts()->limit(5)->get()
     */
    public function limit(int $limit): static
    {
        $this->query->limit($limit);
        return $this;
    }

    /**
     * Executes and returns the Collection (alias for getResults)
     */
    public function get(): Collection
    {
        return $this->getResults();
    }

    /**
     * Counts the related records
     */
    public function count(): int
    {
        return $this->query->count();
    }

    /**
     * Creates a new record associated with the parent
     *
     * @example $user->posts()->create(['title' => 'New Post'])
     */
    public function create(array $data): mixed
    {
        $data[$this->foreignKey] = $this->parent->{$this->localKey};
        $relatedClass            = get_class($this->related);
        return $relatedClass::create($data);
    }
}