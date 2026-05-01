<?php

/*
|--------------------------------------------------------------------------
| Classe QueryBuilder
|--------------------------------------------------------------------------
|
| Constrói e executa consultas SQL de forma fluente e segura, usando
| prepared statements. Suporta SELECT, WHERE, JOIN, ORDER BY, GROUP BY,
| HAVING, LIMIT, OFFSET, eager loading de relações, agregações e paginação.
| Retorna instâncias de Collection para encadeamento de métodos.
|
*/

declare(strict_types=1);

namespace Slenix\Database;

use PDO;

class QueryBuilder
{
    protected PDO $pdo;
    protected string $table;
    protected string $modelClass;
    protected array $select = ['*'];
    protected array $wheres = [];
    protected array $bindings = [];
    protected array $joins = [];
    protected array $orders = [];
    protected array $groups = [];
    protected array $havings = [];
    protected ?int $limit = null;
    protected int $offset = 0;
    protected bool $distinct = false;
    protected int $paramCount = 0;
    protected array $eagerRelations = [];
    private int $cacheSeconds;
    private string $cacheKey;

    public function __construct(PDO $pdo, string $table, string $modelClass)
    {
        $this->pdo = $pdo;
        $this->table = $table;
        $this->modelClass = $modelClass;
    }

    public function select(array|string $columns = ['*']): static
    {
        if (is_string($columns)) {
            $columns = array_map('trim', explode(',', $columns));
        }
        $this->select = is_array($columns) ? $columns : [$columns];
        return $this;
    }

    public function addSelect(array|string $columns): static
    {
        if (is_string($columns)) {
            $columns = array_map('trim', explode(',', $columns));
        }
        $current = $this->select === ['*'] ? [] : $this->select;
        $this->select = array_merge($current, (array) $columns);
        return $this;
    }

    public function distinct(): static
    {
        $this->distinct = true;
        return $this;
    }

    // =========================================================
    // WHERE
    // =========================================================

    public function where(string|callable $column, mixed $operator = null, mixed $value = null, string $boolean = 'AND'): static
    {
        if (is_callable($column)) {
            return $this->whereNested($column, $boolean);
        }

        // Suporte a 2 argumentos: where('col', 'val') == where('col', '=', 'val')
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        $operator = strtoupper(trim((string) $operator));
        $paramName = $this->generateParamName();

        $this->wheres[] = [
            'type' => 'basic',
            'column' => $column,
            'operator' => $operator,
            'boolean' => $boolean,
            'param' => $paramName,
        ];
        $this->bindings[$paramName] = $value;

        return $this;
    }

    public function orWhere(string $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->where($column, $operator, $value, 'OR');
    }

    public function whereIn(string $column, array $values, string $boolean = 'AND', bool $not = false): static
    {
        if (empty($values))
            return $this;

        $params = [];
        foreach ($values as $value) {
            $paramName = $this->generateParamName();
            $params[] = ":{$paramName}";
            $this->bindings[$paramName] = $value;
        }

        $this->wheres[] = [
            'type' => $not ? 'not_in' : 'in',
            'column' => $column,
            'values' => $params,
            'boolean' => $boolean,
        ];

        return $this;
    }

    public function whereNotIn(string $column, array $values, string $boolean = 'AND'): static
    {
        return $this->whereIn($column, $values, $boolean, true);
    }

    public function orWhereIn(string $column, array $values): static
    {
        return $this->whereIn($column, $values, 'OR');
    }

    public function whereBetween(string $column, mixed $min, mixed $max, string $boolean = 'AND', bool $not = false): static
    {
        $minParam = $this->generateParamName();
        $maxParam = $this->generateParamName();

        $this->wheres[] = [
            'type' => $not ? 'not_between' : 'between',
            'column' => $column,
            'min_param' => $minParam,
            'max_param' => $maxParam,
            'boolean' => $boolean,
        ];

        $this->bindings[$minParam] = $min;
        $this->bindings[$maxParam] = $max;

        return $this;
    }

    public function whereNotBetween(string $column, mixed $min, mixed $max, string $boolean = 'AND'): static
    {
        return $this->whereBetween($column, $min, $max, $boolean, true);
    }

    public function whereNull(string $column, string $boolean = 'AND', bool $not = false): static
    {
        $this->wheres[] = [
            'type' => $not ? 'not_null' : 'null',
            'column' => $column,
            'boolean' => $boolean,
        ];
        return $this;
    }

    public function whereNotNull(string $column, string $boolean = 'AND'): static
    {
        return $this->whereNull($column, $boolean, true);
    }

    /**
     * Compara duas colunas entre si
     *
     * @example ->whereColumn('users.id', '=', 'posts.user_id')
     */
    public function whereColumn(string $first, string $operator, string $second, string $boolean = 'AND'): static
    {
        $this->wheres[] = [
            'type' => 'column',
            'first' => $first,
            'operator' => $operator,
            'second' => $second,
            'boolean' => $boolean,
        ];
        return $this;
    }

    /**
     * Agrupa condições WHERE em parênteses
     *
     * @example ->where(function($q) { $q->where('a', 1)->orWhere('b', 2); })
     */
    public function whereNested(callable $callback, string $boolean = 'AND'): static
    {
        $query = new static($this->pdo, $this->table, $this->modelClass);
        $query->paramCount = $this->paramCount; // Evita conflito de nomes de parâmetros
        $callback($query);
        $this->paramCount = $query->paramCount; // Sincroniza o contador

        if (!empty($query->wheres)) {
            $this->wheres[] = [
                'type' => 'nested',
                'query' => $query,
                'boolean' => $boolean,
            ];
            $this->bindings = array_merge($this->bindings, $query->bindings);
        }

        return $this;
    }

