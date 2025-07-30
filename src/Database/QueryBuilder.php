<?php

/**
 * |--------------------------------------------------------------------------
 * | SLENIX QUERY BUILDER - Sistema de construção de consultas fluente
 * |--------------------------------------------------------------------------
 * |
 * | Fornece uma interface fluente para construção de consultas SQL de forma
 * | elegante e intuitiva, permitindo encadeamento de métodos e reutilização.
 * |
 * | @package Slenix\Database
 * | @author Slenix
 * | @version 1.0
 */

declare(strict_types=1);

namespace Slenix\Database;

use PDO, PDOStatement;

/**
 * Class QueryBuilder
 * 
 * Implementa o padrão Builder para construção fluente de consultas SQL
 */
class QueryBuilder
{
    /** @var PDO Instância da conexão com o banco */
    protected PDO $pdo;
    
    /** @var string Nome da tabela principal */
    protected string $table;
    
    /** @var string Classe do modelo associado */
    protected string $modelClass;
    
    /** @var array Colunas selecionadas */
    protected array $select = ['*'];
    
    /** @var array Condições WHERE */
    protected array $wheres = [];
    
    /** @var array Parâmetros para binding */
    protected array $bindings = [];
    
    /** @var array Joins da consulta */
    protected array $joins = [];
    
    /** @var array Cláusulas ORDER BY */
    protected array $orders = [];
    
    /** @var array Cláusulas GROUP BY */
    protected array $groups = [];
    
    /** @var array Condições HAVING */
    protected array $havings = [];
    
    /** @var int|null Limite de registros */
    protected ?int $limit = null;
    
    /** @var int Offset para paginação */
    protected int $offset = 0;
    
    /** @var bool Se deve usar DISTINCT */
    protected bool $distinct = false;
    
    /** @var int Contador para parâmetros únicos */
    protected int $paramCount = 0;

    /**
     * Construtor do QueryBuilder
     * 
     * @param PDO $pdo Instância da conexão PDO
     * @param string $table Nome da tabela
     * @param string $modelClass Classe do modelo associado
     */
    public function __construct(PDO $pdo, string $table, string $modelClass)
    {
        $this->pdo = $pdo;
        $this->table = $table;
        $this->modelClass = $modelClass;
    }

    /**
     * Define as colunas a serem selecionadas
     * 
     * @param array|string $columns Colunas para selecionar
     * @return $this
     * 
     * @example select(['name', 'email']) ou select('name, email')
     */
    public function select($columns = ['*']): self
    {
        if (is_string($columns)) {
            $columns = array_map('trim', explode(',', $columns));
        }
        
        $this->select = is_array($columns) ? $columns : [$columns];
        return $this;
    }

    /**
     * Adiciona DISTINCT à consulta
     * 
     * @return $this
     */
    public function distinct(): self
    {
        $this->distinct = true;
        return $this;
    }

    /**
     * Adiciona condição WHERE à consulta
     * 
     * @param string|callable $column Nome da coluna ou função callback
     * @param string|null $operator Operador de comparação (=, >, <, LIKE, etc.)
     * @param mixed $value Valor para comparação
     * @param string $boolean Operador lógico (AND/OR)
     * @return $this
     * 
     * @example where('name', '=', 'João')
     * @example where('age', '>', 18)
     * @example where('email', 'LIKE', '%@gmail.com')
     */
    public function where($column, $operator = null, $value = null, string $boolean = 'AND'): self
    {
        // Se apenas um parâmetro for passado, assume que é um callback
        if (is_callable($column)) {
            return $this->whereNested($column, $boolean);
        }

        // Se apenas dois parâmetros, assume que o operador é '='
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        $paramName = $this->generateParamName();
        
        $this->wheres[] = [
            'type' => 'basic',
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
            'boolean' => $boolean,
            'param' => $paramName
        ];
        
        $this->bindings[$paramName] = $value;
        
        return $this;
    }

    /**
     * Adiciona condição WHERE com operador OR
     * 
     * @param string $column Nome da coluna
     * @param string|null $operator Operador de comparação
     * @param mixed $value Valor para comparação
     * @return $this
     */
    public function orWhere(string $column, $operator = null, $value = null): self
    {
        return $this->where($column, $operator, $value, 'OR');
    }

