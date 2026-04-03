<?php

/*
|--------------------------------------------------------------------------
| Classe Factory (Base)
|--------------------------------------------------------------------------
|
| Classe abstrata base para factories de modelos (geração de dados falsos).
| Permite criar registros em massa para testes e seeds de desenvolvimento.
| Integra-se com o Faker (se disponível) ou usa geração interna.
|
*/

declare(strict_types=1);

namespace Slenix\Database\Seeds;

abstract class Factory
{
    /** @var string Classe do modelo que este factory gera */
    protected string $model = '';

    /** @var int Quantidade padrão a criar */
    protected int $count = 1;

    /** @var array Overrides de atributos */
    protected array $states = [];

    /**
     * Define a estrutura de atributos padrão.
     * Deve retornar um array com os campos e valores (pode usar Fake::*).
     *
     * @example return ['name' => Fake::name(), 'email' => Fake::email()]
     */
    abstract public function definition(): array;

    // =========================================================
    // FLUENT API
    // =========================================================

    /**
     * Define a quantidade de registros a criar.
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
     * Aplica overrides de atributos.
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
     * Instancia o factory.
     *
     * @example UserFactory::new()
     */
    public static function new(): static
    {
        return new static();
    }

    // =========================================================
    // CRIAÇÃO
    // =========================================================

    /**
     * Cria e persiste modelos no banco de dados.
     *
     * @example UserFactory::new()->count(5)->create()
     * @example UserFactory::new()->create(['role' => 'admin'])
     *
     * @return object|array Modelo único ou array de modelos
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
                    "Propriedade \$model não definida ou inválida em " . static::class
                );
            }

            /** @var \Slenix\Database\Model $model */
            $model = $this->model::create($attributes);
            $models[] = $model;
        }

        return $this->count === 1 ? $models[0] : $models;
    }

    /**
     * Cria instâncias do modelo SEM persistir no banco.
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
     * Retorna apenas o array de atributos (sem criar modelo).
     *
     * @example UserFactory::new()->raw()
     */
    public function raw(array $overrides = []): array
    {
        return array_merge($this->definition(), $this->states, $overrides);
    }
}
