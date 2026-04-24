<?php

/*
|--------------------------------------------------------------------------
| HasManyThrough Relation
|--------------------------------------------------------------------------
|
| This class provides support for "Has Many Through" relationships, 
| allowing access to distant models via an intermediate model. 
| It handles the complex JOIN logic between the parent, intermediate 
| (through), and related tables, including support for Eager Loading.
|
*/

declare(strict_types=1);

namespace Slenix\Database\Relations;

use Slenix\Database\Collection;
use Slenix\Database\Model;

class HasManyThrough extends Relation
{
    /** @var Model The intermediate (through) model instance. */
    protected Model $through;

    /** @var string FK on the through model that points to the parent. */
    protected string $firstKey;

    /** @var string FK on the related model that points to the through model. */
    protected string $secondKey;

    /** @var string PK of the through model. */
    protected string $secondLocalKey;

    /**
     * @param Model  $related        Target (distant) model.
     * @param Model  $parent         Parent model owning the relation.
     * @param Model  $through        Intermediate model.
     * @param string $firstKey       FK on through → parent.
     * @param string $secondKey      FK on related → through.
     * @param string $localKey       PK of parent model.
     * @param string $secondLocalKey PK of through model.
     */
    public function __construct(
        Model  $related,
        Model  $parent,
        Model  $through,
        string $firstKey,
        string $secondKey,
        string $localKey,
        string $secondLocalKey
    ) {
        $this->through        = $through;
        $this->firstKey       = $firstKey;
        $this->secondKey      = $secondKey;
        $this->secondLocalKey = $secondLocalKey;

        parent::__construct($related, $parent, $firstKey, $localKey);
    }

    /**
     * Applies the JOIN constraints for the through table.
     */
    public function addConstraints(): void
    {
        $relatedTable = $this->related->getTable();
        $throughTable = $this->through->getTable();

        $this->query->join(
            $throughTable,
            "{$relatedTable}.{$this->secondKey}",
            '=',
            "{$throughTable}.{$this->secondLocalKey}"
        );

        $parentId = $this->parent->{$this->localKey} ?? $this->parent->getKey();

        if (!empty($parentId)) {
            $this->query->where("{$throughTable}.{$this->firstKey}", '=', $parentId);
        }
    }

    /**
     * Executes the query and returns a Collection of distant related models.
     *
     * @param string[] $columns
     * @return Collection
     */
    public function getResults(array $columns = ['*']): Collection
    {
        if (empty($this->parent->getKey())) {
            return new Collection();
        }

        return $this->query->select($columns)->get();
    }

    /**
     * Alias for getResults().
     */
    public function get(): Collection
    {
        return $this->getResults();
    }

    /**
     * Matches eager-loaded results to their parent models.
     */
    public function match(array $models, array $results, string $relation): array
    {
        $firstKey = $this->firstKey;

        $dictionary = [];
        foreach ($results as $result) {
            $key = $result->{$firstKey} ?? $result->_through_fk ?? null;
            if ($key !== null) {
                $dictionary[$key][] = $result;
            }
        }

        foreach ($models as $model) {
            $key = $model->{$this->localKey} ?? $model->getKey();
            $model->setRelation($relation, new Collection($dictionary[$key] ?? []));
        }

        return $models;
    }

    /**
     * Builds a SELECT that includes the through FK so match() can group results.
     */
    public function getResultsForEager(): Collection
    {
        $relatedTable = $this->related->getTable();
        $throughTable = $this->through->getTable();

        return $this->query
            ->addSelect([
                "{$relatedTable}.*",
                "{$throughTable}.{$this->firstKey} as _through_fk",
            ])
            ->get();
    }
}
