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

namespace Slenix\Supports\Database;

use PDO;

class QueryBuilder
{
    protected PDO    $pdo;
    protected string $table;
    protected string $modelClass;
    protected array  $select   = ['*'];
    protected array  $wheres   = [];
    protected array  $bindings = [];
    protected array  $joins    = [];
    protected array  $orders   = [];
    protected array  $groups   = [];
    protected array  $havings  = [];
    protected ?int   $limit    = null;
    protected int    $offset   = 0;
    protected bool   $distinct = false;
    protected int    $paramCount = 0;
    protected array  $eagerRelations = [];

    public function __construct(PDO $pdo, string $table, string $modelClass)
    {
        $this->pdo        = $pdo;
        $this->table      = $table;
        $this->modelClass = $modelClass;
    }

    // =========================================================
    // SELECT / DISTINCT
    // =========================================================

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
        $current      = $this->select === ['*'] ? [] : $this->select;
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
            $value    = $operator;
            $operator = '=';
        }

        $operator  = strtoupper(trim((string) $operator));
        $paramName = $this->generateParamName();

        $this->wheres[]             = [
            'type'     => 'basic',
            'column'   => $column,
            'operator' => $operator,
            'boolean'  => $boolean,
            'param'    => $paramName,
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
        if (empty($values)) return $this;

        $params = [];
        foreach ($values as $value) {
            $paramName              = $this->generateParamName();
            $params[]               = ":{$paramName}";
            $this->bindings[$paramName] = $value;
        }

        $this->wheres[] = [
            'type'    => $not ? 'not_in' : 'in',
            'column'  => $column,
            'values'  => $params,
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
            'type'      => $not ? 'not_between' : 'between',
            'column'    => $column,
            'min_param' => $minParam,
            'max_param' => $maxParam,
            'boolean'   => $boolean,
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
            'type'    => $not ? 'not_null' : 'null',
            'column'  => $column,
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
            'type'     => 'column',
            'first'    => $first,
            'operator' => $operator,
            'second'   => $second,
            'boolean'  => $boolean,
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
                'type'    => 'nested',
                'query'   => $query,
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
            'type'    => 'raw',
            'sql'     => $sql,
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
            'column'    => $column,
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
        $driver       = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $randomFunc   = $driver === 'pgsql' ? 'RANDOM()' : 'RAND()';
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
        $paramName                  = $this->generateParamName();
        $this->havings[]            = compact('column', 'operator', 'boolean') + ['param' => $paramName];
        $this->bindings[$paramName] = $value;
        return $this;
    }

    // =========================================================
    // LIMIT / OFFSET / PAGINAÇÃO
    // =========================================================

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
        $this->limit  = $perPage;
        $this->offset = ($page - 1) * $perPage;
        return $this;
    }

    public function forPage(int $page, int $perPage = 15): static
    {
        return $this->take($perPage, $page);
    }

    // =========================================================
    // EAGER LOADING
    // =========================================================

    /**
     * Carrega relações antecipadamente (N+1 prevention)
     *
     * @example ->withRelations('posts')
     * @example ->withRelations(['posts', 'profile'])
     * @example ->withRelations(['posts:title,content'])  // seleciona colunas específicas
     */
    public function withRelations(array|string $relations): static
    {
        if (is_string($relations)) $relations = [$relations];

        foreach ($relations as $rel) {
            if (str_contains($rel, ':')) {
                [$name, $colsStr]              = explode(':', $rel, 2);
                $this->eagerRelations[$name]   = array_map('trim', explode(',', $colsStr));
            } else {
                $this->eagerRelations[$rel] = ['*'];
            }
        }

        return $this;
    }

    // =========================================================
    // EXECUTORES — get() retorna Collection
    // =========================================================

    /**
     * Executa e retorna uma Collection de modelos
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
     * Retorna o primeiro resultado ou null
     */
    public function first(): ?object
    {
        $backup      = $this->limit;
        $this->limit = 1;
        $models      = $this->buildSelectSqlAndExecute();
        $this->limit = $backup;

        return $models[0] ?? null;
    }

    /**
     * Retorna o primeiro ou lança exceção
     */
    public function firstOrFail(): object
    {
        return $this->first() ?? throw new \RuntimeException("Nenhum registro encontrado.");
    }

    /**
     * Busca por ID
     */
    public function find(int|string $id, string $column = 'id'): ?object
    {
        return $this->where($column, '=', $id)->first();
    }

    /**
     * Busca por ID ou lança exceção
     */
    public function findOrFail(int|string $id, string $column = 'id'): object
    {
        return $this->find($id, $column) ?? throw new \RuntimeException("Registro com ID '{$id}' não encontrado.");
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
        $sql  = $this->buildSelectSql();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->bindings);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retorna o primeiro resultado como array associativo
     */
    public function firstArray(): ?array
    {
        $backup      = $this->limit;
        $this->limit = 1;
        $sql         = $this->buildSelectSql();
        $stmt        = $this->pdo->prepare($sql);
        $stmt->execute($this->bindings);
        $data        = $stmt->fetch(PDO::FETCH_ASSOC);
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
        $page    = max(1, $page);
        $total   = $this->count();
        $results = $this->take($perPage, $page)->get();

        $lastPage = (int) ceil($total / $perPage);
        $from     = $total > 0 ? (($page - 1) * $perPage) + 1 : 0;
        $to       = min($page * $perPage, $total);

        return [
            'data'         => $results,
            'current_page' => $page,
            'per_page'     => $perPage,
            'total'        => $total,
            'last_page'    => max(1, $lastPage),
            'from'         => $from,
            'to'           => $to,
            'has_more'     => $page < $lastPage,
        ];
    }

    protected function aggregate(string $function, string $column): mixed
    {
        $backup       = $this->select;
        $this->select = ["{$function}({$column}) as __aggregate__"];
        $sql          = $this->buildSelectSql();
        $stmt         = $this->pdo->prepare($sql);
        $stmt->execute($this->bindings);
        $result       = $stmt->fetchColumn();
        $this->select = $backup;
        return $result;
    }

    // =========================================================
    // INTERNALS
    // =========================================================

    protected function buildSelectSqlAndExecute(): array
    {
        $sql  = $this->buildSelectSql();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->bindings);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn($row) => $this->modelClass::hydrate($row), $data);
    }

    /**
     * Carrega as relações eager em lote (resolve problema N+1)
     */
    protected function loadEagerRelations(array $models): void
    {
        if (empty($models)) return;

        foreach ($this->eagerRelations as $name => $columns) {
            // Obtém uma instância limpa do modelo para chamar o método de relação
            $instance = new $this->modelClass();

            if (!method_exists($instance, $name)) {
                continue;
            }

            $relation = $instance->$name();

            if (!($relation instanceof \Slenix\Supports\Database\Relations\Relation)) {
                continue;
            }

            $foreignKey = $relation->getForeignKey();
            $localKey   = $relation->getLocalKey();

            // Para BelongsTo: carregamos pelos owner keys (chaves do pai)
            // Para HasOne/HasMany: carregamos pelas foreign keys
            $relationType = get_class($relation);
            $isBelongsTo  = str_contains($relationType, 'BelongsTo') && !str_contains($relationType, 'Many');

            if ($isBelongsTo) {
                // BelongsTo: a FK está no modelo atual, localKey é a PK do relacionado
                $keys = array_unique(array_filter(
                    array_map(fn($m) => $m->$foreignKey ?? null, $models)
                ));

                if (empty($keys)) {
                    foreach ($models as $model) {
                        $model->setRelation($name, null);
                    }
                    continue;
                }

                $relatedQuery = $relation->getRelated()::newQuery();
                if ($columns !== ['*']) {
                    $relatedQuery->select($columns);
                }
                $results = $relatedQuery->whereIn($localKey, array_values($keys))->get()->all();
            } else {
                // HasOne / HasMany: a FK está no relacionado
                $keys = array_unique(array_filter(
                    array_map(fn($m) => $m->$localKey ?? $m->getKey(), $models)
                ));

                if (empty($keys)) {
                    foreach ($models as $model) {
                        $model->setRelation($name, []);
                    }
                    continue;
                }

                $relatedQuery = $relation->getRelated()::newQuery();
                if ($columns !== ['*']) {
                    $relatedQuery->select(array_merge($columns, [$foreignKey]));
                }
                $results = $relatedQuery->whereIn($foreignKey, array_values($keys))->get()->all();
            }

            $relation->match($models, $results, $name);
        }
    }

    protected function buildSelectSql(): string
    {
        $sql = 'SELECT ';

        if ($this->distinct) $sql .= 'DISTINCT ';

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

        if ($this->limit !== null) $sql .= " LIMIT {$this->limit}";
        if ($this->offset > 0)    $sql .= " OFFSET {$this->offset}";

        return $sql;
    }

    protected function buildWhereClause(): string
    {
        $clauses = [];

        foreach ($this->wheres as $index => $where) {
            $prefix = $index === 0 ? '' : $where['boolean'] . ' ';

            $clause = match ($where['type']) {
                'basic'       => "{$where['column']} {$where['operator']} :{$where['param']}",
                'in'          => "{$where['column']} IN (" . implode(', ', $where['values']) . ")",
                'not_in'      => "{$where['column']} NOT IN (" . implode(', ', $where['values']) . ")",
                'between'     => "{$where['column']} BETWEEN :{$where['min_param']} AND :{$where['max_param']}",
                'not_between' => "{$where['column']} NOT BETWEEN :{$where['min_param']} AND :{$where['max_param']}",
                'null'        => "{$where['column']} IS NULL",
                'not_null'    => "{$where['column']} IS NOT NULL",
                'column'      => "{$where['first']} {$where['operator']} {$where['second']}",
                'raw'         => $where['sql'],
                'nested'      => '(' . $where['query']->buildWhereClause() . ')',
                default       => '',
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
            $prefix    = $index === 0 ? '' : $having['boolean'] . ' ';
            $clauses[] = $prefix . "{$having['column']} {$having['operator']} :{$having['param']}";
        }
        return implode(' ', $clauses);
    }

    protected function generateParamName(): string
    {
        return 'p' . ++$this->paramCount;
    }

    // =========================================================
    // DEBUG / UTILITÁRIOS
    // =========================================================

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
     * Retorna SQL e bindings para debug
     */
    public function dump(): array
    {
        return ['sql' => $this->toSql(), 'bindings' => $this->bindings];
    }

    /**
     * Debug e encerra a execução
     */
    public function dd(): never
    {
        var_dump($this->dump());
        exit;
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
        $this->select         = ['*'];
        $this->wheres         = [];
        $this->bindings       = [];
        $this->joins          = [];
        $this->orders         = [];
        $this->groups         = [];
        $this->havings        = [];
        $this->limit          = null;
        $this->offset         = 0;
        $this->distinct       = false;
        $this->paramCount     = 0;
        $this->eagerRelations = [];
        return $this;
    }
}