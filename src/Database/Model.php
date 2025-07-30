<?php

/** 
 * |--------------------------------------------------------------------------
 * | SLENIX MODEL - Abstrata para implementação de Active Record Pattern
 * |--------------------------------------------------------------------------
 * |
 * | Fornece funcionalidades básicas de CRUD e consultas para modelos de dados.
 * | Integra QueryBuilder para consultas fluentes e elegantes.
 * | Todas as classes modelo devem estender esta classe e definir a propriedade $table.
 * |
 * | Exemplos de uso:
 * | - User::where('name', '=', 'João')->get()
 * | - User::where('age', '>', 18)->orderBy('name')->limit(10)->get()
 * | - User::select(['name', 'email'])->where('active', '=', 1)->first()
 * | - User::whereIn('id', [1,2,3])->orderBy('created_at', 'DESC')->get()
 * |
 * | @package Slenix\Database
 * | @author Slenix
 * | @version 2.0
 */

declare(strict_types=1);

namespace Slenix\Database;

use PDO, PDOStatement;

abstract class Model
{
    /** @var string Nome da tabela no banco de dados */
    protected string $table = '';

    /** @var string Nome da chave primária */
    protected string $primaryKey = 'id';

    /** @var array Atributos do modelo */
    protected array $attributes = [];

    /** @var array Atributos modificados (dirty attributes) */
    protected array $dirty = [];

    /** @var PDO Instância da conexão com o banco */
    protected PDO $pdo;

    /** @var array Atributos que devem ser ocultados na serialização */
    protected array $hidden = [];

    /** @var array Atributos que podem ser preenchidos em massa */
    protected array $fillable = [];

    /** @var array Atributos protegidos contra preenchimento em massa */
    protected array $guarded = [];

    /** @var array Casts de tipos para atributos */
    protected array $casts = [];

    /** @var bool Se o modelo usa timestamps automáticos */
    protected bool $timestamps = true;

    /** @var string Nome da coluna created_at */
    protected string $createdAt = 'created_at';

    /** @var string Nome da coluna updated_at */
    protected string $updatedAt = 'updated_at';

    /**
     * Construtor da classe
     * 
     * @param array $attributes Atributos iniciais do modelo
     * @throws \Exception Se a propriedade $table não estiver definida
     */
    public function __construct(array $attributes = [])
    {
        if (empty($this->table)) {
            throw new \Exception('A propriedade $table deve ser definida na classe modelo.');
        }

        $this->pdo = Database::getInstance();

        if (!empty($attributes)) {
            $this->fill($attributes);
        }
    }

    /**
     * Setter mágico para definir atributos
     * 
     * @param string $name Nome do atributo
     * @param mixed $value Valor do atributo
     */
    public function __set(string $name, $value): void
    {
        $this->attributes[$name] = $this->castAttribute($name, $value);

        if ($name !== $this->primaryKey) {
            $this->dirty[$name] = $this->attributes[$name];
        }
    }

    /**
     * Getter mágico para obter atributos
     * 
     * @param string $name Nome do atributo
     * @return mixed Valor do atributo ou null se não existir
     */
    public function __get(string $name)
    {
        if (array_key_exists($name, $this->attributes)) {
            return $this->castAttribute($name, $this->attributes[$name]);
        }

        return null;
    }

    /**
     * Método mágico para chamadas estáticas (Query Builder)
     * 
     * @param string $method Nome do método
     * @param array $parameters Parâmetros do método
     * @return mixed
     */
    public static function __callStatic(string $method, array $parameters)
    {
        $queryBuilder = (new static())->newQuery();
        if (!method_exists($queryBuilder, $method)) {
            throw new \BadMethodCallException("Método '$method' não existe no QueryBuilder.");
        }
        return $queryBuilder->$method(...$parameters);
    }

    /**
     * Cria nova instância do QueryBuilder
     * 
     * @return QueryBuilder Nova instância do query builder
     */
    public static function newQuery(): QueryBuilder
    {
        $instance = new static();
        if (empty($instance->table)) {
            throw new \Exception('A propriedade $table deve ser definida na classe modelo.');
        }
        return new QueryBuilder(Database::getInstance(), $instance->table, static::class);
    }

