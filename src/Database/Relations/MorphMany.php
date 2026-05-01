<?php

/*
|--------------------------------------------------------------------------
| MorphMany Relation
|--------------------------------------------------------------------------
|
| This class provides support for polymorphic "One-to-Many" relationships.
| It allows a model to own multiple related models through a shared 
| polymorphic interface (type and ID columns) on the related table.
|
*/

declare(strict_types=1);
 
namespace Slenix\Database\Relations;
 
use Slenix\Database\Collection;
use Slenix\Database\Model;
 
class MorphMany extends Relation
{
    /** @var string Column storing the parent class name. */
    protected string $morphType;
 
    /** @var string Column storing the parent PK. */
    protected string $morphId;
 
    /**
     * @param Model  $related   Related model (e.g. Comment).
     * @param Model  $parent    Owning model (e.g. Post, Video).
     * @param string $morphType Column name for the type discriminator.
     * @param string $morphId   Column name for the FK.
     * @param string $localKey  PK of the parent model.
     */
    public function __construct(
        Model $related,
        Model $parent,
        string $morphType,
        string $morphId,
        string $localKey
    ) {
        $this->morphType = $morphType;
        $this->morphId   = $morphId;
 
        parent::__construct($related, $parent, $morphId, $localKey);
    }
 
    /**
     * Constrains the query to the parent's type and ID.
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
     * Lazy-loads and returns a Collection of related models.
     *
     * @param string[] $columns
     * @return Collection<int, Model>
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
     * Matches eager-loaded results to parent models.
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
                    $dictionary[$key][] = $result;
                }
            }
        }
 
        foreach ($models as $model) {
            $key = $model->{$this->localKey} ?? $model->getKey();
            $model->setRelation($relation, new Collection($dictionary[$key] ?? []));
        }
 
        return $models;
    }
 
    /**
     * Creates a new morph-related model and persists it.
     *
     * @example $post->comments()->create(['body' => 'Great post!'])
     *
     * @param array<string, mixed> $data
     * @return Model
     */
    public function create(array $data): Model
    {
        $data[$this->morphId]   = $this->parent->{$this->localKey} ?? $this->parent->getKey();
        $data[$this->morphType] = get_class($this->parent);
 
        $relatedClass = get_class($this->related);
        return $relatedClass::create($data);
    }
}
