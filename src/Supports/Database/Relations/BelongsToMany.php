<?php

/*
|--------------------------------------------------------------------------
| Classe BelongsToMany
|--------------------------------------------------------------------------
|
| Representa a relação muitos-para-muitos (N:N) entre dois modelos via
| tabela pivot. Suporta colunas extras na pivot (withPivot), timestamps,
| attach/detach/sync/toggle, eager loading via match() e lazy loading
| retornando Collection. A tabela pivot é nomeada automaticamente em
| ordem alfabética (ex: role_user para User e Role).
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Database\Relations;

use PDO;
use Slenix\Supports\Database\Collection;

class BelongsToMany extends Relation
{
    /** @var string Tabela intermediária (pivot) */
    protected string $pivotTable;

    /** @var string FK do modelo pai na pivot */
    protected string $pivotForeignKey;

    /** @var string FK do modelo relacionado na pivot */
    protected string $pivotRelatedKey;

    /** @var array Colunas extras da pivot a trazer nos resultados */
    protected array $pivotColumns = [];

    /** @var bool Se deve incluir timestamps da pivot */
    protected bool $withTimestamps = false;

    /**
     * @param \Slenix\Supports\Database\Model $related        Modelo relacionado
     * @param \Slenix\Supports\Database\Model $parent         Modelo pai
     * @param string                           $pivotTable     Tabela pivot
     * @param string                           $pivotForeignKey FK do pai na pivot
     * @param string                           $pivotRelatedKey FK do relacionado na pivot
     * @param string                           $foreignKey     PK do pai (localKey)
     * @param string                           $localKey       PK do relacionado (ownerKey)
     */
    public function __construct(
        \Slenix\Supports\Database\Model $related,
        \Slenix\Supports\Database\Model $parent,
        string $pivotTable,
        string $pivotForeignKey,
        string $pivotRelatedKey,
        string $foreignKey,
        string $localKey
    ) {
        $this->pivotTable      = $pivotTable;
        $this->pivotForeignKey = $pivotForeignKey;
        $this->pivotRelatedKey = $pivotRelatedKey;

        parent::__construct($related, $parent, $foreignKey, $localKey);
    }

    /**
     * Aplica JOIN com a pivot e WHERE pelo pai
     */
    public function addConstraints(): void
    {
        $relatedTable = $this->related->getTable();

        $this->query->join(
            $this->pivotTable,
            "{$relatedTable}.{$this->localKey}",
            '=',
            "{$this->pivotTable}.{$this->pivotRelatedKey}"
        );

        $parentId = $this->parent->{$this->foreignKey} ?? $this->parent->getKey();
        if (!empty($parentId)) {
            $this->query->where("{$this->pivotTable}.{$this->pivotForeignKey}", '=', $parentId);
        }
    }

    // =========================================================
    // CONFIGURAÇÃO DA PIVOT
    // =========================================================

    /**
     * Define colunas extras da pivot a incluir nos resultados
     *
     * @example ->withPivot('role', 'expires_at')
     */
    public function withPivot(string ...$columns): static
    {
        $this->pivotColumns = array_unique(array_merge($this->pivotColumns, $columns));
        return $this;
    }

    /**
     * Inclui timestamps (created_at, updated_at) da pivot
     */
    public function withTimestamps(): static
    {
        $this->withTimestamps = true;
        $this->pivotColumns   = array_unique(array_merge($this->pivotColumns, ['created_at', 'updated_at']));
        return $this;
    }

    // =========================================================
    // ATTACH / DETACH / SYNC / TOGGLE
    // =========================================================

    /**
     * Insere um ou mais registros na pivot
     *
     * @param int|array $ids       ID(s) do modelo relacionado
     * @param array     $pivotData Dados extras da pivot
     */
    public function attach(int|array $ids, array $pivotData = []): void
    {
        $ids      = (array) $ids;
        $parentId = $this->parent->getKey();
        $pdo      = $this->getPdo();

        foreach ($ids as $id) {
            $data = array_merge([
                $this->pivotForeignKey => $parentId,
                $this->pivotRelatedKey => $id,
            ], $pivotData);

            if ($this->withTimestamps) {
                $now = date('Y-m-d H:i:s');
                $data['created_at'] ??= $now;
                $data['updated_at'] ??= $now;
            }

            $cols   = implode(', ', array_map(fn($c) => "`{$c}`", array_keys($data)));
            $params = ':' . implode(', :', array_keys($data));
            $sql    = "INSERT INTO `{$this->pivotTable}` ({$cols}) VALUES ({$params})";

            $pdo->prepare($sql)->execute($data);
        }
    }

    /**
     * Remove um ou mais registros da pivot
     *
     * @param int|array|null $ids null = remove todos do pai
     * @return int Número de linhas removidas
     */
    public function detach(int|array|null $ids = null): int
    {
        $parentId = $this->parent->getKey();
        $pdo      = $this->getPdo();

        if ($ids === null) {
            $stmt = $pdo->prepare(
                "DELETE FROM `{$this->pivotTable}` WHERE `{$this->pivotForeignKey}` = :parent_id"
            );
            $stmt->execute(['parent_id' => $parentId]);
            return $stmt->rowCount();
        }

        $ids   = (array) $ids;
        $count = 0;
        foreach ($ids as $id) {
            $stmt = $pdo->prepare(
                "DELETE FROM `{$this->pivotTable}`
                 WHERE `{$this->pivotForeignKey}` = :parent_id
                   AND `{$this->pivotRelatedKey}` = :related_id"
            );
            $stmt->execute(['parent_id' => $parentId, 'related_id' => $id]);
            $count += $stmt->rowCount();
        }

        return $count;
    }

    /**
     * Sincroniza a pivot: remove ausentes, insere novos
     *
     * @param array $ids       IDs para manter
     * @param bool  $detaching Se deve remover os não incluídos (padrão: true)
     * @return array{attached: array, detached: array, updated: array}
     */
    public function sync(array $ids, bool $detaching = true): array
    {
        $current  = $this->getCurrentRelatedIds();
        $toAttach = array_diff($ids, $current);
        $toDetach = $detaching ? array_diff($current, $ids) : [];

        if (!empty($toDetach)) {
            $this->detach(array_values($toDetach));
        }

        if (!empty($toAttach)) {
            $this->attach(array_values($toAttach));
        }

        return [
            'attached' => array_values($toAttach),
            'detached' => array_values($toDetach),
            'updated'  => [],
        ];
    }

    /**
     * Sync sem remover os não incluídos
     */
    public function syncWithoutDetaching(array $ids): array
    {
        return $this->sync($ids, false);
    }

    /**
     * Alterna (toggle) o estado de IDs na pivot
     *
     * @example $user->roles()->toggle([1, 2, 3])
     */
    public function toggle(int|array $ids): array
    {
        $ids      = (array) $ids;
        $current  = $this->getCurrentRelatedIds();
        $toAttach = array_diff($ids, $current);
        $toDetach = array_intersect($ids, $current);

        if (!empty($toDetach)) $this->detach(array_values($toDetach));
        if (!empty($toAttach)) $this->attach(array_values($toAttach));

        return [
            'attached' => array_values($toAttach),
            'detached' => array_values($toDetach),
        ];
    }

    /**
     * Atualiza dados extras na pivot para um ID específico
     *
     * @example $user->roles()->updateExistingPivot(1, ['expires_at' => '2025-12-31'])
     */
    public function updateExistingPivot(int $id, array $data): bool
    {
        $parentId = $this->parent->getKey();

        if ($this->withTimestamps) {
            $data['updated_at'] ??= date('Y-m-d H:i:s');
        }

        $sets   = implode(', ', array_map(fn($k) => "`{$k}` = :{$k}", array_keys($data)));
        $sql    = "UPDATE `{$this->pivotTable}` SET {$sets}
                   WHERE `{$this->pivotForeignKey}` = :__parent_id__
                     AND `{$this->pivotRelatedKey}` = :__related_id__";

        $params = array_merge($data, [
            '__parent_id__'  => $parentId,
            '__related_id__' => $id,
        ]);

        return $this->getPdo()->prepare($sql)->execute($params);
    }

    // =========================================================
    // LAZY / EAGER LOADING
    // =========================================================

    /**
     * Executa a query e retorna Collection dos relacionados (lazy loading)
     */
    public function getResults(array $columns = ['*']): Collection
    {
        if (empty($this->parent->getKey())) {
            return new Collection();
        }

        $this->applyPivotSelect($columns);
        return $this->query->get();
    }

    /**
     * Alias para getResults
     */
    public function get(): Collection
    {
        return $this->getResults();
    }

    /**
     * Associa resultados do eager load a cada modelo pai
     */
    public function match(array $models, array $results, string $relation): array
    {
        $dictionary = [];
        foreach ($results as $result) {
            $pivotKey = $result->{$this->pivotForeignKey} ?? null;
            if ($pivotKey !== null) {
                $dictionary[$pivotKey][] = $result;
            }
        }

        foreach ($models as $model) {
            $key = $model->getKey();
            $model->setRelation($relation, new Collection($dictionary[$key] ?? []));
        }

        return $models;
    }

    // =========================================================
    // INTERNOS
    // =========================================================

    /**
     * Aplica o SELECT com colunas do relacionado + pivot
     */
    protected function applyPivotSelect(array $columns): void
    {
        $relatedTable = $this->related->getTable();
        $selects      = array_map(
            fn($c) => $c === '*' ? "`{$relatedTable}`.*" : "`{$relatedTable}`.`{$c}`",
            $columns
        );

        // FK do pai da pivot (necessária para o match no eager loading)
        $selects[] = "`{$this->pivotTable}`.`{$this->pivotForeignKey}`";

        // Colunas extras da pivot (prefixadas com "pivot_")
        foreach ($this->pivotColumns as $col) {
            $selects[] = "`{$this->pivotTable}`.`{$col}` as `pivot_{$col}`";
        }

        $this->query->select($selects);
    }

    /**
     * Retorna IDs atualmente na pivot para o pai
     */
    protected function getCurrentRelatedIds(): array
    {
        $parentId = $this->parent->getKey();
        $sql      = "SELECT `{$this->pivotRelatedKey}` FROM `{$this->pivotTable}`
                     WHERE `{$this->pivotForeignKey}` = :parent_id";
        $stmt     = $this->getPdo()->prepare($sql);
        $stmt->execute(['parent_id' => $parentId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Obtém a instância do PDO
     */
    protected function getPdo(): PDO
    {
        return \Slenix\Supports\Database\Connection::getInstance();
    }
}