<?php

/*
|--------------------------------------------------------------------------
| MorphOne Relation
|--------------------------------------------------------------------------
|
| This class provides support for polymorphic "One-to-One" relationships.
| It allows a single model to belong to more than one type of model on
| a single association, identifying the parent via type and ID columns.
|
*/

declare(strict_types=1);
 
namespace Slenix\Database\Relations;

use Slenix\Database\Model;

class MorphOne extends Relation
{
    /** @var string Column storing the parent class name (e.g. 'App\Models\User'). */
    protected string $morphType;
 
    /** @var string Column storing the parent PK value. */
    protected string $morphId;
 
    /**
     * @param Model  $related   Related model (e.g. Image).
     * @param Model  $parent    Owning model (e.g. User).
     * @param string $morphType Column name for the type discriminator.
     * @param string $morphId   Column name for the FK.
     * @param string $localKey  PK of the parent model.
     */
    public function __construct(
        Model  $related,
        Model  $parent,
        string $morphType,
        string $morphId,
        string $localKey
    ) {
        $this->morphType = $morphType;
        $this->morphId   = $morphId;
 
        parent::__construct($related, $parent, $morphId, $localKey);
    }
 
    /**
     * Applies the polymorphic constraints to the query.
     * 
     * WHERE morph_id = parent.pk AND morph_type = 'ClassName'
     */
    public function addConstraints(): void
    {
        $parentId    = $this->parent->{$this->localKey} ?? $this->parent->getKey();
        $parentClass = get_class($this->parent);
 
        if (!empty($parentId)) {
            $this->query
                ->where($this->morphId,   '=', $parentId)
                ->where($this->morphType, '=', $parentClass);
        }
    }
 
    /**
     * Lazy-loads and returns the single related model or null.
     *
     * @param string[] $columns
     * @return Model|null
     */
    public function getResults(array $columns = ['*']): ?Model
    {
        if (empty($this->parent->getKey())) {
            return null;
        }
 
        return $this->query->select($columns)->first();
    }
 
    /**
     * Matches eager-loaded morph results to parent models.
     *
     * @param Model[] $models
     * @param Model[] $results
     * @param string  $relation
     * @return Model[]
     */
    public function match(array $models, array $results, string $relation): array
    {
        $parentClass = get_class($this->parent);
 
        $dictionary = [];
        foreach ($results as $result) {
            if (($result->{$this->morphType} ?? null) === $parentClass) {
                $key = $result->{$this->morphId} ?? null;
                if ($key !== null) {
                    $dictionary[$key] = $result;
                }
            }
        }
 
        foreach ($models as $model) {
            $key = $model->{$this->localKey} ?? $model->getKey();
            $model->setRelation($relation, $dictionary[$key] ?? null);
        }
 
        return $models;
    }
}