    /**
     * WHERE LIKE simplificado
     *
     * @example ->whereLike('name', '%João%')
     */
    public function whereLike(string $column, string $value, string $boolean = 'AND'): static
    {
        return $this->where($column, 'LIKE', $value, $boolean);
    }

    /**
     * WHERE NOT LIKE simplificado
     */
    public function whereNotLike(string $column, string $value, string $boolean = 'AND'): static
    {
        return $this->where($column, 'NOT LIKE', $value, $boolean);
    }

    /**
     * WHERE RAW (com cuidado com SQL injection — use bindings)
     *
     * @example ->whereRaw('YEAR(created_at) = :year', ['year' => 2024])
     */
    public function whereRaw(string $sql, array $bindings = [], string $boolean = 'AND'): static
    {
        $this->wheres[] = [
            'type' => 'raw',
            'sql' => $sql,
            'boolean' => $boolean,
        ];
        $this->bindings = array_merge($this->bindings, $bindings);
        return $this;
    }

    // =========================================================
    // JOINS
    // =========================================================

    public function join(string $table, string $first, string $operator, string $second): static
    {
        $this->joins[] = compact('table', 'first', 'operator', 'second') + ['type' => 'INNER'];
        return $this;
    }

    public function leftJoin(string $table, string $first, string $operator, string $second): static
    {
        $this->joins[] = compact('table', 'first', 'operator', 'second') + ['type' => 'LEFT'];
        return $this;
    }

    public function rightJoin(string $table, string $first, string $operator, string $second): static
    {
        $this->joins[] = compact('table', 'first', 'operator', 'second') + ['type' => 'RIGHT'];
        return $this;
    }

    public function crossJoin(string $table): static
    {
        $this->joins[] = ['type' => 'CROSS', 'table' => $table, 'first' => '', 'operator' => '', 'second' => ''];
        return $this;
    }

    // =========================================================
    // ORDER / GROUP / HAVING
    // =========================================================

    public function orderBy(string $column, string $direction = 'ASC'): static
    {
        $this->orders[] = [
            'column' => $column,
            'direction' => strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC',
        ];
        return $this;
    }

    public function orderByDesc(string $column): static
    {
        return $this->orderBy($column, 'DESC');
    }

    public function orderByRaw(string $expression): static
    {
        $this->orders[] = ['column' => $expression, 'direction' => '', 'raw' => true];
        return $this;
    }

