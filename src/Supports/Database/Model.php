<?php

/*
|--------------------------------------------------------------------------
| Classe Model
|--------------------------------------------------------------------------
|
| Esta classe abstrata implementa o padrão Active Record, servindo como
| base para todos os modelos da aplicação. Oferece suporte a CRUD, casts,
| relacionamentos (HasOne, HasMany, BelongsTo, BelongsToMany), soft delete,
| geração automática de slugs, hooks/observers, eager loading e muito mais,
| seguindo a arquitetura do Eloquent ORM do Laravel.
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Database;

use PDO;
use DateTime;
use Slenix\Supports\Database\Connection;
use Slenix\Supports\Database\Collection;
use Slenix\Supports\Database\Relations\BelongsTo;
use Slenix\Supports\Database\Relations\BelongsToMany;
use Slenix\Supports\Database\Relations\HasMany;
use Slenix\Supports\Database\Relations\HasOne;

abstract class Model implements \JsonSerializable
{
    // =========================================================
    // PROPRIEDADES DO MODELO
    // =========================================================

    /** @var string Nome da tabela no banco de dados */
    protected string $table = '';

    /** @var string Chave primária da tabela */
    protected string $primaryKey = 'id';

    /** @var bool Se a chave primária é auto-incremento */
    protected bool $incrementing = true;

    /** @var string Tipo da chave primária */
    protected string $keyType = 'int';

    /** @var array Atributos do modelo */
    protected array $attributes = [];

    /** @var array Atributos modificados (dirty) */
    protected array $dirty = [];

    /** @var PDO Instância do PDO */
    protected PDO $pdo;

    /** @var array Campos ocultos na serialização */
    protected array $hidden = [];

    /** @var array Campos permitidos para mass assignment */
    protected array $fillable = [];

    /** @var array Campos bloqueados para mass assignment */
    protected array $guarded = [];

    /** @var array Casts de tipo para atributos */
    protected array $casts = [];

    /** @var bool Se o modelo usa timestamps automáticos */
    protected bool $timestamps = true;

    /** @var string Coluna de created_at */
    protected string $createdAt = 'created_at';

    /** @var string Coluna de updated_at */
    protected string $updatedAt = 'updated_at';

    /** @var array Relações carregadas */
    protected array $relations = [];

    /** @var array Hooks registrados (creating, created, etc.) */
    protected static array $hooks = [];

    /** @var array Atributos computados a incluir no toArray() */
    protected array $appends = [];

    /** @var bool Se o modelo usa soft delete */
    protected bool $softDelete = false;

    /** @var string Coluna do soft delete */
    protected string $deletedAt = 'deleted_at';

    // =========================================================
    // SLUG AUTOMÁTICO
    // =========================================================

    /** @var string|null Campo fonte do slug (ex: 'title') */
    protected ?string $slugFrom = null;

    /** @var string Campo que receberá o slug gerado */
    protected string $slugField = 'slug';

    /** @var bool Se o slug deve ser único na tabela */
    protected bool $slugUnique = true;

    // =========================================================
    // CONSTRUTOR
    // =========================================================

    public function __construct(array $attributes = [])
    {
        if (empty($this->table)) {
            // Gera nome da tabela automaticamente a partir do nome da classe
            $className   = basename(str_replace('\\', '/', static::class));
            $this->table = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $className)) . 's';
        }

        $this->pdo = Connection::getInstance();

        if (!empty($attributes)) {
            $this->fillAttributes($attributes);
        }
    }

    // =========================================================
    // HYDRATE / FILL
    // =========================================================

    /**
     * Preenche os atributos internos do modelo (sem verificar fillable)
     */
    protected function fillAttributes(array $attributes): void
    {
        foreach ($attributes as $key => $value) {
            $this->attributes[$key] = $this->castAttribute($key, $value);
        }
    }

    /**
     * Cria uma instância do modelo a partir de um array (sem salvar)
     */
    public static function hydrate(array $attributes): static
    {
        $instance = new static();
        $instance->fillAttributes($attributes);
        return $instance;
    }

    /**
     * Hidrata uma coleção de arrays em uma Collection de modelos
     */
    public static function hydrateMany(array $rows): Collection
    {
        return new Collection(array_map(fn($row) => static::hydrate($row), $rows));
    }

    // =========================================================
    // MAGIC METHODS
    // =========================================================

    public function __set(string $name, $value): void
    {
        $this->setAttribute($name, $value);
    }

    public function __get(string $name): mixed
    {
        // Accessor: getXxxAttribute
        $accessor = 'get' . str_replace(' ', '', ucwords(str_replace('_', ' ', $name))) . 'Attribute';
        if (method_exists($this, $accessor)) {
            return $this->$accessor();
        }

        // Atributo direto
        if (array_key_exists($name, $this->attributes)) {
            return $this->castAttribute($name, $this->attributes[$name]);
        }

        // Relação já carregada
        if (array_key_exists($name, $this->relations)) {
            return $this->relations[$name];
        }

        // Lazy load de relações via ReflectionMethod
        if (method_exists($this, $name)) {
            try {
                $method     = new \ReflectionMethod($this, $name);
                $returnType = $method->getReturnType();

                $isRelation = false;
                if ($returnType instanceof \ReflectionNamedType) {
                    $typeName   = $returnType->getName();
                    $isRelation = str_contains($typeName, 'Relation')
                        || in_array($typeName, [HasOne::class, HasMany::class, BelongsTo::class, BelongsToMany::class]);
                }

                if (!$isRelation) {
                    // Tenta verificar se retorna uma subclasse de Relation
                    $result = $this->$name();
                    if ($result instanceof \Slenix\Supports\Database\Relations\Relation) {
                        $isRelation = true;
                        $loaded = $result->getResults();
                        $this->relations[$name] = $loaded;
                        return $loaded;
                    }
                    return null;
                }

                $this->relations[$name] = $this->$name()->getResults();
                return $this->relations[$name];
            } catch (\ReflectionException $e) {
                return null;
            }
        }

        return null;
    }

    public function __isset(string $name): bool
    {
        return isset($this->attributes[$name]) || isset($this->relations[$name]);
    }

    /**
     * Permite chamar métodos do QueryBuilder diretamente na classe estática
     *
     * @example User::latest()->take(10)->get()
     */
    public static function __callStatic(string $method, array $parameters): mixed
    {
        $queryBuilder = static::newQuery();
        if (!method_exists($queryBuilder, $method)) {
            throw new \BadMethodCallException("Método '$method' não existe no QueryBuilder.");
        }
        return $queryBuilder->$method(...$parameters);
    }

    // =========================================================
    // QUERY BUILDER
    // =========================================================

    /**
     * Cria uma nova instância do QueryBuilder para este modelo
     */
    public static function newQuery(): QueryBuilder
    {
        $instance = new static();
        $query    = new QueryBuilder(Connection::getInstance(), $instance->table, static::class);

        // Aplica soft delete automaticamente
        if ($instance->softDelete) {
            $query->whereNull($instance->deletedAt);
        }

        return $query;
    }

    /**
     * QueryBuilder sem filtro de soft delete
     */
    public static function withTrashed(): QueryBuilder
    {
        $instance = new static();
        return new QueryBuilder(Connection::getInstance(), $instance->table, static::class);
    }

    /**
     * QueryBuilder que retorna apenas registros soft-deletados
     */
    public static function onlyTrashed(): QueryBuilder
    {
        $instance = new static();
        return (new QueryBuilder(Connection::getInstance(), $instance->table, static::class))
            ->whereNotNull($instance->deletedAt);
    }

    // =========================================================
    // CASTS
    // =========================================================

    protected function setAttribute(string $key, $value): void
    {
        // Mutator: setXxxAttribute
        $mutator = 'set' . str_replace(' ', '', ucwords(str_replace('_', ' ', $key))) . 'Attribute';
        if (method_exists($this, $mutator)) {
            $this->$mutator($value);
            return;
        }

        $castValue  = $this->castAttribute($key, $value);
        $hasChanged = !array_key_exists($key, $this->attributes)
            || $this->attributes[$key] !== $castValue;

        $this->attributes[$key] = $castValue;

        if ($key !== $this->primaryKey && $hasChanged) {
            $this->dirty[$key] = $castValue instanceof DateTime
                ? $castValue->format('Y-m-d H:i:s')
                : (is_array($castValue) ? json_encode($castValue) : $castValue);
        }
    }

    protected function castAttribute(string $key, $value): mixed
    {
        if (!isset($this->casts[$key]) || $value === null) {
            return $value;
        }

        $castType = strtolower(trim($this->casts[$key]));

        try {
            return match (true) {
                in_array($castType, ['int', 'integer'])
                    => $this->castToInteger($value),

                in_array($castType, ['float', 'double', 'decimal', 'real'])
                    => $this->castToFloat($value),

                in_array($castType, ['bool', 'boolean'])
                    => $this->castToBoolean($value),

                $castType === 'string'
                    => $this->castToString($value),

                in_array($castType, ['json', 'array', 'collection'])
                    => $this->castToJson($value),

                in_array($castType, ['datetime', 'date', 'timestamp', 'carbon'])
                    => $this->castToDateTime($value),

                default => $value,
            };
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException(
                "Erro ao aplicar cast '{$castType}' ao atributo '{$key}': " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    private function castToInteger($value): int
    {
        if (is_numeric($value)) return (int) $value;
        throw new \InvalidArgumentException("Valor não pode ser convertido para integer");
    }

    private function castToFloat($value): float
    {
        if (is_numeric($value)) return (float) $value;
        throw new \InvalidArgumentException("Valor não pode ser convertido para float");
    }

    private function castToBoolean($value): bool
    {
        if (is_bool($value)) return $value;
        if (is_numeric($value)) return (bool) $value;
        if (is_string($value)) return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        return (bool) $value;
    }

    private function castToString($value): string
    {
        if (is_scalar($value) || (is_object($value) && method_exists($value, '__toString'))) {
            return (string) $value;
        }
        throw new \InvalidArgumentException("Valor não pode ser convertido para string");
    }

    private function castToJson($value): array
    {
        if (is_array($value)) return $value;
        if (!is_string($value)) {
            throw new \InvalidArgumentException("Valor deve ser string ou array para cast JSON");
        }

        $decoded = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException("JSON inválido: " . json_last_error_msg());
        }

        return $decoded;
    }

    private function castToDateTime($value): DateTime
    {
        if ($value instanceof DateTime) return $value;
        if (is_string($value) && $value !== '') return new DateTime($value);
        if (is_int($value)) return new DateTime('@' . $value);
        throw new \InvalidArgumentException("Valor não pode ser convertido para DateTime");
    }

    // =========================================================
    // SLUG
    // =========================================================

    /**
     * Gera e define o slug automaticamente a partir de $slugFrom
     */
    protected function generateSlug(): void
    {
        if (empty($this->slugFrom)) return;

        $source = $this->attributes[$this->slugFrom] ?? '';
        if (empty($source)) return;

        // Se o slug já está definido e não mudou a fonte, não regenera
        if (
            !empty($this->attributes[$this->slugField])
            && !array_key_exists($this->slugFrom, $this->dirty)
        ) {
            return;
        }

        $slug = $this->makeSlug((string) $source);

        if ($this->slugUnique) {
            $slug = $this->ensureUniqueSlug($slug);
        }

        $this->attributes[$this->slugField] = $slug;
        $this->dirty[$this->slugField]       = $slug;
    }

    /**
     * Converte uma string em slug URL-friendly
     */
    public static function makeSlug(string $text): string
    {
        // Transliteração básica de caracteres acentuados
        $transliteration = [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ý' => 'y', 'ÿ' => 'y',
            'ñ' => 'n', 'ç' => 'c',
            'À' => 'a', 'Á' => 'a', 'Â' => 'a', 'Ã' => 'a', 'Ä' => 'a',
            'È' => 'e', 'É' => 'e', 'Ê' => 'e', 'Ë' => 'e',
            'Ì' => 'i', 'Í' => 'i', 'Î' => 'i', 'Ï' => 'i',
            'Ò' => 'o', 'Ó' => 'o', 'Ô' => 'o', 'Õ' => 'o', 'Ö' => 'o',
            'Ù' => 'u', 'Ú' => 'u', 'Û' => 'u', 'Ü' => 'u',
            'Ý' => 'y', 'Ñ' => 'n', 'Ç' => 'c',
        ];

        $text = strtr($text, $transliteration);
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9\s\-]/', '', $text);
        $text = preg_replace('/[\s\-]+/', '-', trim($text));

        return $text;
    }

    /**
     * Garante que o slug seja único na tabela
     */
    protected function ensureUniqueSlug(string $slug): string
    {
        $original = $slug;
        $count    = 1;
        $id       = $this->attributes[$this->primaryKey] ?? null;

        while (true) {
            $sql  = "SELECT COUNT(*) FROM {$this->table} WHERE {$this->slugField} = :slug"
                . ($id ? " AND {$this->primaryKey} != :id" : '');

            $stmt = $this->pdo->prepare($sql);
            $params = ['slug' => $slug];
            if ($id) $params['id'] = $id;
            $stmt->execute($params);

            if ((int) $stmt->fetchColumn() === 0) break;

            $slug = $original . '-' . $count++;
        }

        return $slug;
    }

    /**
     * Encontra um registro pelo slug
     */
    public static function findBySlug(string $slug): ?static
    {
        $instance = new static();
        return static::where($instance->slugField, '=', $slug)->first();
    }

    // =========================================================
    // FILL / MASS ASSIGNMENT
    // =========================================================

    /**
     * Preenche atributos respeitando fillable/guarded
     */
    public function fill(array $data): static
    {
        foreach ($data as $key => $value) {
            if ($this->isFillable($key)) {
                $this->setAttribute($key, $value);
            }
        }
        return $this;
    }

    /**
     * Preenche atributos ignorando fillable/guarded
     */
    public function forceFill(array $data): static
    {
        foreach ($data as $key => $value) {
            $this->setAttribute($key, $value);
        }
        return $this;
    }

    protected function isFillable(string $key): bool
    {
        // Chave primária nunca é fillable
        if ($key === $this->primaryKey) return false;

        if (!empty($this->fillable)) return in_array($key, $this->fillable, true);
        if (!empty($this->guarded))  return !in_array($key, $this->guarded, true);
        return true;
    }

    // =========================================================
    // CRUD
    // =========================================================

    /**
     * Cria e salva um novo registro
     */
    public static function create(array $data): static
    {
        $instance = new static();
        $instance->fill($data);
        $instance->save();
        return $instance;
    }

    /**
     * Atualiza ou cria um registro baseado nos critérios de busca
     *
     * @example User::updateOrCreate(['email' => 'x@x.com'], ['name' => 'João'])
     */
    public static function updateOrCreate(array $search, array $data = []): static
    {
        $query = static::newQuery();
        foreach ($search as $k => $v) {
            $query->where($k, '=', $v);
        }

        $model = $query->first();

        if ($model) {
            $model->fill($data)->save();
            return $model;
        }

        return static::create(array_merge($search, $data));
    }

    /**
     * Busca ou cria um registro
     */
    public static function firstOrCreate(array $search, array $data = []): static
    {
        $query = static::newQuery();
        foreach ($search as $k => $v) {
            $query->where($k, '=', $v);
        }

        return $query->first() ?? static::create(array_merge($search, $data));
    }

    /**
     * Busca ou instancia (sem salvar)
     */
    public static function firstOrNew(array $search, array $data = []): static
    {
        $query = static::newQuery();
        foreach ($search as $k => $v) {
            $query->where($k, '=', $v);
        }

        return $query->first() ?? (new static())->fill(array_merge($search, $data));
    }

    /**
     * Salva o modelo (INSERT ou UPDATE)
     */
    public function save(): bool
    {
        $isNew = $this->isNew();

        // Gera slug antes de salvar se configurado
        $this->generateSlug();

        static::fireHook($isNew ? 'creating' : 'updating', $this);
        static::fireHook('saving', $this);

        if ($this->timestamps) {
            $now = date('Y-m-d H:i:s');
            if ($isNew) {
                $this->attributes[$this->createdAt] = $now;
                $this->dirty[$this->createdAt]       = $now;
            }
            $this->attributes[$this->updatedAt] = $now;
            $this->dirty[$this->updatedAt]       = $now;
        }

        $result = $isNew ? $this->performInsert() : $this->performUpdate();

        if ($result) {
            static::fireHook($isNew ? 'created' : 'updated', $this);
            static::fireHook('saved', $this);
        }

        return $result;
    }

    protected function performInsert(): bool
    {
        if (empty($this->attributes)) return false;

        $data    = $this->serializeForDb($this->attributes);
        $columns = array_keys($data);
        $colList = implode(', ', array_map(fn($c) => "`{$c}`", $columns));
        $holders = ':' . implode(', :', $columns);

        $sql    = "INSERT INTO `{$this->table}` ({$colList}) VALUES ({$holders})";
        $stmt   = $this->pdo->prepare($sql);
        $result = $stmt->execute($data);

        if ($result) {
            $lastId = $this->pdo->lastInsertId();
            if ($lastId) {
                $this->attributes[$this->primaryKey] = $this->incrementing
                    ? (int) $lastId
                    : $lastId;
            }
            $this->dirty = [];
        }

        return $result;
    }

    protected function performUpdate(): bool
    {
        if (empty($this->dirty)) return true;
        if (empty($this->attributes[$this->primaryKey])) {
            throw new \RuntimeException("Não é possível atualizar um modelo sem chave primária.");
        }

        $data    = $this->serializeForDb($this->dirty);
        $updates = implode(', ', array_map(fn($key) => "`{$key}` = :{$key}", array_keys($data)));
        $sql     = "UPDATE `{$this->table}` SET {$updates} WHERE `{$this->primaryKey}` = :__pk__";

        $stmt   = $this->pdo->prepare($sql);
        $params = array_merge($data, ['__pk__' => $this->attributes[$this->primaryKey]]);
        $result = $stmt->execute($params);

        if ($result) $this->dirty = [];

        return $result;
    }

    /**
     * Serializa atributos para o banco (DateTime → string, array → JSON, etc.)
     */
    protected function serializeForDb(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if ($value instanceof DateTime) {
                $castType     = strtolower($this->casts[$key] ?? '');
                $result[$key] = $castType === 'date'
                    ? $value->format('Y-m-d')
                    : $value->format('Y-m-d H:i:s');
            } elseif (is_array($value)) {
                $result[$key] = json_encode($value, JSON_UNESCAPED_UNICODE);
            } elseif (is_bool($value)) {
                $result[$key] = $value ? 1 : 0;
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    /**
     * Atualiza atributos e salva
     */
    public function update(array $data = []): bool
    {
        if (!empty($data)) $this->fill($data);
        return $this->save();
    }

    /**
     * Deleta o registro (soft ou permanente)
     */
    public function delete(): bool
    {
        if (empty($this->attributes[$this->primaryKey])) return false;

        static::fireHook('deleting', $this);

        if ($this->softDelete) {
            $sql  = "UPDATE `{$this->table}` SET `{$this->deletedAt}` = :deleted_at WHERE `{$this->primaryKey}` = :pk";
            $stmt = $this->pdo->prepare($sql);
            $result = $stmt->execute([
                'deleted_at' => date('Y-m-d H:i:s'),
                'pk'         => $this->attributes[$this->primaryKey],
            ]);
        } else {
            $sql    = "DELETE FROM `{$this->table}` WHERE `{$this->primaryKey}` = :pk";
            $stmt   = $this->pdo->prepare($sql);
            $result = $stmt->execute(['pk' => $this->attributes[$this->primaryKey]]);
        }

        if ($result) static::fireHook('deleted', $this);

        return $result;
    }

    /**
     * Força deleção física mesmo com softDelete ativo
     */
    public function forceDelete(): bool
    {
        if (empty($this->attributes[$this->primaryKey])) return false;

        $sql  = "DELETE FROM `{$this->table}` WHERE `{$this->primaryKey}` = :pk";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute(['pk' => $this->attributes[$this->primaryKey]]);
    }

    /**
     * Restaura um modelo soft-deletado
     */
    public function restore(): bool
    {
        if (!$this->softDelete || empty($this->attributes[$this->primaryKey])) return false;

        $sql  = "UPDATE `{$this->table}` SET `{$this->deletedAt}` = NULL WHERE `{$this->primaryKey}` = :pk";
        $stmt = $this->pdo->prepare($sql);
        $result = $stmt->execute(['pk' => $this->attributes[$this->primaryKey]]);

        if ($result) {
            $this->attributes[$this->deletedAt] = null;
        }

        return $result;
    }

    // =========================================================
    // FINDERS
    // =========================================================

    /**
     * Busca pelo ID
     */
    public static function find(int|string $id): ?static
    {
        return static::newQuery()->where(static::make()->primaryKey, '=', $id)->first();
    }

    /**
     * Busca pelo ID ou lança exceção
     */
    public static function findOrFail(int|string $id): static
    {
        return static::find($id) ?? throw new \RuntimeException(
            "Registro com ID '{$id}' não encontrado na tabela '" . static::make()->table . "'."
        );
    }

    /**
     * Retorna Collection de modelos pelos IDs
     */
    public static function findMany(array $ids): Collection
    {
        if (empty($ids)) return new Collection();
        return static::newQuery()->whereIn(static::make()->primaryKey, $ids)->get();
    }

    /**
     * Busca o primeiro registro que satisfaz a condição
     */
    public static function firstWhere(string $column, mixed $value): ?static
    {
        return static::newQuery()->where($column, '=', $value)->first();
    }

    /**
     * Retorna todos os registros como Collection
     */
    public static function all(): Collection
    {
        return static::newQuery()->get();
    }

    /**
     * Retorna o primeiro registro
     */
    public static function first(): ?static
    {
        return static::newQuery()->first();
    }

    /**
     * Retorna o último registro
     */
    public static function last(): ?static
    {
        return static::newQuery()->orderBy(static::make()->primaryKey, 'DESC')->first();
    }

    /**
     * Conta registros
     */
    public static function count(): int
    {
        return static::newQuery()->count();
    }

    /**
     * Verifica se existe algum registro
     */
    public static function exists(): bool
    {
        return static::newQuery()->exists();
    }

    // =========================================================
    // QUERY SCOPES (delegam ao QueryBuilder)
    // =========================================================

    public static function where(string $column, mixed $operator = null, mixed $value = null): QueryBuilder
    {
        return static::newQuery()->where($column, $operator, $value);
    }

    public static function orWhere(string $column, mixed $operator = null, mixed $value = null): QueryBuilder
    {
        return static::newQuery()->orWhere($column, $operator, $value);
    }

    public static function whereIn(string $column, array $values): QueryBuilder
    {
        return static::newQuery()->whereIn($column, $values);
    }

    public static function whereNotIn(string $column, array $values): QueryBuilder
    {
        return static::newQuery()->whereNotIn($column, $values);
    }

    public static function whereBetween(string $column, mixed $min, mixed $max): QueryBuilder
    {
        return static::newQuery()->whereBetween($column, $min, $max);
    }

    public static function whereNull(string $column): QueryBuilder
    {
        return static::newQuery()->whereNull($column);
    }

    public static function whereNotNull(string $column): QueryBuilder
    {
        return static::newQuery()->whereNotNull($column);
    }

    public static function select(array|string $columns = ['*']): QueryBuilder
    {
        return static::newQuery()->select($columns);
    }

    public static function distinct(): QueryBuilder
    {
        return static::newQuery()->distinct();
    }

    public static function orderBy(string $column, string $direction = 'ASC'): QueryBuilder
    {
        return static::newQuery()->orderBy($column, $direction);
    }

    public static function orderByDesc(string $column): QueryBuilder
    {
        return static::newQuery()->orderByDesc($column);
    }

    public static function latest(string $column = 'created_at'): QueryBuilder
    {
        return static::newQuery()->latest($column);
    }

    public static function oldest(string $column = 'created_at'): QueryBuilder
    {
        return static::newQuery()->oldest($column);
    }

    public static function groupBy(array|string $columns): QueryBuilder
    {
        return static::newQuery()->groupBy($columns);
    }

    public static function having(string $column, string $operator, mixed $value): QueryBuilder
    {
        return static::newQuery()->having($column, $operator, $value);
    }

    public static function limit(int $limit): QueryBuilder
    {
        return static::newQuery()->limit($limit);
    }

    public static function offset(int $offset): QueryBuilder
    {
        return static::newQuery()->offset($offset);
    }

    public static function take(int $perPage, int $page = 1): QueryBuilder
    {
        return static::newQuery()->take($perPage, $page);
    }

    public static function join(string $table, string $first, string $operator, string $second): QueryBuilder
    {
        return static::newQuery()->join($table, $first, $operator, $second);
    }

    public static function leftJoin(string $table, string $first, string $operator, string $second): QueryBuilder
    {
        return static::newQuery()->leftJoin($table, $first, $operator, $second);
    }

    public static function paginate(int $perPage = 15, int $page = 1): array
    {
        return static::newQuery()->paginate($perPage, $page);
    }

    public static function with(array|string $relations): QueryBuilder
    {
        $relationsToLoad = is_array($relations) ? $relations : func_get_args();
        return static::newQuery()->withRelations($relationsToLoad);
    }

    /**
     * Processa registros em chunks (ótimo para grandes volumes)
     *
     * @example User::chunk(100, function(Collection $chunk) { ... })
     */
    public static function chunk(int $size, callable $callback): void
    {
        $page = 1;
        do {
            $results = static::newQuery()->take($size, $page)->get();
            if ($results->isEmpty()) break;
            if ($callback($results) === false) break;
            $page++;
        } while ($results->count() === $size);
    }

    /**
     * Aplica um scope local (método scopeXxx no modelo)
     *
     * @example User::scope('active')->get()
     * @example User::scope('olderThan', 18)->get()
     */
    public static function scope(string $scope, mixed ...$params): QueryBuilder
    {
        $method   = 'scope' . ucfirst($scope);
        $instance = new static();
        $query    = static::newQuery();

        if (!method_exists($instance, $method)) {
            throw new \BadMethodCallException("Scope '{$scope}' não encontrado no modelo " . static::class . ".");
        }

        $instance->$method($query, ...$params);
        return $query;
    }

    /**
     * Retorna todos os registros como array associativo
     */
    public static function allArray(): array
    {
        return static::newQuery()->getArray();
    }

    /**
     * Retorna o primeiro como array associativo
     */
    public static function firstArray(): ?array
    {
        return static::newQuery()->firstArray();
    }

    // =========================================================
    // HOOKS (OBSERVERS SIMPLES)
    // =========================================================

    public static function creating(callable $callback): void { static::on('creating', $callback); }
    public static function created(callable $callback): void  { static::on('created',  $callback); }
    public static function updating(callable $callback): void { static::on('updating', $callback); }
    public static function updated(callable $callback): void  { static::on('updated',  $callback); }
    public static function saving(callable $callback): void   { static::on('saving',   $callback); }
    public static function saved(callable $callback): void    { static::on('saved',    $callback); }
    public static function deleting(callable $callback): void { static::on('deleting', $callback); }
    public static function deleted(callable $callback): void  { static::on('deleted',  $callback); }

    protected static function on(string $event, callable $callback): void
    {
        static::$hooks[static::class][$event][] = $callback;
    }

    protected static function fireHook(string $event, self $model): void
    {
        foreach (static::$hooks[static::class][$event] ?? [] as $callback) {
            $callback($model);
        }
    }

    // =========================================================
    // SERIALIZAÇÃO
    // =========================================================

    public function toArray(): array
    {
        $array = $this->attributes;

        // Serializa DateTime
        foreach ($array as $key => &$value) {
            if ($value instanceof DateTime) {
                $castType = strtolower($this->casts[$key] ?? '');
                $value    = $castType === 'date'
                    ? $value->format('Y-m-d')
                    : $value->format('Y-m-d H:i:s');
            }
        }
        unset($value);

        // Remove campos ocultos
        foreach ($this->hidden as $hidden) {
            unset($array[$hidden]);
        }

        // Appends (atributos computados)
        foreach ($this->appends as $append) {
            $accessor = 'get' . str_replace(' ', '', ucwords(str_replace('_', ' ', $append))) . 'Attribute';
            if (method_exists($this, $accessor)) {
                $array[$append] = $this->$accessor();
            }
        }

        // Relações carregadas
        foreach ($this->relations as $key => $value) {
            if ($value instanceof Collection) {
                $array[$key] = $value->toArray();
            } elseif ($value instanceof self) {
                $array[$key] = $value->toArray();
            } elseif (is_array($value)) {
                $array[$key] = array_map(
                    fn($item) => $item instanceof self ? $item->toArray() : (array) $item,
                    $value
                );
            } else {
                $array[$key] = $value;
            }
        }

        return $array;
    }

    public function toJson(int $options = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE): string
    {
        return json_encode($this->toArray(), $options);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    // =========================================================
    // UTILITÁRIOS
    // =========================================================

    /**
     * Recarrega os atributos do modelo do banco
     */
    public function refresh(): bool
    {
        if (empty($this->attributes[$this->primaryKey])) return false;

        $fresh = static::find($this->attributes[$this->primaryKey]);
        if ($fresh) {
            $this->attributes = $fresh->attributes;
            $this->relations  = [];
            $this->dirty      = [];
            return true;
        }

        return false;
    }

    public function isDirty(?string $key = null): bool
    {
        return $key === null ? !empty($this->dirty) : array_key_exists($key, $this->dirty);
    }

    public function isClean(?string $key = null): bool
    {
        return !$this->isDirty($key);
    }

    public function getDirty(): array
    {
        return $this->dirty;
    }

    public function isNew(): bool
    {
        return empty($this->attributes[$this->primaryKey]);
    }

    /**
     * Cria uma cópia do modelo sem a chave primária
     */
    public function replicate(array $except = []): static
    {
        $attributes = $this->attributes;
        unset($attributes[$this->primaryKey]);
        if ($this->timestamps) {
            unset($attributes[$this->createdAt], $attributes[$this->updatedAt]);
        }
        foreach ($except as $field) {
            unset($attributes[$field]);
        }
        return new static($attributes);
    }

    public function getKey(): mixed
    {
        return $this->attributes[$this->primaryKey] ?? null;
    }

    public function getKeyName(): string
    {
        return $this->primaryKey;
    }

    public function getTable(): string
    {
        return $this->table;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getAttribute(string $key): mixed
    {
        return $this->__get($key);
    }

    protected static function make(): static
    {
        return new static();
    }

    /**
     * Trunca a tabela
     */
    public static function truncate(): bool
    {
        $instance = new static();
        return $instance->pdo->exec("TRUNCATE TABLE `{$instance->table}`") !== false;
    }

    public function setRelation(string $key, mixed $value): static
    {
        $this->relations[$key] = $value;
        return $this;
    }

    public function getRelation(string $key): mixed
    {
        return $this->relations[$key] ?? null;
    }

    public function relationLoaded(string $key): bool
    {
        return array_key_exists($key, $this->relations);
    }

    /**
     * Executa uma query SQL bruta retornando Collection de modelos
     */
    public static function query(string $sql, array $params = []): Collection
    {
        $instance = new static();
        $stmt     = $instance->pdo->prepare($sql);
        $stmt->execute($params);

        $results = [];
        while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = static::hydrate($data);
        }

        return new Collection($results);
    }

    // =========================================================
    // RELACIONAMENTOS
    // =========================================================

    /**
     * Relação um-para-um (este modelo tem um relacionado)
     *
     * @example return $this->hasOne(Profile::class);
     * @example return $this->hasOne(Profile::class, 'user_id', 'id');
     */
    protected function hasOne(string $related, ?string $foreignKey = null, ?string $localKey = null): HasOne
    {
        $relatedInstance = new $related();
        $foreignKey      = $foreignKey ?? $this->getForeignKeyName();
        $localKey        = $localKey   ?? $this->primaryKey;
        return new HasOne($relatedInstance, $this, $foreignKey, $localKey);
    }

    /**
     * Relação um-para-muitos (este modelo tem muitos relacionados)
     *
     * @example return $this->hasMany(Post::class);
     * @example return $this->hasMany(Post::class, 'author_id', 'id');
     */
    protected function hasMany(string $related, ?string $foreignKey = null, ?string $localKey = null): HasMany
    {
        $relatedInstance = new $related();
        $foreignKey      = $foreignKey ?? $this->getForeignKeyName();
        $localKey        = $localKey   ?? $this->primaryKey;
        return new HasMany($relatedInstance, $this, $foreignKey, $localKey);
    }

    /**
     * Relação inversa: este modelo pertence a outro
     *
     * @example return $this->belongsTo(User::class);
     * @example return $this->belongsTo(User::class, 'author_id', 'id');
     */
    protected function belongsTo(string $related, ?string $foreignKey = null, ?string $ownerKey = null): BelongsTo
    {
        $relatedInstance = new $related();
        $foreignKey      = $foreignKey ?? $relatedInstance->getForeignKeyName();
        $ownerKey        = $ownerKey   ?? $relatedInstance->getKeyName();
        return new BelongsTo($relatedInstance, $this, $foreignKey, $ownerKey);
    }

    /**
     * Relação muitos-para-muitos via tabela pivot
     *
     * @example return $this->belongsToMany(Role::class);
     * @example return $this->belongsToMany(Role::class, 'user_roles', 'user_id', 'role_id');
     */
    protected function belongsToMany(
        string  $related,
        ?string $pivotTable      = null,
        ?string $pivotForeignKey = null,
        ?string $pivotRelatedKey = null,
        ?string $parentKey       = null,
        ?string $relatedKey      = null
    ): BelongsToMany {
        $relatedInstance = new $related();

        if ($pivotTable === null) {
            $tables = [$this->table, $relatedInstance->getTable()];
            sort($tables);
            $pivotTable = implode('_', $tables);
        }

        $pivotForeignKey = $pivotForeignKey ?? $this->getForeignKeyName();
        $pivotRelatedKey = $pivotRelatedKey ?? $relatedInstance->getForeignKeyName();
        $parentKey       = $parentKey       ?? $this->primaryKey;
        $relatedKey      = $relatedKey      ?? $relatedInstance->getKeyName();

        return new BelongsToMany(
            $relatedInstance,
            $this,
            $pivotTable,
            $pivotForeignKey,
            $pivotRelatedKey,
            $parentKey,
            $relatedKey
        );
    }

    /**
     * Retorna o nome da FK padrão para este modelo (ex: 'user_id' para User)
     */
    public function getForeignKeyName(): string
    {
        $basename = basename(str_replace('\\', '/', static::class));
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $basename)) . '_id';
    }
}