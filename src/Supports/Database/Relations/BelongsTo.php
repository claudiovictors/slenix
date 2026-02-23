<?php

/*
|--------------------------------------------------------------------------
| Classe BelongsTo
|--------------------------------------------------------------------------
|
| Representa a relação inversa "pertence a" (N:1) entre dois modelos.
| Exemplo: Um Post pertence a um User. A FK (user_id) fica na tabela posts.
| A foreignKey é a coluna no modelo atual; localKey é a PK do modelo pai.
| Suporta lazy loading e eager loading via match().
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Database\Relations;

class BelongsTo extends Relation
{
    /**
     * Aplica a constraint padrão: WHERE owner_key = foreign_key_value_do_pai
     *
     * foreignKey: coluna FK no modelo atual (ex: user_id em posts)
     * localKey:   coluna PK no modelo relacionado (ex: id em users)
     */
    public function addConstraints(): void
    {
        $foreignKeyValue = $this->parent->{$this->foreignKey};

        if (!empty($foreignKeyValue)) {
            $this->query->where($this->localKey, '=', $foreignKeyValue);
        }
    }

    /**
     * Associa os resultados do eager load a cada modelo (BelongsTo é 1:1)
     *
     * @param array  $models   Array de modelos que possuem a FK
     * @param array  $results  Modelos relacionados carregados
     * @param string $relation Nome da relação
     * @return array Modelos com a relação definida
     */
    public function match(array $models, array $results, string $relation): array
    {
        // Dicionário: localKey (PK do relacionado) => modelo relacionado
        $dictionary = [];
        foreach ($results as $result) {
            $key = $result->{$this->localKey};
            if ($key !== null) {
                $dictionary[$key] = $result;
            }
        }

        // Para cada modelo, busca o relacionado pela FK local
        foreach ($models as $model) {
            $foreignValue = $model->{$this->foreignKey};
            $model->setRelation($relation, $dictionary[$foreignValue] ?? null);
        }

        return $models;
    }

    /**
     * Executa a query e retorna o modelo relacionado (lazy loading)
     */
    public function getResults(array $columns = ['*']): mixed
    {
        $foreignKeyValue = $this->parent->{$this->foreignKey};

        if (empty($foreignKeyValue)) {
            return null;
        }

        return $this->query->select($columns)->first();
    }

    // =========================================================
    // HELPERS DE ASSOCIAÇÃO
    // =========================================================

    /**
     * Associa o modelo pai ao relacionado (define a FK)
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
     * Remove a associação (zera a FK)
     *
     * @example $post->author()->dissociate()
     */
    public function dissociate(): mixed
    {
        $this->parent->{$this->foreignKey} = null;
        return $this->parent;
    }
}