    public function inRandomOrder(): static
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $randomFunc = $driver === 'pgsql' ? 'RANDOM()' : 'RAND()';
        $this->orders[] = ['column' => $randomFunc, 'direction' => '', 'raw' => true];
        return $this;
    }

    public function latest(string $column = 'created_at'): static
    {
        return $this->orderBy($column, 'DESC');
    }

    public function oldest(string $column = 'created_at'): static
    {
        return $this->orderBy($column, 'ASC');
    }

    public function groupBy(array|string $columns): static
    {
        $this->groups = array_merge($this->groups, (array) $columns);
        return $this;
    }

    public function having(string $column, string $operator, mixed $value, string $boolean = 'AND'): static
    {
        $paramName = $this->generateParamName();
        $this->havings[] = compact('column', 'operator', 'boolean') + ['param' => $paramName];
        $this->bindings[$paramName] = $value;
        return $this;
    }

    public function limit(int $limit): static
    {
        $this->limit = max(0, $limit);
        return $this;
    }

    public function offset(int $offset): static
    {
        $this->offset = max(0, $offset);
        return $this;
    }

    public function take(int $perPage, int $page = 1): static
    {
        $this->limit = $perPage;
        $this->offset = ($page - 1) * $perPage;
        return $this;
    }

    public function forPage(int $page, int $perPage = 15): static
    {
        return $this->take($perPage, $page);
    }

    /**
     * Carrega relações antecipadamente (N+1 prevention)
     *
     * @example ->withRelations('posts')
     * @example ->withRelations(['posts', 'profile'])
     * @example ->withRelations(['posts:title,content'])  // seleciona colunas específicas
     */
    /**
     * Registers one or more relations to be eager-loaded with the query.
     *
     * Supports:
     *   - Simple relation:           'user'
     *   - Column-constrained:        'user:id,name,email'
     *   - Nested (dot notation):     'user.profile'
     *   - Nested with columns:       'user.profile:avatar,bio'
     *   - Mixed array:               ['user', 'user.profile', 'comments:body']
     *
     * Nested relations are parsed and stored in a tree so that
     * loadEagerRelations() can recursively load them after the
     * parent relation is resolved.
     *
     * @param array|string $relations Relation name(s) with optional dot nesting and column constraints.
     * @return static Fluent.
     */
    public function withRelations(array|string $relations): static
    {
        if (is_string($relations)) {
            $relations = [$relations];
        }

        foreach ($relations as $rel) {
            // Split column constraint first: 'user.profile:avatar,bio' → ['user.profile', 'avatar,bio']
            $columns = ['*'];
            if (str_contains($rel, ':')) {
                [$rel, $colsStr] = explode(':', $rel, 2);
                $columns = array_map('trim', explode(',', $colsStr));
            }

            // Store with columns — dot notation is preserved and resolved in loadEagerRelations()
            $this->eagerRelations[trim($rel)] = $columns;
        }

        return $this;
    }

    /**
     * Executes the query and returns a Collection of model instances,
     * with all eager relations (including nested via dot notation) loaded.
     *
     * @return \Slenix\Database\Collection Collection of hydrated model instances.
     */
    public function get(): Collection
    {
        $models = $this->buildSelectSqlAndExecute();

        if (!empty($this->eagerRelations) && !empty($models)) {
            $this->loadEagerRelations($models);
        }

        return new Collection($models);
    }

    /**
     * Returns the first result matching the current query, or null.
     * Eager relations registered via with() are loaded before returning.
     *
     * @return object|null Hydrated model instance with eager relations, or null.
     */
    public function first(): ?object
    {
        $backup = $this->limit;
        $this->limit = 1;
        $models = $this->buildSelectSqlAndExecute();
        $this->limit = $backup;

        if (!empty($this->eagerRelations) && !empty($models)) {
            $this->loadEagerRelations($models);
        }

        return $models[0] ?? null;
    }

    /**
     * Returns the first result or throws when none is found.
     *
     * @return object Hydrated model with eager relations.
     * @throws \RuntimeException
     */
    public function firstOrFail(): object
    {
        return $this->first() ?? throw new \RuntimeException('No record found.');
    }

    /**
     * Finds a record by column value. Delegates to first() so eager relations are loaded.
     *
     * @param int|string $id     Value to match.
     * @param string     $column Column to search (default 'id').
     * @return object|null
     */
    public function find(int|string $id, string $column = 'id'): ?object
    {
        return $this->where($column, '=', $id)->first();
    }


    /**
     * Finds a record by column value or throws when not found.
     *
     * @param int|string $id     Value to match.
     * @param string     $column Column to search (default 'id').
     * @return object
     * @throws \RuntimeException
     */
    public function findOrFail(int|string $id, string $column = 'id'): object
    {
        return $this->find($id, $column)
            ?? throw new \RuntimeException("Record with ID '{$id}' not found.");
    }

    /**
     * Retorna o valor de uma única coluna do primeiro resultado
     */
    public function value(string $column): mixed
    {
        $result = $this->select([$column])->first();
        return $result?->$column;
    }

    /**
     * Retorna Collection de valores de uma coluna
     *
     * @example ->pluck('name')
     * @example ->pluck('name', 'id') // chaveado por id
     */
    public function pluck(string $column, ?string $keyBy = null): Collection
    {
        return $this->get()->pluck($column, $keyBy);
    }

    /**
     * Retorna resultados como array associativo
     */
    public function getArray(): array
    {
        $sql = $this->buildSelectSql();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->bindings);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retorna o primeiro resultado como array associativo
     */
    public function firstArray(): ?array
    {
        $backup = $this->limit;
        $this->limit = 1;
        $sql = $this->buildSelectSql();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->bindings);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->limit = $backup;
        return $data ?: null;
    }

    // =========================================================
    // AGREGAÇÃO
    // =========================================================

    public function count(string $column = '*'): int
    {
        return (int) $this->aggregate('COUNT', $column);
    }

    public function sum(string $column): float
    {
        return (float) ($this->aggregate('SUM', $column) ?? 0);
    }

    public function avg(string $column): float
    {
        return (float) ($this->aggregate('AVG', $column) ?? 0);
    }

    public function max(string $column): mixed
    {
        return $this->aggregate('MAX', $column);
    }

    public function min(string $column): mixed
    {
        return $this->aggregate('MIN', $column);
    }

    public function exists(): bool
    {
        return $this->count() > 0;
    }

    public function doesntExist(): bool
    {
        return !$this->exists();
    }

    /**
     * Retorna paginação completa
     *
     * @return array{data: Collection, current_page: int, per_page: int, total: int, last_page: int, from: int, to: int}
     */
    public function paginate(int $perPage = 15, int $page = 1): array
    {
        $page = max(1, $page);
        $total = $this->count();
        $results = $this->take($perPage, $page)->get();

        $lastPage = (int) ceil($total / $perPage);
        $from = $total > 0 ? (($page - 1) * $perPage) + 1 : 0;
        $to = min($page * $perPage, $total);

        return [
            'data' => $results,
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => max(1, $lastPage),
            'from' => $from,
            'to' => $to,
            'has_more' => $page < $lastPage,
        ];
    }

    protected function aggregate(string $function, string $column): mixed
    {
        $backup = $this->select;
        $this->select = ["{$function}({$column}) as __aggregate__"];
        $sql = $this->buildSelectSql();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->bindings);
        $result = $stmt->fetchColumn();
        $this->select = $backup;
        return $result;
    }

    // =========================================================
    // INTERNALS
    // =========================================================

    protected function buildSelectSqlAndExecute(): array
    {
        $sql = $this->buildSelectSql();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->bindings);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn($row) => $this->modelClass::hydrate($row), $data);
    }

    /**
     * Loads eager relations (including nested dot-notation relations) onto an array of models.
     *
     * Dot notation works by:
     *   1. Grouping all relations by their first segment (e.g. 'user' from 'user.profile').
     *   2. Loading the first-level relation as normal.
     *   3. Collecting the loaded related models and recursively calling loadEagerRelations()
     *      on them with the remaining nested segments.
     *
     * Example — Post::with(['user', 'user.profile']):
     *   Pass 1: loads 'user' on each Post   → sets $post->relations['user'] = User instance
     *   Pass 2: loads 'profile' on each User → sets $user->relations['profile'] = Profile instance
     *   Result: $post->user->profile is fully populated with zero extra queries beyond the 3 total.
     *
     * @param array $models Hydrated model instances to decorate with relations.
     * @return void
     */
    protected function loadEagerRelations(array $models): void
    {
        if (empty($models)) {
            return;
        }

        // ── Parse relation tree ───────────────────────────────────────────────
        // Separate top-level relations from nested (dot-notation) relations.
        //
        // Input eagerRelations:
        //   ['user' => ['*'], 'user.profile' => ['avatar','bio'], 'comments' => ['*']]
        //
        // topLevel:
        //   ['user' => ['*'], 'comments' => ['*']]
        //
        // nested (grouped by parent):
        //   ['user' => ['profile' => ['avatar','bio']]]

        $topLevel = [];
        $nested = [];

        foreach ($this->eagerRelations as $relation => $columns) {
            if (!str_contains($relation, '.')) {
                // Simple relation — load directly on $models
                $topLevel[$relation] = $columns;
            } else {
                // Dot-notation — split into parent and remainder
                [$parent, $remainder] = explode('.', $relation, 2);
                $nested[$parent][$remainder] = $columns;
            }
        }

        // ── Load top-level relations ──────────────────────────────────────────
        foreach ($topLevel as $name => $columns) {
            $this->loadSingleRelation($models, $name, $columns);
        }

        // ── Recursively load nested relations ─────────────────────────────────
        // For each parent that has nested children, collect the loaded related
        // models and delegate to a child QueryBuilder.
        foreach ($nested as $parent => $childRelations) {
            // Collect all related models that were loaded for this parent
            $relatedModels = [];

            foreach ($models as $model) {
                $loaded = $model->getRelation($parent);

                if ($loaded === null) {
                    continue;
                }

                // HasMany returns a Collection; HasOne / BelongsTo returns a single model
                if ($loaded instanceof \Slenix\Database\Collection) {
                    foreach ($loaded->all() as $relatedModel) {
                        $relatedModels[] = $relatedModel;
                    }
                } elseif (is_object($loaded)) {
                    $relatedModels[] = $loaded;
                }
            }

            if (empty($relatedModels)) {
                continue;
            }

            // Build a minimal QueryBuilder for the related model class so we can
            // reuse loadSingleRelation() with the correct $modelClass context.
            $relatedClass = get_class($relatedModels[0]);
            $childInstance = new $relatedClass();
            $childQB = new static(
                $this->pdo,
                $childInstance->getTable(),
                $relatedClass
            );

            foreach ($childRelations as $childName => $childColumns) {
                $childQB->loadSingleRelation($relatedModels, $childName, $childColumns);
            }
        }
    }

    /**
     * Loads a single named relation onto an array of parent model instances.
     *
     * Handles BelongsTo, HasOne, HasMany and BelongsToMany relation types.
     * After loading, each model in $models has the relation set via setRelation().
     *
     * @param array    $models  Parent model instances.
     * @param string   $name    Relation method name on the model (e.g. 'user', 'profile').
     * @param string[] $columns Columns to select on the related model (['*'] = all).
     * @return void
     */
    protected function loadSingleRelation(array $models, string $name, array $columns): void
    {
        if (empty($models)) {
            return;
        }

        $instance = new $this->modelClass();

        if (!method_exists($instance, $name)) {
            return;
        }

        $relation = $instance->$name();

        if (!($relation instanceof \Slenix\Database\Relations\Relation)) {
            return;
        }

        $foreignKey = $relation->getForeignKey();
        $localKey = $relation->getLocalKey();
        $isBelongsTo = is_a($relation, \Slenix\Database\Relations\BelongsTo::class)
            && !is_a($relation, \Slenix\Database\Relations\BelongsToMany::class);
        $isBelongsToMany = is_a($relation, \Slenix\Database\Relations\BelongsToMany::class);

        // ── BelongsTo ─────────────────────────────────────────────────────────
        if ($isBelongsTo) {
            $keys = array_values(array_unique(array_filter(
                array_map(
                    fn($m) => $m->getAttribute($foreignKey) ?? $m->$foreignKey ?? null,
                    $models
                )
            )));

            if (empty($keys)) {
                foreach ($models as $model) {
                    $model->setRelation($name, null);
                }
                return;
            }

            $relatedQuery = $relation->getRelated()::newQuery();
            if ($columns !== ['*']) {
                $relatedQuery->select(
                    array_values(array_unique(array_merge($columns, [$localKey])))
                );
            }
            $results = $relatedQuery->whereIn($localKey, $keys)->get()->all();

            // ── BelongsToMany ─────────────────────────────────────────────────────
        } elseif ($isBelongsToMany) {
            $keys = array_values(array_unique(array_filter(
                array_map(fn($m) => $m->getKey(), $models)
            )));

            if (empty($keys)) {
                foreach ($models as $model) {
                    $model->setRelation($name, new \Slenix\Database\Collection([]));
                }
                return;
            }

            $results = $relation->getResults()->all();
            $relation->match($models, $results, $name);
            return;

            // ── HasOne / HasMany ──────────────────────────────────────────────────
        } else {
            $keys = array_values(array_unique(array_filter(
                array_map(
                    fn($m) => $m->getAttribute($localKey) ?? $m->getKey() ?? null,
                    $models
                )
            )));

            if (empty($keys)) {
                $empty = is_a($relation, \Slenix\Database\Relations\HasMany::class)
                    ? new \Slenix\Database\Collection([])
                    : null;

                foreach ($models as $model) {
                    $model->setRelation($name, $empty);
                }
                return;
            }

            $relatedQuery = $relation->getRelated()::newQuery();
            if ($columns !== ['*']) {
                $relatedQuery->select(
                    array_values(array_unique(array_merge($columns, [$foreignKey])))
                );
            }
            $results = $relatedQuery->whereIn($foreignKey, $keys)->get()->all();
        }

        $relation->match($models, $results, $name);
    }

    /**
     * Filters parent models that have at least one matching related row.
     *
     * Produces: WHERE EXISTS (SELECT 1 FROM `related` WHERE fk = parent.pk [AND ...])
     *
     * @example User::whereHas('posts')->get()
     * @example User::whereHas('posts', fn($q) => $q->where('published', 1))->get()
     *
     * @param string        $relation Relation method name on the model.
     * @param callable|null $callback Optional callback to constrain the subquery.
     * @param string        $boolean  'AND' | 'OR'.
     * @return static Fluent.
     */
    public function whereHas(
        string $relation,
        ?callable $callback = null,
        string $boolean = 'AND'
    ): static {
        $instance = new $this->modelClass();
        $relationObj = $instance->$relation();

        $related = $relationObj->getRelated();
        $foreignKey = $relationObj->getForeignKey();
        $localKey = $relationObj->getLocalKey();

        // Build the inner SELECT
        $subQB = $related::newQuery()
            ->select(['1'])
            ->whereColumn($foreignKey, '=', $this->table . '.' . $localKey);

        if ($callback !== null) {
            $callback($subQB);
        }

        $subSql = $subQB->toSql();
        $subBindings = $subQB->getBindings();

        // Merge subquery bindings into parent
        $this->bindings = array_merge($this->bindings, $subBindings);

        $this->wheres[] = [
            'type' => 'raw',
            'sql' => "EXISTS ({$subSql})",
            'boolean' => $boolean,
        ];

        return $this;
    }

    /**
     * OR version of whereHas().
     *
     * @param string        $relation
     * @param callable|null $callback
     * @return static Fluent.
     */
    public function orWhereHas(string $relation, ?callable $callback = null): static
    {
        return $this->whereHas($relation, $callback, 'OR');
    }

    /**
     * Filters parent models that have NO matching related rows.
     *
     * Produces: WHERE NOT EXISTS (SELECT 1 FROM `related` WHERE fk = parent.pk)
     *
     * @example User::whereDoesntHave('posts')->get()
     *
     * @param string        $relation
     * @param callable|null $callback
     * @return static Fluent.
     */
    public function whereDoesntHave(string $relation, ?callable $callback = null): static
    {
        $instance = new $this->modelClass();
        $relationObj = $instance->$relation();

        $related = $relationObj->getRelated();
        $foreignKey = $relationObj->getForeignKey();
        $localKey = $relationObj->getLocalKey();

        $subQB = $related::newQuery()
            ->select(['1'])
            ->whereColumn($foreignKey, '=', $this->table . '.' . $localKey);

        if ($callback !== null) {
            $callback($subQB);
        }

        $this->bindings = array_merge($this->bindings, $subQB->getBindings());
        $this->wheres[] = [
            'type' => 'raw',
            'sql' => 'NOT EXISTS (' . $subQB->toSql() . ')',
            'boolean' => 'AND',
        ];

        return $this;
    }

    /**
     * Filters parent models whose related row count matches the given operator/value.
     *
     * @example User::whereHasCount('posts', '>=', 3)->get()
     *
     * @param string     $relation
     * @param string     $operator '=' | '>' | '>=' | '<' | '<=' | '!='
     * @param int        $count
     * @param callable|null $callback
     * @return static Fluent.
     */
    public function whereHasCount(
        string $relation,
        string $operator = '>=',
        int $count = 1,
        ?callable $callback = null
    ): static {
        $instance = new $this->modelClass();
        $relationObj = $instance->$relation();

        $related = $relationObj->getRelated();
        $foreignKey = $relationObj->getForeignKey();
        $localKey = $relationObj->getLocalKey();

        $subQB = $related::newQuery()
            ->select(['COUNT(*)'])
            ->whereColumn($foreignKey, '=', $this->table . '.' . $localKey);

        if ($callback !== null) {
            $callback($subQB);
        }

        $paramName = $this->generateParamName();
        $this->bindings = array_merge($this->bindings, $subQB->getBindings());
        $this->bindings[$paramName] = $count;

        $this->wheres[] = [
            'type' => 'raw',
            'sql' => '(' . $subQB->toSql() . ") {$operator} :{$paramName}",
            'boolean' => 'AND',
        ];

        return $this;
    }

    // ============================================================
