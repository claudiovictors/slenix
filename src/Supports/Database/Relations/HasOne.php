<?php

/*
|--------------------------------------------------------------------------
| Classe HasOne
|--------------------------------------------------------------------------
|
| Representa a relação "tem um" (1:1) entre dois modelos.
| Exemplo: Um User tem um Profile. A FK (user_id) fica na tabela do Profile.
| Suporta lazy loading e eager loading via match().
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Database\Relations;

class HasOne extends Relation
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
     * Associa os resultados do eager load a cada modelo pai (1:1)
     *
     * @param array  $models   Array de modelos pais
     * @param array  $results  Resultados do banco (array de modelos relacionados)
     * @param string $relation Nome da relação
     * @return array Modelos com a relação definida
     */
    public function match(array $models, array $results, string $relation): array
    {
        // Monta dicionário: foreignKey => modelo relacionado
        $dictionary = [];
        foreach ($results as $result) {
            $key = $result->{$this->foreignKey};
            if ($key !== null) {
                $dictionary[$key] = $result;
            }
        }

        // Associa o relacionado (ou null) a cada modelo pai
        foreach ($models as $model) {
            $localValue = $model->{$this->localKey} ?? $model->getKey();
            $model->setRelation($relation, $dictionary[$localValue] ?? null);
        }

        return $models;
    }

    /**
     * Executa a query e retorna um único modelo relacionado (lazy loading)
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