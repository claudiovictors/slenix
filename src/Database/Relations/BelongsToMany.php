<?php

/*
|--------------------------------------------------------------------------
| BelongsToMany Class
|--------------------------------------------------------------------------
|
| Represents the many-to-many (N:N) relationship between two models via
| a pivot table. Supports extra pivot columns (withPivot), timestamps,
| attach/detach/sync/toggle, eager loading via match(), and lazy loading
| returning a Collection. The pivot table is named automatically in
| alphabetical order (e.g. role_user for User and Role).
|
*/

declare(strict_types=1);

namespace Slenix\Database\Relations;

use PDO;
use Slenix\Database\Connection;
use Slenix\Database\Collection;

class BelongsToMany extends Relation
{
    /** @var string Intermediate (pivot) table */
    protected string $pivotTable;

    /** @var string FK of the parent model in the pivot */
    protected string $pivotForeignKey;

    /** @var string FK of the related model in the pivot */
    protected string $pivotRelatedKey;

    /** @var array Extra pivot columns to include in results */
    protected array $pivotColumns = [];

    /** @var bool Whether to include pivot timestamps */
    protected bool $withTimestamps = false;

    /**
     * @param \Slenix\Database\Model $related         Related model
     * @param \Slenix\Database\Model $parent          Parent model
     * @param string                 $pivotTable      Pivot table name
     * @param string                 $pivotForeignKey FK of the parent in the pivot
     * @param string                 $pivotRelatedKey FK of the related in the pivot
     * @param string                 $foreignKey      PK of the parent (localKey)
     * @param string                 $localKey        PK of the related (ownerKey)
     */
    public function __construct(
        \Slenix\Database\Model $related,
        \Slenix\Database\Model $parent,
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
     * Applies the JOIN with the pivot table and WHERE by parent
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

    /**
     * Defines extra pivot columns to include in results
     *
     * @example ->withPivot('role', 'expires_at')
     */
    public function withPivot(string ...$columns): static
    {
        $this->pivotColumns = array_unique(array_merge($this->pivotColumns, $columns));
        return $this;
    }

    /**
     * Includes pivot timestamps (created_at, updated_at)
     */
    public function withTimestamps(): static
    {
        $this->withTimestamps = true;
        $this->pivotColumns   = array_unique(array_merge($this->pivotColumns, ['created_at', 'updated_at']));
        return $this;
    }

    /**
     * Inserts one or more records into the pivot table
     *
     * @param int|array $ids       ID(s) of the related model
     * @param array     $pivotData Extra pivot data
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
     * Removes one or more records from the pivot table
     *
     * @param int|array|null $ids null = removes all records for the parent
     * @return int Number of rows removed
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
     * Syncs the pivot table: removes absent records, inserts new ones
     *
     * @param array $ids       IDs to keep
     * @param bool  $detaching Whether to remove records not included (default: true)
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
     * Sync without removing records not included
     */
    public function syncWithoutDetaching(array $ids): array
    {
        return $this->sync($ids, false);
    }

    /**
     * Toggles the state of IDs in the pivot table
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
     * Updates extra pivot data for a specific ID
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

    /**
     * Executes the query and returns a Collection of related models (lazy loading)
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
     * Alias for getResults
     */
    public function get(): Collection
    {
        return $this->getResults();
    }

    /**
     * Associates eager load results to each parent model
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

    /**
     * Applies the SELECT with related model columns + pivot columns
     */
    protected function applyPivotSelect(array $columns): void
    {
        $relatedTable = $this->related->getTable();
        $selects      = array_map(
            fn($c) => $c === '*' ? "`{$relatedTable}`.*" : "`{$relatedTable}`.`{$c}`",
            $columns
        );

        // Parent FK from the pivot (required for match() in eager loading)
        $selects[] = "`{$this->pivotTable}`.`{$this->pivotForeignKey}`";

        // Extra pivot columns (prefixed with "pivot_")
        foreach ($this->pivotColumns as $col) {
            $selects[] = "`{$this->pivotTable}`.`{$col}` as `pivot_{$col}`";
        }

        $this->query->select($selects);
    }

    /**
     * Returns the IDs currently in the pivot table for the parent
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
     * Returns the PDO instance
     */
    protected function getPdo(): PDO
    {
        return Connection::getInstance();
    }
}