// AGGREGATE EAGER LOADS
// ============================================================

    /**
     * Appends a `{relation}_count` virtual attribute to each loaded model.
     *
     * Executes one extra query total (GROUP BY FK), never N queries.
     *
     * @example Post::withCount('comments')->get()
     *          // $post->comments_count
     *
     * @param string|string[] $relations One or more relation names.
     * @return static Fluent.
     */
    public function withCount(string|array $relations): static
    {
        foreach ((array) $relations as $relation) {
            $this->eagerRelations["__count__{$relation}"] = ['__aggregate__' => 'COUNT'];
        }
        return $this;
    }

    /**
     * Appends a `{relation}_{column}_sum` virtual attribute to each loaded model.
     *
     * @example Post::withSum('likes', 'votes')->get()
     *          // $post->likes_votes_sum
     *
     * @param string $relation Relation name.
     * @param string $column   Column to sum.
     * @return static Fluent.
     */
    public function withSum(string $relation, string $column): static
    {
        $this->eagerRelations["__sum__{$relation}__{$column}"] = ['__aggregate__' => 'SUM', '__col__' => $column];
        return $this;
    }

    /**
     * Appends a `{relation}_{column}_avg` virtual attribute to each loaded model.
     *
     * @param string $relation
     * @param string $column
     * @return static Fluent.
     */
    public function withAvg(string $relation, string $column): static
    {
        $this->eagerRelations["__avg__{$relation}__{$column}"] = ['__aggregate__' => 'AVG', '__col__' => $column];
        return $this;
    }

    /**
     * Appends a `{relation}_{column}_min` virtual attribute to each loaded model.
     *
     * @param string $relation
     * @param string $column
     * @return static Fluent.
     */
    public function withMin(string $relation, string $column): static
    {
        $this->eagerRelations["__min__{$relation}__{$column}"] = ['__aggregate__' => 'MIN', '__col__' => $column];
        return $this;
    }

    /**
     * Appends a `{relation}_{column}_max` virtual attribute to each loaded model.
     *
     * @param string $relation
     * @param string $column
     * @return static Fluent.
     */
    public function withMax(string $relation, string $column): static
    {
        $this->eagerRelations["__max__{$relation}__{$column}"] = ['__aggregate__' => 'MAX', '__col__' => $column];
        return $this;
    }

    /**
     * Internal: executes aggregate subqueries registered via withCount/withSum/etc.
     * Called inside loadEagerRelations() after detecting the __count__ / __sum__ prefix.
     *
     * @param array  $models     Parent model instances.
     * @param string $key        eagerRelations key (e.g. '__count__posts').
     * @param array  $meta       Aggregate metadata (['__aggregate__' => 'COUNT', ...]).
     * @return void
     */
    protected function loadAggregateRelation(array $models, string $key, array $meta): void
    {
        // Parse key: __count__posts  or  __sum__likes__votes
        if (str_starts_with($key, '__count__')) {
            $relation = substr($key, strlen('__count__'));
            $func = 'COUNT';
            $col = '*';
            $suffix = 'count';
        } else {
            // __sum__relation__column
            preg_match('/^__(sum|avg|min|max)__(.+?)__(.+)$/', $key, $m);
            $func = strtoupper($m[1]);
            $relation = $m[2];
            $col = $m[3];
            $suffix = strtolower($func);
        }

        $instance = new $this->modelClass();
        $relationObj = $instance->$relation();
        $foreignKey = $relationObj->getForeignKey();
        $localKey = $relationObj->getLocalKey();
        $relatedTable = $relationObj->getRelated()->getTable();

        // Collect parent PKs
        $keys = array_values(array_unique(array_filter(
            array_map(fn($m) => $m->getAttribute($localKey) ?? $m->getKey(), $models)
        )));

        if (empty($keys)) {
            foreach ($models as $model) {
                $virtualKey = $col === '*'
                    ? "{$relation}_count"
                    : "{$relation}_{$col}_{$suffix}";
                $model->forceFill([$virtualKey => 0]);
            }
            return;
        }

        // Build IN placeholders
        $placeholders = [];
        $bindings = [];
        foreach ($keys as $i => $k) {
            $p = "agg_pk_{$i}";
            $placeholders[] = ":{$p}";
            $bindings[$p] = $k;
        }

        $aggExpr = $func === 'COUNT' ? "COUNT(*)" : "{$func}(`{$col}`)";
        $inClause = implode(', ', $placeholders);
        $sql = "SELECT `{$foreignKey}`, {$aggExpr} as __agg__
                  FROM `{$relatedTable}`
                  WHERE `{$foreignKey}` IN ({$inClause})
                  GROUP BY `{$foreignKey}`";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Map FK → aggregate value
        $map = [];
        foreach ($rows as $row) {
            $map[$row[$foreignKey]] = $row['__agg__'];
        }

        $virtualKey = $col === '*'
            ? "{$relation}_count"
            : "{$relation}_{$col}_{$suffix}";

        foreach ($models as $model) {
            $pk = $model->getAttribute($localKey) ?? $model->getKey();
            $value = $map[$pk] ?? 0;
            $model->forceFill([$virtualKey => (int) $value]);
        }
    }

    /**
     * Yields model instances one at a time using a PHP Generator.
     * Ideal for processing very large result sets without loading them all into memory.
     *
     * @example foreach (User::where('active', 1)->cursor() as $user) { ... }
     *
     * @return \Generator<int, object> Yields hydrated model instances.
     */
    public function cursor(): \Generator
    {
        $sql = $this->buildSelectSql();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->bindings);

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            yield $this->modelClass::hydrate($row);
        }
    }

    /**
     * Processes results in chunks, yielding each chunk as a Collection.
     * Uses OFFSET-based pagination internally.
     *
     * @example foreach (User::lazy(200) as $chunk) { $chunk->each(...); }
     *
     * @param int $chunkSize Number of models per chunk.
     * @return \Generator<int, \Slenix\Database\Collection>
     */
    public function lazy(int $chunkSize = 1000): \Generator
    {
        $page = 1;

        do {
            $results = $this->clone()->take($chunkSize, $page)->get();
            if ($results->isEmpty()) {
                break;
            }
            yield $results;
            $page++;
        } while ($results->count() === $chunkSize);
    }

    /**
     * Chunks results using PK-range queries instead of OFFSET.
     * Much more efficient than OFFSET-based chunking on large tables
     * because it avoids full-table scans.
     *
     * @example User::chunkById(500, function(Collection $chunk) { ... })
     *
     * @param int      $size     Rows per chunk.
     * @param callable $callback Receives a Collection. Return false to stop.
     * @param string   $column   Primary key column (default 'id').
     * @return void
     */
    public function chunkById(int $size, callable $callback, string $column = 'id'): void
    {
        $lastId = 0;

        do {
            $results = $this->clone()
                ->where($column, '>', $lastId)
                ->orderBy($column, 'ASC')
                ->limit($size)
                ->get();

            if ($results->isEmpty()) {
                break;
            }

            if ($callback($results) === false) {
                break;
            }

            $last = $results->last();
            $lastId = $last?->$column ?? $last?->getKey() ?? 0;

        } while ($results->count() === $size);
    }

    /**
     * Iterates over every model using chunkById internally.
     * Lower memory than get() for very large tables.
     *
     * @example User::where('active', 1)->eachById(function(User $user) { ... })
     *
     * @param callable $callback Receives each model. Return false to stop iteration.
     * @param int      $size     Chunk size.
     * @param string   $column   PK column.
     * @return void
     */
    public function eachById(callable $callback, int $size = 500, string $column = 'id'): void
    {
        $this->chunkById($size, function (\Slenix\Database\Collection $chunk) use ($callback) {
            foreach ($chunk as $model) {
                if ($callback($model) === false) {
                    return false;
                }
            }
        }, $column);
    }

    /**
     * Caches the query result for a given number of seconds.
     * On subsequent calls with the same SQL + bindings, returns the cached Collection.
     *
     * @example User::where('active', 1)->remember(300)->get()
     *
     * @param int         $seconds \Cache TTL.
     * @param string|null $key     Custom cache key (auto-generated from SQL when null).
     * @return static Fluent — call get() / first() after this.
     */
    public function remember(int $seconds = 60, ?string $key = null): static
    {
        $this->cacheSeconds = $seconds;
        $this->cacheKey = $key;
        return $this;
    }

    /**
     * Caches the query result indefinitely (no expiry).
     *
     * @param string|null $key Custom cache key.
     * @return static Fluent.
     */
    public function rememberForever(?string $key = null): static
    {
        return $this->remember(0, $key);
    }

    /**
     * Clears the cached result for this exact query.
     *
     * @return static Fluent.
     */
    public function flushCache(): static
    {
        \Slenix\Supports\Cache\Cache::forget($this->resolveCacheKey());
        return $this;
    }

    /**
     * Resolves or generates the cache key for this query.
     *
     * @return string MD5-based key derived from SQL + serialized bindings.
     */
    protected function resolveCacheKey(): string
    {
        return $this->cacheKey
            ?? 'qb_' . md5($this->toSql() . serialize($this->bindings));
    }

    /**
     * Wraps the Collection fetch with cache logic.
     * Called inside get() and first() when $this->cacheSeconds is set.
     *
     * @param callable $fetch Closure that returns the raw result.
     * @return mixed Cached or fresh result.
     */
    protected function fetchWithCache(callable $fetch): mixed
    {
        if (!isset($this->cacheSeconds)) {
            return $fetch();
        }

        $key = $this->resolveCacheKey();

        if ($this->cacheSeconds === 0) {
            return \Slenix\Supports\Cache\Cache::remember($key, PHP_INT_MAX, $fetch);
        }

        return \Slenix\Supports\Cache\Cache::remember($key, $this->cacheSeconds, $fetch);
    }

    /**
     * Applies the callback to the QueryBuilder only when $condition is true.
     * Keeps query building clean without if/else blocks in controller code.
     *
     * @example User::query()->when($request->has('search'), fn($q) => $q->whereLike('name', "%{$search}%"))->get()
     *
     * @param mixed    $condition Any truthy/falsy value.
     * @param callable $callback  Receives $this (QueryBuilder). May return $this or void.
     * @param callable|null $default Applied when condition is false.
     * @return static Fluent.
     */
    public function when(mixed $condition, callable $callback, ?callable $default = null): static
    {
        if ($condition) {
            $result = $callback($this, $condition);
            return $result instanceof static ? $result : $this;
        }

        if ($default !== null) {
            $result = $default($this, $condition);
            return $result instanceof static ? $result : $this;
        }

        return $this;
    }

    /**
     * Applies the callback only when $condition is false (inverse of when()).
     *
     * @param mixed    $condition
     * @param callable $callback
     * @return static Fluent.
     */
    public function unless(mixed $condition, callable $callback): static
    {
        return $this->when(!$condition, $callback);
    }

    /**
     * Passes the QueryBuilder to a callback without interrupting the chain.
     * Useful for debugging or side-effects mid-chain.
     *
     * @example User::latest()->tap(fn($q) => logger($q->toSql()))->get()
     *
     * @param callable $callback Receives $this.
     * @return static Fluent (unchanged).
     */
    public function tap(callable $callback): static
    {
        $callback($this);
        return $this;
    }

    // ============================================================
