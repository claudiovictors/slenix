<?php

/*
|--------------------------------------------------------------------------
| HasOne Class
|--------------------------------------------------------------------------
|
| Represents the "has one" (1:1) relationship between two models.
| Example: A User has one Profile. The FK (user_id) lives in the Profile table.
| Supports lazy loading and eager loading via match().
|
*/

declare(strict_types=1);

namespace Slenix\Database\Relations;

class HasOne extends Relation
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
     * Associates eager load results to each parent model (1:1)
     *
     * @param array  $models   Array of parent models
     * @param array  $results  Database results (array of related models)
     * @param string $relation Relation name
     * @return array Models with the relation set
     */
    public function match(array $models, array $results, string $relation): array
    {
        // Build dictionary: foreignKey => related model
        $dictionary = [];
        foreach ($results as $result) {
            $key = $result->{$this->foreignKey};
            if ($key !== null) {
                $dictionary[$key] = $result;
            }
        }

        // Assign the related model (or null) to each parent model
        foreach ($models as $model) {
            $localValue = $model->{$this->localKey} ?? $model->getKey();
            $model->setRelation($relation, $dictionary[$localValue] ?? null);
        }

        return $models;
    }

    /**
     * Executes the query and returns a single related model (lazy loading)
     */
    public function getResults(array $columns = ['*']): mixed
    {
        $parentKeyValue = $this->parent->{$this->localKey};

        if (empty($parentKeyValue)) {
            return null;
        }

        return $this->query->select($columns)->first();
    }
}