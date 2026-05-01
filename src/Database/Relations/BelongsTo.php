<?php

/*
|--------------------------------------------------------------------------
| BelongsTo Class
|--------------------------------------------------------------------------
|
| Represents the inverse "belongs to" (N:1) relationship between two models.
| Example: A Post belongs to a User. The FK (user_id) lives in the posts table.
| The foreignKey is the column on the current model; localKey is the PK of the parent model.
| Supports lazy loading and eager loading via match().
|
*/

declare(strict_types=1);

namespace Slenix\Database\Relations;

class BelongsTo extends Relation
{
    /**
     * Applies the default constraint: WHERE owner_key = foreign_key_value_of_parent
     *
     * foreignKey: FK column on the current model (e.g. user_id in posts)
     * localKey:   PK column on the related model (e.g. id in users)
     */
    public function addConstraints(): void
    {
        $foreignKeyValue = $this->parent->{$this->foreignKey};

        if (!empty($foreignKeyValue)) {
            $this->query->where($this->localKey, '=', $foreignKeyValue);
        }
    }

    /**
     * Associates eager load results to each model (BelongsTo is 1:1)
     *
     * @param array  $models   Array of models that own the FK
     * @param array  $results  Loaded related models
     * @param string $relation Relation name
     * @return array Models with the relation set
     */
    public function match(array $models, array $results, string $relation): array
    {
        // Dictionary: localKey (PK of related) => related model
        $dictionary = [];
        foreach ($results as $result) {
            $key = $result->{$this->localKey};
            if ($key !== null) {
                $dictionary[$key] = $result;
            }
        }

        // For each model, find the related model by its local FK
        foreach ($models as $model) {
            $foreignValue = $model->{$this->foreignKey};
            $model->setRelation($relation, $dictionary[$foreignValue] ?? null);
        }

        return $models;
    }

    /**
     * Executes the query and returns the related model (lazy loading)
     */
    public function getResults(array $columns = ['*']): mixed
    {
        $foreignKeyValue = $this->parent->{$this->foreignKey};

        if (empty($foreignKeyValue)) {
            return null;
        }

        return $this->query->select($columns)->first();
    }

    /**
     * Associates the parent model to the related model (sets the FK)
     *
     * @example $post->author()->associate($user)
     */
    public function associate(mixed $model): mixed
    {
        $id = is_object($model) ? $model->getKey() : $model;
        $this->parent->{$this->foreignKey} = $id;
        return $this->parent;
    }

    /**
     * Removes the association (nullifies the FK)
     *
     * @example $post->author()->dissociate()
     */
    public function dissociate(): mixed
    {
        $this->parent->{$this->foreignKey} = null;
        return $this->parent;
    }
}