// RESULT HELPERS
// ============================================================

    /**
     * Expects exactly one result and returns it.
     * Throws when zero or more than one record is found.
     *
     * @example User::where('email', $email)->sole()
     *
     * @return object The single matching model.
     * @throws \RuntimeException When result count is not exactly 1.
     */
    public function sole(): object
    {
        $results = $this->limit(2)->get();

        if ($results->count() === 0) {
            throw new \RuntimeException('No record found matching the query.');
        }

        if ($results->count() > 1) {
            throw new \RuntimeException(
                'Expected exactly one result but found more than one.'
            );
        }

        return $results->first();
    }

    /**
     * Returns the SQL query with bindings interpolated for display purposes.
     * NEVER use the output of this method to execute queries — it is not safe.
     *
     * @return string Human-readable SQL string.
     */
    public function toRawSql(): string
    {
        $sql = $this->toSql();
        $bindings = $this->bindings;

        foreach ($bindings as $key => $value) {
            $escaped = is_string($value)
                ? "'" . addslashes($value) . "'"
                : (is_null($value) ? 'NULL' : (string) $value);

            $sql = str_replace(":{$key}", $escaped, $sql);
        }

        return $sql;
    }

    /**
     * Runs EXPLAIN on the current query and returns the result rows.
     * Works on MySQL and PostgreSQL. Returns empty array on SQLite.
     *
     * @example User::where('active', 1)->explainSql()
     *
     * @return array<int, array<string, mixed>> EXPLAIN rows.
     */
    public function explainSql(): array
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            return [['note' => 'EXPLAIN not supported on SQLite via this method.']];
        }

        $prefix = $driver === 'pgsql' ? 'EXPLAIN ' : 'EXPLAIN ';
        $stmt = $this->pdo->prepare($prefix . $this->toSql());
        $stmt->execute($this->bindings);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Writes the current SQL and bindings to the Slenix Log at DEBUG level.
     *
     * @param string $label Optional label to identify the log entry.
     * @return static Fluent (unchanged).
     */
    public function log(string $label = 'QueryBuilder'): static
    {
        \Slenix\Supports\Logging\Log::debug($label, [
            'sql' => $this->toSql(),
            'bindings' => $this->bindings,
            'raw' => $this->toRawSql(),
        ]);

        return $this;
    }

    /**
     * Dumps the SQL and bindings to the screen and continues execution.
     *
     * @return static Fluent (unchanged).
     */
    public function dump(): static
    {
        var_dump([
            'sql' => $this->toSql(),
            'bindings' => $this->bindings,
            'raw' => $this->toRawSql(),
        ]);

        return $this;
    }

    /**
     * Dumps the SQL and bindings and immediately stops execution.
     *
     * @return never
     */
    public function dd()
    {
        dd([
            'sql' => $this->toSql(),
            'bindings' => $this->bindings,
            'raw' => $this->toRawSql(),
        ]);

        exit(1);
    }


    protected function buildSelectSql(): string
    {
        $sql = 'SELECT ';

        if ($this->distinct)
            $sql .= 'DISTINCT ';

        $sql .= implode(', ', $this->select);
        $sql .= " FROM `{$this->table}`";

        foreach ($this->joins as $join) {
            if ($join['type'] === 'CROSS') {
                $sql .= " CROSS JOIN `{$join['table']}`";
            } else {
                $sql .= " {$join['type']} JOIN `{$join['table']}` ON {$join['first']} {$join['operator']} {$join['second']}";
            }
        }

        if (!empty($this->wheres)) {
            $sql .= ' WHERE ' . $this->buildWhereClause();
        }

        if (!empty($this->groups)) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groups);
        }

        if (!empty($this->havings)) {
            $sql .= ' HAVING ' . $this->buildHavingClause();
        }

        if (!empty($this->orders)) {
            $orderParts = [];
            foreach ($this->orders as $order) {
                $orderParts[] = isset($order['raw']) && $order['raw']
                    ? $order['column']
                    : trim($order['column'] . ' ' . $order['direction']);
            }
            $sql .= ' ORDER BY ' . implode(', ', $orderParts);
        }

        if ($this->limit !== null)
            $sql .= " LIMIT {$this->limit}";
        if ($this->offset > 0)
            $sql .= " OFFSET {$this->offset}";

        return $sql;
    }

    protected function buildWhereClause(): string
    {
        $clauses = [];

        foreach ($this->wheres as $index => $where) {
            $prefix = $index === 0 ? '' : $where['boolean'] . ' ';

            $clause = match ($where['type']) {
                'basic' => "{$where['column']} {$where['operator']} :{$where['param']}",
                'in' => "{$where['column']} IN (" . implode(', ', $where['values']) . ")",
                'not_in' => "{$where['column']} NOT IN (" . implode(', ', $where['values']) . ")",
                'between' => "{$where['column']} BETWEEN :{$where['min_param']} AND :{$where['max_param']}",
                'not_between' => "{$where['column']} NOT BETWEEN :{$where['min_param']} AND :{$where['max_param']}",
                'null' => "{$where['column']} IS NULL",
                'not_null' => "{$where['column']} IS NOT NULL",
                'column' => "{$where['first']} {$where['operator']} {$where['second']}",
                'raw' => $where['sql'],
                'nested' => '(' . $where['query']->buildWhereClause() . ')',
                default => '',
            };

            if ($clause !== '') {
                $clauses[] = $prefix . $clause;
            }
        }

        return implode(' ', $clauses);
    }

    protected function buildHavingClause(): string
    {
        $clauses = [];
        foreach ($this->havings as $index => $having) {
            $prefix = $index === 0 ? '' : $having['boolean'] . ' ';
            $clauses[] = $prefix . "{$having['column']} {$having['operator']} :{$having['param']}";
        }
        return implode(' ', $clauses);
    }

    protected function generateParamName(): string
    {
        return 'p' . ++$this->paramCount;
    }

    /**
     * Retorna a SQL gerada (sem executar)
     */
    public function toSql(): string
    {
        return $this->buildSelectSql();
    }

    /**
     * Retorna os bindings atuais
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }

    /**
     * Clona o QueryBuilder
     */
    public function clone(): static
    {
        return clone $this;
    }

    /**
     * Reseta o QueryBuilder para o estado inicial
     */
    public function reset(): static
    {
        $this->select = ['*'];
        $this->wheres = [];
        $this->bindings = [];
        $this->joins = [];
        $this->orders = [];
        $this->groups = [];
        $this->havings = [];
        $this->limit = null;
        $this->offset = 0;
        $this->distinct = false;
        $this->paramCount = 0;
        $this->eagerRelations = [];
        return $this;
    }
}