    /**
     * Aplica cast de tipo para um atributo
     * 
     * @param string $key Nome do atributo
     * @param mixed $value Valor do atributo
     * @return mixed Valor com cast aplicado
     */
    protected function castAttribute(string $key, $value)
    {
        if (!isset($this->casts[$key]) || $value === null) {
            return $value;
        }

        try {
            switch ($this->casts[$key]) {
                case 'int':
                case 'integer':
                    return (int) $value;
                case 'float':
                case 'double':
                    return (float) $value;
                case 'string':
                    return (string) $value;
                case 'bool':
                case 'boolean':
                    return (bool) $value;
                case 'array':
                case 'json':
                    if (is_string($value)) {
                        $decoded = json_decode($value, true);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            return $decoded;
                        }
                        throw new \Exception("Falha ao decodificar JSON para o atributo '$key'.");
                    }
                    return $value;
                case 'datetime':
                    if (is_string($value)) {
                        return new \DateTime($value);
                    }
                    return $value;
                default:
                    return $value;
            }
        } catch (\Exception $e) {
            // Logar o erro ou lançar uma exceção específica
            error_log("Erro ao aplicar cast ao atributo '$key': " . $e->getMessage());
            return $value; // Retorna o valor original como fallback
        }
    }

    /**
     * Preenche os atributos do modelo a partir de um array
     * 
     * @param array $data Dados para preencher
     * @return $this
     */
    public function fill(array $data): self
    {
        foreach ($data as $key => $value) {
            if ($this->isFillable($key)) {
                $this->attributes[$key] = $this->castAttribute($key, $value);
            }
        }

        $this->dirty = [];
        return $this;
    }

    /**
     * Verifica se um atributo pode ser preenchido em massa
     * 
     * @param string $key Nome do atributo
     * @return bool True se pode ser preenchido
     */
    protected function isFillable(string $key): bool
    {
        // Se fillable está definido, só permite os campos listados
        if (!empty($this->fillable)) {
            return in_array($key, $this->fillable);
        }

        // Se guarded está definido, bloqueia os campos listados
        if (!empty($this->guarded)) {
            return !in_array($key, $this->guarded);
        }

        // Por padrão, permite todos os campos
        return true;
    }

    /**
     * Cria uma nova instância do modelo com os dados fornecidos
     * 
     * @param array $data Dados para criar o modelo
     * @return static Nova instância do modelo
     */
    public static function create(array $data): self
    {
        $instance = new static();
        $instance->fill($data);
        $instance->save();
        return $instance;
    }

    /**
     * Salva o modelo no banco de dados (insert ou update)
     * 
     * @return bool True se salvou com sucesso, false caso contrário
     */
    public function save(): bool
    {
        if ($this->timestamps) {
            $now = date('Y-m-d H:i:s');

            if (empty($this->attributes[$this->primaryKey])) {
                // Novo registro - define created_at
                $this->attributes[$this->createdAt] = $now;
            }

            // Sempre atualiza updated_at
            $this->attributes[$this->updatedAt] = $now;
            $this->dirty[$this->updatedAt] = $now;
        }

        if (empty($this->attributes[$this->primaryKey])) {
            return $this->insert();
        }

        return $this->update();
    }

    /**
     * Insere um novo registro no banco de dados
     * 
     * @return bool True se inseriu com sucesso, false caso contrário
     */
    protected function insert(): bool
    {
        if (empty($this->attributes)) {
            return false;
        }

        $columns = implode(',', array_keys($this->attributes));
        $placeholders = ':' . implode(',:', array_keys($this->attributes));

        $sql = "INSERT INTO {$this->table} ($columns) VALUES ($placeholders)";
        $stmt = $this->pdo->prepare($sql);
        $result = $stmt->execute($this->attributes);

        if ($result) {
            $this->attributes[$this->primaryKey] = $this->pdo->lastInsertId();
            $this->dirty = [];
        }

        return $result;
    }

    /**
     * Atualiza o registro existente no banco de dados
     * 
     * @return bool True se atualizou com sucesso, false caso contrário
     */
    public function update(): bool
    {
        if (empty($this->dirty) || empty($this->attributes[$this->primaryKey])) {
            return true;
        }

        $updates = array_map(fn($key) => "$key = :$key", array_keys($this->dirty));
        $sql = "UPDATE {$this->table} SET " . implode(',', $updates) . " WHERE {$this->primaryKey} = :{$this->primaryKey}";

        $stmt = $this->pdo->prepare($sql);
        $params = array_merge($this->dirty, [$this->primaryKey => $this->attributes[$this->primaryKey]]);
        $result = $stmt->execute($params);

        if ($result) {
            $this->attributes = array_merge($this->attributes, $this->dirty);
            $this->dirty = [];
        }

        return $result;
    }

    /**
     * Deleta o registro do banco de dados
     * 
     * @return bool True se deletou com sucesso, false caso contrário
     */
    public function delete(): bool
    {
        if (empty($this->attributes[$this->primaryKey])) {
            return false;
        }

        $sql = "DELETE FROM {$this->table} WHERE {$this->primaryKey} = :{$this->primaryKey}";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$this->primaryKey => $this->attributes[$this->primaryKey]]);
    }

    /**
     * Busca um registro por ID
     * 
     * @param mixed $id ID do registro
     * @return static|null Instância do modelo ou null se não encontrado
     */
    public static function find($id): ?self
    {
        return static::where(static::make()->primaryKey, '=', $id)->first();
    }

    /**
     * Busca um registro por ID ou falha
     * 
     * @param mixed $id ID do registro
     * @return static Instância do modelo
     * @throws \Exception Se o registro não for encontrado
     */
    public static function findOrFail($id): self
    {
        $result = static::find($id);

        if ($result === null) {
            throw new \Exception("Registro com ID '$id' não encontrado na tabela.");
        }

        return $result;
    }

    /**
     * Busca múltiplos registros por IDs
     * 
     * @param array $ids Array de IDs
     * @return array Array de instâncias do modelo
     */
    public static function findMany(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        return static::whereIn(static::make()->primaryKey, $ids)->get();
    }

    /**
     * Busca todos os registros da tabela
     * 
     * @return array Array de instâncias do modelo
     */
    public static function all(): array
    {
        return static::newQuery()->get();
    }

    /**
     * Busca o primeiro registro da tabela
     * 
     * @return static|null Instância do modelo ou null
     */
    public static function first(): ?self
    {
        return static::newQuery()->first();
    }

    /**
     * Busca o último registro da tabela (ordenado por chave primária)
     * 
     * @return static|null Instância do modelo ou null
     */
    public static function last(): ?self
    {
        $instance = static::make();
        return static::orderBy($instance->primaryKey, 'DESC')->first();
    }

    /**
     * Conta o número total de registros na tabela
     * 
     * @return int Número de registros
     */
    public static function count(): int
    {
        return static::newQuery()->count();
    }

    /**
     * Verifica se existe algum registro na tabela
     * 
     * @return bool True se existir pelo menos um registro
     */
    public static function exists(): bool
    {
        return static::count() > 0;
    }

    /**
     * Cria uma nova instância do modelo sem salvar
     * 
     * @return static Nova instância
     */
    protected static function make(): self
    {
        return new static();
    }

    /**
     * Busca registros com condições WHERE
     * 
     * @param string $column Nome da coluna
     * @param string|null $operator Operador de comparação
     * @param mixed $value Valor para comparação
     * @return QueryBuilder Instância do QueryBuilder
     */
    public static function where(string $column, $operator = null, $value = null): QueryBuilder
    {
        return static::newQuery()->where($column, $operator, $value);
    }

    /**
     * Busca registros com condição OR WHERE
     * 
     * @param string $column Nome da coluna
     * @param string|null $operator Operador de comparação
     * @param mixed $value Valor para comparação
     * @return QueryBuilder
     */
    public static function orWhere(string $column, $operator = null, $value = null): QueryBuilder
    {
        return static::newQuery()->orWhere($column, $operator, $value);
    }

    /**
     * Busca registros com WHERE IN
     * 
     * @param string $column Nome da coluna
     * @param array $values Array de valores
     * @return QueryBuilder
     */
    public static function whereIn(string $column, array $values): QueryBuilder
    {
        return static::newQuery()->whereIn($column, $values);
    }

    /**
     * Busca registros com WHERE NOT IN
     * 
     * @param string $column Nome da coluna
     * @param array $values Array de valores
     * @return QueryBuilder
     */
    public static function whereNotIn(string $column, array $values): QueryBuilder
    {
        return static::newQuery()->whereNotIn($column, $values);
    }

    /**
     * Busca registros com WHERE BETWEEN
     * 
     * @param string $column Nome da coluna
     * @param mixed $min Valor mínimo
     * @param mixed $max Valor máximo
     * @return QueryBuilder
     */
    public static function whereBetween(string $column, $min, $max): QueryBuilder
    {
        return static::newQuery()->whereBetween($column, $min, $max);
    }

    /**
     * Busca registros com WHERE IS NULL
     * 
     * @param string $column Nome da coluna
     * @return QueryBuilder
     */
    public static function whereNull(string $column): QueryBuilder
    {
        return static::newQuery()->whereNull($column);
    }

    /**
     * Busca registros com WHERE IS NOT NULL
     * 
     * @param string $column Nome da coluna
     * @return QueryBuilder
     */
    public static function whereNotNull(string $column): QueryBuilder
    {
        return static::newQuery()->whereNotNull($column);
    }

    /**
     * Define colunas para seleção
     * 
     * @param array|string $columns Colunas para selecionar
     * @return QueryBuilder
     */
    public static function select($columns = ['*']): QueryBuilder
    {
        return static::newQuery()->select($columns);
    }

    /**
     * Adiciona DISTINCT à consulta
     * 
     * @return QueryBuilder
     */
    public static function distinct(): QueryBuilder
    {
        return static::newQuery()->distinct();
    }

    /**
     * Adiciona ORDER BY à consulta
     * 
     * @param string $column Nome da coluna
     * @param string $direction Direção (ASC/DESC)
     * @return QueryBuilder
     */
    public static function orderBy(string $column, string $direction = 'ASC'): QueryBuilder
    {
        return static::newQuery()->orderBy($column, $direction);
    }

    /**
     * Adiciona ORDER BY descendente
     * 
     * @param string $column Nome da coluna
     * @return QueryBuilder
     */
    public static function orderByDesc(string $column): QueryBuilder
    {
        return static::newQuery()->orderByDesc($column);
    }

    /**
     * Adiciona GROUP BY à consulta
     * 
     * @param string|array $columns Colunas para agrupamento
     * @return QueryBuilder
     */
    public static function groupBy($columns): QueryBuilder
    {
        return static::newQuery()->groupBy($columns);
    }

    /**
     * Adiciona HAVING à consulta
     * 
     * @param string $column Nome da coluna
     * @param string $operator Operador de comparação
     * @param mixed $value Valor para comparação
     * @return QueryBuilder
     */
    public static function having(string $column, string $operator, $value): QueryBuilder
    {
        return static::newQuery()->having($column, $operator, $value);
    }

    /**
     * Define limite de registros
     * 
     * @param int $limit Número máximo de registros
     * @return QueryBuilder
     */
    public static function limit(int $limit): QueryBuilder
    {
        return static::newQuery()->limit($limit);
    }

    /**
     * Define offset para paginação
     * 
     * @param int $offset Número de registros para pular
     * @return QueryBuilder
     */
    public static function offset(int $offset): QueryBuilder
    {
        return static::newQuery()->offset($offset);
    }

    /**
     * Atalho para limit e offset (paginação)
     * 
     * @param int $perPage Registros por página
     * @param int $page Página atual (inicia em 1)
     * @return QueryBuilder
     */
    public static function take(int $perPage, int $page = 1): QueryBuilder
    {
        return static::newQuery()->take($perPage, $page);
    }

    /**
     * Adiciona INNER JOIN à consulta
     * 
     * @param string $table Tabela para join
     * @param string $first Primeira coluna
     * @param string $operator Operador de comparação
     * @param string $second Segunda coluna
     * @return QueryBuilder
     */
    public static function join(string $table, string $first, string $operator, string $second): QueryBuilder
    {
        return static::newQuery()->join($table, $first, $operator, $second);
    }

    /**
     * Adiciona LEFT JOIN à consulta
     * 
     * @param string $table Tabela para join
     * @param string $first Primeira coluna
     * @param string $operator Operador de comparação
     * @param string $second Segunda coluna
     * @return QueryBuilder
     */
    public static function leftJoin(string $table, string $first, string $operator, string $second): QueryBuilder
    {
        return static::newQuery()->leftJoin($table, $first, $operator, $second);
    }

    /**
     * Implementa paginação com metadados
     * 
     * @param int $perPage Registros por página
     * @param int $page Página atual
     * @return array Array com dados paginados e metadados
     */
    public static function paginate(int $perPage = 15, int $page = 1): array
    {
        return static::newQuery()->paginate($perPage, $page);
    }

    /**
     * Converte o modelo para array
     * 
     * @return array Array com todos os atributos (exceto os ocultos)
     */
    public function toArray(): array
    {
        $array = $this->attributes;

        // Remove atributos ocultos
        foreach ($this->hidden as $hidden) {
            unset($array[$hidden]);
        }

        return $array;
    }

    /**
     * Converte o modelo para JSON
     * 
     * @return string JSON string
     */
    public function toJson(): string
    {
        return json_encode($this->toArray());
    }

    /**
     * Recarrega o modelo do banco de dados
     * 
     * @return bool True se recarregou com sucesso
     */
    public function refresh(): bool
    {
        if (empty($this->attributes[$this->primaryKey])) {
            return false;
        }

        $fresh = static::find($this->attributes[$this->primaryKey]);

        if ($fresh) {
            $this->attributes = $fresh->attributes;
            $this->dirty = [];
            return true;
        }

        return false;
    }

    /**
     * Verifica se o modelo foi modificado
     * 
     * @return bool True se há modificações não salvas
     */
    public function isDirty(): bool
    {
        return !empty($this->dirty);
    }

    /**
     * Obtém os atributos modificados
     * 
     * @return array Array com atributos modificados
     */
    public function getDirty(): array
    {
        return $this->dirty;
    }

    /**
     * Clona o modelo (replica sem chave primária)
     * 
     * @return static Nova instância com os mesmos atributos
     */
    public function replicate(): self
    {
        $attributes = $this->attributes;
        unset($attributes[$this->primaryKey]); // Remove a chave primária

        return new static($attributes);
    }

    /**
     * Obtém a chave primária do modelo
     * 
     * @return mixed Valor da chave primária
     */
    public function getKey()
    {
        return $this->attributes[$this->primaryKey] ?? null;
    }

    /**
     * Obtém o nome da chave primária
     * 
     * @return string Nome da chave primária
     */
    public function getKeyName(): string
    {
        return $this->primaryKey;
    }

    /**
     * Obtém o nome da tabela
     * 
     * @return string Nome da tabela
     */
    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * Executa uma consulta personalizada
     * 
     * @param string $sql Query SQL
     * @param array $params Parâmetros para a query
     * @return array Array de instâncias do modelo
     */
    public static function query(string $sql, array $params = []): array
    {
        $instance = new static();
        $stmt = $instance->pdo->prepare($sql);
        $stmt->execute($params);

        $results = [];
        while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $model = new static();
            $model->fill($data);
            $results[] = $model;
        }

        return $results;
    }

    /**
     * Trunca a tabela (remove todos os registros)
     * 
     * @return bool True se executou com sucesso
     */
    public static function truncate(): bool
    {
        $instance = new static();
        $sql = "TRUNCATE TABLE {$instance->table}";
        return $instance->pdo->exec($sql) !== false;
    }
}