    /**
     * Adiciona condição WHERE IN
     * 
     * @param string $column Nome da coluna
     * @param array $values Array de valores
     * @param string $boolean Operador lógico (AND/OR)
     * @param bool $not Se deve usar NOT IN
     * @return $this
     * 
     * @example whereIn('id', [1, 2, 3, 4])
     */
    public function whereIn(string $column, array $values, string $boolean = 'AND', bool $not = false): self
    {
        if (empty($values)) {
            return $this;
        }

        $params = [];
        foreach ($values as $value) {
            $paramName = $this->generateParamName();
            $params[] = ":$paramName";
            $this->bindings[$paramName] = $value;
        }

        $this->wheres[] = [
            'type' => $not ? 'not_in' : 'in',
            'column' => $column,
            'values' => $params,
            'boolean' => $boolean
        ];

        return $this;
    }

    /**
     * Adiciona condição WHERE NOT IN
     * 
     * @param string $column Nome da coluna
     * @param array $values Array de valores
     * @param string $boolean Operador lógico (AND/OR)
     * @return $this
     */
    public function whereNotIn(string $column, array $values, string $boolean = 'AND'): self
    {
        return $this->whereIn($column, $values, $boolean, true);
    }

    /**
     * Adiciona condição WHERE OR IN
     * 
     * @param string $column Nome da coluna
     * @param array $values Array de valores
     * @return $this
     */
    public function orWhereIn(string $column, array $values): self
    {
        return $this->whereIn($column, $values, 'OR');
    }

    /**
     * Adiciona condição WHERE BETWEEN
     * 
     * @param string $column Nome da coluna
     * @param mixed $min Valor mínimo
     * @param mixed $max Valor máximo
     * @param string $boolean Operador lógico (AND/OR)
     * @param bool $not Se deve usar NOT BETWEEN
     * @return $this
     * 
     * @example whereBetween('age', 18, 65)
     */
    public function whereBetween(string $column, $min, $max, string $boolean = 'AND', bool $not = false): self
    {
        $minParam = $this->generateParamName();
        $maxParam = $this->generateParamName();

        $this->wheres[] = [
            'type' => $not ? 'not_between' : 'between',
            'column' => $column,
            'min_param' => $minParam,
            'max_param' => $maxParam,
            'boolean' => $boolean
        ];

        $this->bindings[$minParam] = $min;
        $this->bindings[$maxParam] = $max;

        return $this;
    }

    /**
     * Adiciona condição WHERE NOT BETWEEN
     * 
     * @param string $column Nome da coluna
     * @param mixed $min Valor mínimo
     * @param mixed $max Valor máximo
     * @param string $boolean Operador lógico (AND/OR)
     * @return $this
     */
    public function whereNotBetween(string $column, $min, $max, string $boolean = 'AND'): self
    {
        return $this->whereBetween($column, $min, $max, $boolean, true);
    }

    /**
     * Adiciona condição WHERE IS NULL
     * 
     * @param string $column Nome da coluna
     * @param string $boolean Operador lógico (AND/OR)
     * @param bool $not Se deve usar IS NOT NULL
     * @return $this
     */
    public function whereNull(string $column, string $boolean = 'AND', bool $not = false): self
    {
        $this->wheres[] = [
            'type' => $not ? 'not_null' : 'null',
            'column' => $column,
            'boolean' => $boolean
        ];

        return $this;
    }

    /**
     * Adiciona condição WHERE IS NOT NULL
     * 
     * @param string $column Nome da coluna
     * @param string $boolean Operador lógico (AND/OR)
     * @return $this
     */
    public function whereNotNull(string $column, string $boolean = 'AND'): self
    {
        return $this->whereNull($column, $boolean, true);
    }

    /**
     * Adiciona WHERE aninhado (com parênteses)
     * 
     * @param callable $callback Função callback para construir condições aninhadas
     * @param string $boolean Operador lógico (AND/OR)
     * @return $this
     * 
     * @example where(function($query) { $query->where('a', 1)->orWhere('b', 2); })
     */
    public function whereNested(callable $callback, string $boolean = 'AND'): self
    {
        $query = new static($this->pdo, $this->table, $this->modelClass);
        $callback($query);

        if (!empty($query->wheres)) {
            $this->wheres[] = [
                'type' => 'nested',
                'query' => $query,
                'boolean' => $boolean
            ];

            // Mescla os bindings do query aninhado
            $this->bindings = array_merge($this->bindings, $query->bindings);
        }

        return $this;
    }

    /**
     * Adiciona INNER JOIN à consulta
     * 
     * @param string $table Tabela para join
     * @param string $first Primeira coluna
     * @param string $operator Operador de comparação
     * @param string $second Segunda coluna
     * @return $this
     * 
     * @example join('profiles', 'users.id', '=', 'profiles.user_id')
     */
    public function join(string $table, string $first, string $operator, string $second): self
    {
        $this->joins[] = [
            'type' => 'inner',
            'table' => $table,
            'first' => $first,
            'operator' => $operator,
            'second' => $second
        ];

        return $this;
    }

