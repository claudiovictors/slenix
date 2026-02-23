<?php

/*
|--------------------------------------------------------------------------
| Classe HasMany
|--------------------------------------------------------------------------
|
| Representa a relação "tem muitos" (1:N) entre dois modelos.
| Exemplo: Um User tem muitos Posts. A FK (user_id) fica na tabela posts.
| Suporta lazy loading (retorna Collection) e eager loading via match().
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Database\Relations;

use Slenix\Supports\Database\Collection;

class HasMany extends Relation
{
    /**
     * Aplica a constraint padrão: WHERE foreign_key = local_key_value
     */
    public function addConstraints(): void
    {
        $parentKeyValue = $this->parent->{$this->localKey};

        if (!empty($parentKeyValue)) {
            $this->query->where($this->foreignKey, '=', $parentKeyValue);
        }
    }

    /**
     * Associa os resultados do eager load a cada modelo pai (1:N)
     *
     * @param array  $models   Array de modelos pais
     * @param array  $results  Resultados do banco (array de modelos relacionados)
     * @param string $relation Nome da relação
     * @return array Modelos com a relação definida (Collection por pai)
     */
    public function match(array $models, array $results, string $relation): array
    {
        // Monta dicionário: localKeyValue => [modelos relacionados]
        $dictionary = [];
        foreach ($results as $result) {
            $key = $result->{$this->foreignKey};
            if ($key !== null) {
                $dictionary[$key][] = $result;
            }
        }

        // Associa a Collection (ou Collection vazia) a cada modelo pai
        foreach ($models as $model) {
            $localValue = $model->{$this->localKey} ?? $model->getKey();
            $related    = $dictionary[$localValue] ?? [];
            $model->setRelation($relation, new Collection($related));
        }

        return $models;
    }

    /**
     * Executa a query e retorna uma Collection dos relacionados (lazy loading)
     */
    public function getResults(array $columns = ['*']): Collection
    {
        $parentKeyValue = $this->parent->{$this->localKey};

        if (empty($parentKeyValue)) {
            return new Collection();
        }

        return $this->query->select($columns)->get();
    }

    // =========================================================
    // HELPERS DE ENCADEAMENTO
    // =========================================================

    /**
     * Adiciona WHERE à query da relação
     *
     * @example $user->posts()->where('active', 1)->get()
     */
    public function where(string $column, mixed $operator = null, mixed $value = null): static
    {
        $this->query->where($column, $operator, $value);
        return $this;
    }

    /**
     * Adiciona ORDER BY à query da relação
     *
     * @example $user->posts()->orderBy('created_at', 'DESC')->get()
     */
    public function orderBy(string $column, string $direction = 'ASC'): static
    {
        $this->query->orderBy($column, $direction);
        return $this;
    }

    /**
     * Limita os resultados da relação
     *
     * @example $user->posts()->limit(5)->get()
     */
    public function limit(int $limit): static
    {
        $this->query->limit($limit);
        return $this;
    }

    /**
     * Executa e retorna a Collection (alias para getResults)
     */
    public function get(): Collection
    {
        return $this->getResults();
    }

    /**
     * Conta os registros relacionados
     */
    public function count(): int
    {
        return $this->query->count();
    }

    /**
     * Cria um novo registro associado ao pai
     *
     * @example $user->posts()->create(['title' => 'Novo Post'])
     */
    public function create(array $data): mixed
    {
        $data[$this->foreignKey] = $this->parent->{$this->localKey};
        $relatedClass            = get_class($this->related);
        return $relatedClass::create($data);
    }
}