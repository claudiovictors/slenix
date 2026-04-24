<?php

/*
|--------------------------------------------------------------------------
| Factory Class (Base)
|--------------------------------------------------------------------------
|
| Abstract base class for model factories (fake data generation).
| Allows mass-creating records for tests and development seeds.
| Integrates with Faker (if available) or uses internal generation.
|
*/

declare(strict_types=1);

namespace Slenix\Database\Seeds;

abstract class Factory
{
    /** @var string \Model class this factory generates */
    protected string $model = '';

    /** @var int Default number of records to create */
    protected int $count = 1;

    /** @var array Attribute overrides */
    protected array $states = [];

    /**
     * Defines the default attribute structure.
     * Must return an array with fields and values (may use Fake::*).
     *
     * @example return ['name' => Fake::name(), 'email' => Fake::email()]
     */
    abstract public function definition(): array;

    /**
     * Sets the number of records to create.
     *
     * @example UserFactory::new()->count(10)->create()
     */
    public function count(int $count): static
    {
        $clone        = clone $this;
        $clone->count = $count;
        return $clone;
    }

    /**
     * Applies attribute overrides.
     *
     * @example UserFactory::new()->state(['role' => 'admin'])->create()
     */
    public function state(array $attributes): static
    {
        $clone         = clone $this;
        $clone->states = array_merge($clone->states, $attributes);
        return $clone;
    }

    /**
     * Instantiates the factory.
     *
     * @example UserFactory::new()
     */
    public static function new(): static
    {
        return new static();
    }

    /**
     * Creates and persists models in the database.
     *
     * @example UserFactory::new()->count(5)->create()
     * @example UserFactory::new()->create(['role' => 'admin'])
     *
     * @return object|array Single model or array of models
     */
    public function create(array $overrides = []): mixed
    {
        $models = [];

        for ($i = 0; $i < $this->count; $i++) {
            $attributes = array_merge(
                $this->definition(),
                $this->states,
                $overrides
            );

            if (empty($this->model) || !class_exists($this->model)) {
                throw new \RuntimeException(
                    "Property \$model is not defined or invalid in " . static::class
                );
            }

            /** @var \Slenix\Database\Model $model */
            $model = $this->model::create($attributes);
            $models[] = $model;
        }

        return $this->count === 1 ? $models[0] : $models;
    }

    /**
     * Creates model instances WITHOUT persisting to the database.
     *
     * @example UserFactory::new()->make()
     */
    public function make(array $overrides = []): mixed
    {
        $models = [];

        for ($i = 0; $i < $this->count; $i++) {
            $attributes = array_merge(
                $this->definition(),
                $this->states,
                $overrides
            );

            $modelClass = $this->model;
            $model      = new $modelClass();
            $model->fill($attributes);
            $models[]   = $model;
        }

        return $this->count === 1 ? $models[0] : $models;
    }

    /**
     * Returns only the attribute array (without creating a model).
     *
     * @example UserFactory::new()->raw()
     */
    public function raw(array $overrides = []): array
    {
        return array_merge($this->definition(), $this->states, $overrides);
    }
}