    /**
     * Adiciona LEFT JOIN à consulta
     * 
     * @param string $table Tabela para join
     * @param string $first Primeira coluna
     * @param string $operator Operador de comparação
     * @param string $second Segunda coluna
     * @return $this
     */
    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        $this->joins[] = [
            'type' => 'left',
            'table' => $table,
            'first' => $first,
            'operator' => $operator,
            'second' => $second
        ];

        return $this;
    }

    /**
     * Adiciona RIGHT JOIN à consulta
     * 
     * @param string $table Tabela para join
     * @param string $first Primeira coluna
     * @param string $operator Operador de comparação
     * @param string $second Segunda coluna
     * @return $this
     */
    public function rightJoin(string $table, string $first, string $operator, string $second): self
    {
        $this->joins[] = [
            'type' => 'right',
            'table' => $table,
            'first' => $first,
            'operator' => $operator,
            'second' => $second
        ];

        return $this;
    }

    /**
     * Adiciona ORDER BY à consulta
     * 
     * @param string $column Nome da coluna
     * @param string $direction Direção (ASC/DESC)
     * @return $this
     * 
     * @example orderBy('name', 'ASC')
     * @example orderBy('created_at', 'DESC')
     */
    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        
        $this->orders[] = [
            'column' => $column,
            'direction' => $direction
        ];

        return $this;
    }

    /**
     * Adiciona ORDER BY descendente
     * 
     * @param string $column Nome da coluna
     * @return $this
     */
    public function orderByDesc(string $column): self
    {
        return $this->orderBy($column, 'DESC');
    }

    /**
     * Adiciona ordenação aleatória
     * 
     * @return $this
     */
    public function inRandomOrder(): self
    {
        $this->orders[] = [
            'column' => 'RAND()',
            'direction' => ''
        ];

        return $this;
    }

    /**
     * Adiciona GROUP BY à consulta
     * 
     * @param string|array $columns Colunas para agrupamento
     * @return $this
     * 
     * @example groupBy('category_id')
     * @example groupBy(['category_id', 'status'])
     */
    public function groupBy($columns): self
    {
        if (is_string($columns)) {
            $columns = [$columns];
        }

        $this->groups = array_merge($this->groups, $columns);
        return $this;
    }

    /**
     * Adiciona HAVING à consulta
     * 
     * @param string $column Nome da coluna
     * @param string $operator Operador de comparação
     * @param mixed $value Valor para comparação
     * @param string $boolean Operador lógico (AND/OR)
     * @return $this
     * 
     * @example having('COUNT(*)', '>', 5)
     */
    public function having(string $column, string $operator, $value, string $boolean = 'AND'): self
    {
        $paramName = $this->generateParamName();
        
        $this->havings[] = [
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
            'boolean' => $boolean,
            'param' => $paramName
        ];
        
        $this->bindings[$paramName] = $value;
        
        return $this;
    }

    /**
     * Define limite de registros
     * 
     * @param int $limit Número máximo de registros
     * @return $this
     * 
     * @example limit(10)
     */
    public function limit(int $limit): self
    {
        $this->limit = $limit > 0 ? $limit : null;
        return $this;
    }

    /**
     * Define offset para paginação
     * 
     * @param int $offset Número de registros para pular
     * @return $this
     * 
     * @example offset(20)
     */
    public function offset(int $offset): self
    {
        $this->offset = max(0, $offset);
        return $this;
    }

    /**
     * Atalho para limit e offset (paginação)
     * 
     * @param int $perPage Registros por página
     * @param int $page Página atual (inicia em 1)
     * @return $this
     * 
     * @example take(10, 2) // 10 registros, página 2
     */
    public function take(int $perPage, int $page = 1): self
    {
        $this->limit = $perPage;
        $this->offset = ($page - 1) * $perPage;
        return $this;
    }

    /**
     * Executa a consulta e retorna todos os registros
     * 
     * @return array Array de instâncias do modelo
     */
    public function get(): array
    {
        $sql = $this->buildSelectSql();
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->bindings);
        
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $data;
    }

    /**
     * Executa a consulta e retorna o primeiro registro
     * 
     * @return object|null Instância do modelo ou null se não encontrado
     */
    public function first(): ?object
    {
        $originalLimit = $this->limit;
        $this->limit = 1;
        
        $results = $this->get();
        
        $this->limit = $originalLimit;
        
        return $results[0] ?? null;
    }

    /**
     * Executa a consulta e retorna o primeiro registro ou falha
     * 
     * @return object Instância do modelo
     * @throws \Exception Se nenhum registro for encontrado
     */
    public function firstOrFail(): object
    {
        $result = $this->first();
        
        if ($result === null) {
            throw new \Exception("Nenhum registro encontrado para a consulta especificada.");
        }
        
        return $result;
    }

    /**
     * Encontra um registro pelo ID
     * 
     * @param mixed $id ID do registro
     * @param string $column Nome da coluna (padrão: 'id')
     * @return object|null Instância do modelo ou null
     */
    public function find($id, string $column = 'id'): ?object
    {
        return $this->where($column, '=', $id)->first();
    }

    /**
     * Encontra um registro pelo ID ou falha
     * 
     * @param mixed $id ID do registro
     * @param string $column Nome da coluna (padrão: 'id')
     * @return object Instância do modelo
     * @throws \Exception Se o registro não for encontrado
     */
    public function findOrFail($id, string $column = 'id'): object
    {
        $result = $this->find($id, $column);
        
        if ($result === null) {
            throw new \Exception("Registro com ID '$id' não encontrado.");
        }
        
        return $result;
    }

    /**
     * Retorna o valor de uma coluna específica
     * 
     * @param string $column Nome da coluna
     * @return mixed Valor da coluna ou null
     */
    public function value(string $column)
    {
        $result = $this->select([$column])->first();
        return $result ? $result->$column : null;
    }

    /**
     * Retorna array com valores de uma coluna
     * 
     * @param string $column Nome da coluna
     * @param string|null $key Coluna para usar como chave do array
     * @return array Array de valores
     */
    public function pluck(string $column, string $key = null): array
    {
        $select = $key ? [$column, $key] : [$column];
        $results = $this->select($select)->get();
        
        $plucked = [];
        foreach ($results as $result) {
            if ($key) {
                $plucked[$result->$key] = $result->$column;
            } else {
                $plucked[] = $result->$column;
            }
        }
        
        return $plucked;
    }

    /**
     * Conta o número de registros
     * 
     * @param string $column Coluna para contar (padrão: '*')
     * @return int Número de registros
     */
    public function count(string $column = '*'): int
    {
        return (int) $this->aggregate('COUNT', $column);
    }

    /**
     * Calcula a soma de uma coluna
     * 
     * @param string $column Nome da coluna
     * @return float Soma dos valores
     */
    public function sum(string $column): float
    {
        return (float) $this->aggregate('SUM', $column);
    }

    /**
     * Calcula a média de uma coluna
     * 
     * @param string $column Nome da coluna
     * @return float Média dos valores
     */
    public function avg(string $column): float
    {
        return (float) $this->aggregate('AVG', $column);
    }

    /**
     * Encontra o valor máximo de uma coluna
     * 
     * @param string $column Nome da coluna
     * @return mixed Valor máximo
     */
    public function max(string $column)
    {
        return $this->aggregate('MAX', $column);
    }

    /**
     * Encontra o valor mínimo de uma coluna
     * 
     * @param string $column Nome da coluna
     * @return mixed Valor mínimo
     */
    public function min(string $column)
    {
        return $this->aggregate('MIN', $column);
    }

    /**
     * Verifica se existem registros
     * 
     * @return bool True se existir pelo menos um registro
     */
    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /**
     * Verifica se não existem registros
     * 
     * @return bool True se não existir nenhum registro
     */
    public function doesntExist(): bool
    {
        return !$this->exists();
    }

    /**
     * Cria paginação com metadados
     * 
     * @param int $perPage Registros por página
     * @param int $page Página atual
     * @return array Array com dados paginados e metadados
     */
    public function paginate(int $perPage = 15, int $page = 1): array
    {
        $total = $this->count();
        $results = $this->take($perPage, $page)->get();
        
        return [
            'data' => $results,
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => (int) ceil($total / $perPage),
            'from' => (($page - 1) * $perPage) + 1,
            'to' => min($page * $perPage, $total),
        ];
    }

    /**
     * Executa função de agregação
     * 
     * @param string $function Função de agregação (COUNT, SUM, AVG, MAX, MIN)
     * @param string $column Nome da coluna
     * @return mixed Resultado da agregação
     */
    protected function aggregate(string $function, string $column)
    {
        $originalSelect = $this->select;
        $this->select = ["{$function}({$column}) as aggregate"];
        
        $sql = $this->buildSelectSql();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->bindings);
        
        $result = $stmt->fetchColumn();
        
        $this->select = $originalSelect;
        
        return $result;
    }

    /**
     * Constrói a SQL completa da consulta SELECT
     * 
     * @return string SQL da consulta
     */
    protected function buildSelectSql(): string
    {
        $sql = 'SELECT ';
        
        // DISTINCT
        if ($this->distinct) {
            $sql .= 'DISTINCT ';
        }
        
        // SELECT columns
        $sql .= implode(', ', $this->select);
        
        // FROM table
        $sql .= " FROM {$this->table}";
        
        // JOINS
        foreach ($this->joins as $join) {
            $type = strtoupper($join['type']);
            $sql .= " {$type} JOIN {$join['table']} ON {$join['first']} {$join['operator']} {$join['second']}";
        }
        
        // WHERE conditions
        if (!empty($this->wheres)) {
            $sql .= ' WHERE ' . $this->buildWhereClause();
        }
        
        // GROUP BY
        if (!empty($this->groups)) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groups);
        }
        
        // HAVING
        if (!empty($this->havings)) {
            $sql .= ' HAVING ' . $this->buildHavingClause();
        }
        
        // ORDER BY
        if (!empty($this->orders)) {
            $orderClauses = [];
            foreach ($this->orders as $order) {
                $orderClauses[] = trim($order['column'] . ' ' . $order['direction']);
            }
            $sql .= ' ORDER BY ' . implode(', ', $orderClauses);
        }
        
        // LIMIT
        if ($this->limit !== null) {
            $sql .= " LIMIT {$this->limit}";
        }
        
        // OFFSET
        if ($this->offset > 0) {
            $sql .= " OFFSET {$this->offset}";
        }
        
        return $sql;
    }

    /**
     * Constrói a cláusula WHERE
     * 
     * @return string Cláusula WHERE construída
     */
    protected function buildWhereClause(): string
    {
        $clauses = [];
        
        foreach ($this->wheres as $index => $where) {
            $boolean = $index === 0 ? '' : $where['boolean'] . ' ';
            
            switch ($where['type']) {
                case 'basic':
                    $clauses[] = $boolean . "{$where['column']} {$where['operator']} :{$where['param']}";
                    break;
                    
                case 'in':
                    $clauses[] = $boolean . "{$where['column']} IN (" . implode(', ', $where['values']) . ")";
                    break;
                    
                case 'not_in':
                    $clauses[] = $boolean . "{$where['column']} NOT IN (" . implode(', ', $where['values']) . ")";
                    break;
                    
                case 'between':
                    $clauses[] = $boolean . "{$where['column']} BETWEEN :{$where['min_param']} AND :{$where['max_param']}";
                    break;
                    
                case 'not_between':
                    $clauses[] = $boolean . "{$where['column']} NOT BETWEEN :{$where['min_param']} AND :{$where['max_param']}";
                    break;
                    
                case 'null':
                    $clauses[] = $boolean . "{$where['column']} IS NULL";
                    break;
                    
                case 'not_null':
                    $clauses[] = $boolean . "{$where['column']} IS NOT NULL";
                    break;
                    
                case 'nested':
                    $nestedClause = $where['query']->buildWhereClause();
                    if ($nestedClause) {
                        $clauses[] = $boolean . "({$nestedClause})";
                    }
                    break;
            }
        }
        
        return implode(' ', $clauses);
    }

    /**
     * Constrói a cláusula HAVING
     * 
     * @return string Cláusula HAVING construída
     */
    protected function buildHavingClause(): string
    {
        $clauses = [];
        
        foreach ($this->havings as $index => $having) {
            $boolean = $index === 0 ? '' : $having['boolean'] . ' ';
            $clauses[] = $boolean . "{$having['column']} {$having['operator']} :{$having['param']}";
        }
        
        return implode(' ', $clauses);
    }

    /**
     * Gera nome único para parâmetro
     * 
     * @return string Nome do parâmetro
     */
    protected function generateParamName(): string
    {
        return 'param_' . ++$this->paramCount;
    }

    /**
     * Clona o QueryBuilder atual
     * 
     * @return static Nova instância do QueryBuilder
     */
    public function clone(): self
    {
        return clone $this;
    }

    /**
     * Converte a consulta atual para SQL (para debug)
     * 
     * @return string SQL da consulta
     */
    public function toSql(): string
    {
        return $this->buildSelectSql();
    }

    /**
     * Retorna os bindings atuais (para debug)
     * 
     * @return array Array de parâmetros
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }

    /**
     * Reseta o QueryBuilder para o estado inicial
     * 
     * @return $this
     */
    public function reset(): self
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
        
        return $this;
    }
}