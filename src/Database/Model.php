<?php

/*
|--------------------------------------------------------------------------
| Model Class
|--------------------------------------------------------------------------
|
| This abstract class implements the Active Record pattern, serving as
| the base for all application models. Provides support for CRUD, casts,
| relationships (HasOne, HasMany, BelongsTo, BelongsToMany), soft delete,
| automatic slug generation, hooks/observers, eager loading and much more,
| following the architecture of Laravel's Eloquent ORM.
|
*/

declare(strict_types=1);

namespace Slenix\Database;

use PDO;
use DateTime;
use Slenix\Database\Connection;
use Slenix\Database\Collection;
use Slenix\Database\Relations\BelongsTo;
use Slenix\Database\Relations\BelongsToMany;
use Slenix\Database\Relations\HasMany;
use Slenix\Database\Relations\HasOne;

abstract class Model implements \JsonSerializable
{

    /** @var string Database table name */
    protected string $table = '';

    /** @var string Table primary key */
    protected string $primaryKey = 'id';

    /** @var bool Whether the primary key is auto-incrementing */
    protected bool $incrementing = true;

    /** @var string Primary key type */
    protected string $keyType = 'int';

    /** @var array Model attributes */
    protected array $attributes = [];

    /** @var array Modified (dirty) attributes */
    protected array $dirty = [];

    /** @var PDO PDO instance */
    protected PDO $pdo;

    /** @var array Fields hidden from serialization */
    protected array $hidden = [];

    /** @var array Fields allowed for mass assignment */
    protected array $fillable = [];

    /** @var array Fields blocked from mass assignment */
    protected array $guarded = [];

    /** @var array Type casts for attributes */
    protected array $casts = [];

    /** @var bool Whether the model uses automatic timestamps */
    protected bool $timestamps = true;

    /** @var string The created_at column name */
    protected string $createdAt = 'created_at';

    /** @var string The updated_at column name */
    protected string $updatedAt = 'updated_at';

    /** @var array Loaded relations */
    protected array $relations = [];

    /** @var array Registered hooks (creating, created, etc.) */
    protected static array $hooks = [];

    /** @var array Computed attributes to include in toArray() */
    protected array $appends = [];

    /** @var bool Whether the model uses soft delete */
    protected bool $softDelete = false;

    /** @var string Soft delete column name */
    protected string $deletedAt = 'deleted_at';

    /** @var string|null Source field for the slug (e.g. 'title') */
    protected ?string $slugFrom = null;

    /** @var string Field that will receive the generated slug */
    protected string $slugField = 'slug';

    /** @var bool Whether the slug must be unique in the table */
    protected bool $slugUnique = true;

    /** @var array<string, array<string, callable>> */
    protected static array $globalScopes = [];

    public function __construct(array $attributes = [])
    {
        if (empty($this->table)) {
            // Automatically generate the table name from the class name
            $className = basename(str_replace('\\', '/', static::class));
            $this->table = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $className)) . 's';
        }

        $this->pdo = Connection::getInstance();

        if (!empty($attributes)) {
            $this->fillAttributes($attributes);
        }
    }

    /**
     * Fills the model's internal attributes (without checking fillable)
     */
    protected function fillAttributes(array $attributes): void
    {
        foreach ($attributes as $key => $value) {
            $this->attributes[$key] = $this->castAttribute($key, $value);
        }
    }

    /**
     * Creates a model instance from an array (without saving)
     */
    public static function hydrate(array $attributes): static
    {
        $instance = new static();
        $instance->fillAttributes($attributes);
        return $instance;
    }

    /**
     * Hydrates a collection of arrays into a Collection of models
     */
    public static function hydrateMany(array $rows): Collection
    {
        return new Collection(array_map(fn($row) => static::hydrate($row), $rows));
    }

    public function __set(string $name, $value): void
    {
        $this->setAttribute($name, $value);
    }

    /**
     * Magic getter — resolves attributes, accessors, loaded relations and lazy-loads relations.
     *
     * Resolution order:
     *   1. Accessor method (getXxxAttribute)
     *   2. Raw attribute value (with cast)
     *   3. Already-loaded relation stored in $this->relations
     *   4. Lazy-load: calls the relation method and caches the result
     *
     * Bug fixed: previous code checked instanceof \Slenix\Supports\Database\Relations\Relation
     * (wrong namespace) so lazy loading ALWAYS returned null silently.
     * Also removed the broken ReflectionMethod isRelation detection — simply calling the method
     * and checking instanceof \Slenix\Database\Relations\Relation is sufficient and simpler.
     *
     * @param string $name Property name to resolve.
     * @return mixed Attribute value, relation result, or null when not found.
     */
    public function __get(string $name): mixed
    {
        // ── 1. Accessor: getXxxAttribute ──────────────────────────────────────
        $accessor = 'get' . str_replace(' ', '', ucwords(str_replace('_', ' ', $name))) . 'Attribute';
        if (method_exists($this, $accessor)) {
            return $this->$accessor();
        }

        // ── 2. Direct attribute (with cast) ───────────────────────────────────
        if (array_key_exists($name, $this->attributes)) {
            return $this->castAttribute($name, $this->attributes[$name]);
        }

        // ── 3. Already-loaded relation ────────────────────────────────────────
        if (array_key_exists($name, $this->relations)) {
            return $this->relations[$name];
        }

        // ── 4. Lazy-load via relation method ──────────────────────────────────
        if (method_exists($this, $name)) {
            $result = $this->$name();

            // Correct namespace: Slenix\Database\Relations\Relation
            if ($result instanceof \Slenix\Database\Relations\Relation) {
                $loaded = $result->getResults();
                $this->relations[$name] = $loaded;
                return $loaded;
            }

            // Not a relation method — return value directly (computed property)
            return $result;
        }

        return null;
    }

    public function __isset(string $name): bool
    {
        return isset($this->attributes[$name]) || isset($this->relations[$name]);
    }

    /**
     * Allows calling QueryBuilder methods directly on the static class
     *
     * @example User::latest()->take(10)->get()
     */
    public static function __callStatic(string $method, array $parameters): mixed
    {
        $queryBuilder = static::newQuery();
        if (!method_exists($queryBuilder, $method)) {
            throw new \BadMethodCallException("Method '$method' does not exist on QueryBuilder.");
        }
        return $queryBuilder->$method(...$parameters);
    }

    /**
     * Creates a new QueryBuilder instance for this model
     */
    public static function newQuery(): QueryBuilder
    {
        $instance = new static();
        $query = new QueryBuilder(Connection::getInstance(), $instance->table, static::class);

        // Automatically apply soft delete filter
        if ($instance->softDelete) {
            $query->whereNull($instance->deletedAt);
        }

        return $query;
    }

    /**
     * QueryBuilder without the soft delete filter
     */
    public static function withTrashed(): QueryBuilder
    {
        $instance = new static();
        return new QueryBuilder(Connection::getInstance(), $instance->table, static::class);
    }

    /**
     * QueryBuilder that returns only soft-deleted records
     */
    public static function onlyTrashed(): QueryBuilder
    {
        $instance = new static();
        return (new QueryBuilder(Connection::getInstance(), $instance->table, static::class))
            ->whereNotNull($instance->deletedAt);
    }

    protected function setAttribute(string $key, $value): void
    {
        // Mutator: setXxxAttribute
        $mutator = 'set' . str_replace(' ', '', ucwords(str_replace('_', ' ', $key))) . 'Attribute';
        if (method_exists($this, $mutator)) {
            $this->$mutator($value);
            return;
        }

        $castValue = $this->castAttribute($key, $value);
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
                "Error applying cast '{$castType}' to attribute '{$key}': " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    private function castToInteger($value): int
    {
        if (is_numeric($value))
            return (int) $value;
        throw new \InvalidArgumentException("Value cannot be cast to integer");
    }

    private function castToFloat($value): float
    {
        if (is_numeric($value))
            return (float) $value;
        throw new \InvalidArgumentException("Value cannot be cast to float");
    }

    private function castToBoolean($value): bool
    {
        if (is_bool($value))
            return $value;
        if (is_numeric($value))
            return (bool) $value;
        if (is_string($value))
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        return (bool) $value;
    }

    private function castToString($value): string
    {
        if (is_scalar($value) || (is_object($value) && method_exists($value, '__toString'))) {
            return (string) $value;
        }
        throw new \InvalidArgumentException("Value cannot be cast to string");
    }

    private function castToJson($value): array
    {
        if (is_array($value))
            return $value;
        if (!is_string($value)) {
            throw new \InvalidArgumentException("Value must be a string or array for JSON cast");
        }

        $decoded = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException("Invalid JSON: " . json_last_error_msg());
        }

        return $decoded;
    }

    private function castToDateTime($value): DateTime
    {
        if ($value instanceof DateTime)
            return $value;
        if (is_string($value) && $value !== '')
            return new DateTime($value);
        if (is_int($value))
            return new DateTime('@' . $value);
        throw new \InvalidArgumentException("Value cannot be cast to DateTime");
    }

    /**
     * Automatically generates and sets the slug from $slugFrom
     */
    protected function generateSlug(): void
    {
        if (empty($this->slugFrom))
            return;

        $source = $this->attributes[$this->slugFrom] ?? '';
        if (empty($source))
            return;

        // If the slug is already set and the source field hasn't changed, skip regeneration
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
        $this->dirty[$this->slugField] = $slug;
    }

    /**
     * Converts a string into a URL-friendly slug
     */
    public static function makeSlug(string $text): string
    {
        // Basic transliteration of accented characters
        $transliteration = [
            'à' => 'a',
            'á' => 'a',
            'â' => 'a',
            'ã' => 'a',
            'ä' => 'a',
            'è' => 'e',
            'é' => 'e',
            'ê' => 'e',
            'ë' => 'e',
            'ì' => 'i',
            'í' => 'i',
            'î' => 'i',
            'ï' => 'i',
            'ò' => 'o',
            'ó' => 'o',
            'ô' => 'o',
            'õ' => 'o',
            'ö' => 'o',
            'ù' => 'u',
            'ú' => 'u',
            'û' => 'u',
            'ü' => 'u',
            'ý' => 'y',
            'ÿ' => 'y',
            'ñ' => 'n',
            'ç' => 'c',
            'À' => 'a',
            'Á' => 'a',
            'Â' => 'a',
            'Ã' => 'a',
            'Ä' => 'a',
            'È' => 'e',
            'É' => 'e',
            'Ê' => 'e',
            'Ë' => 'e',
            'Ì' => 'i',
            'Í' => 'i',
            'Î' => 'i',
            'Ï' => 'i',
            'Ò' => 'o',
            'Ó' => 'o',
            'Ô' => 'o',
            'Õ' => 'o',
            'Ö' => 'o',
            'Ù' => 'u',
            'Ú' => 'u',
            'Û' => 'u',
            'Ü' => 'u',
            'Ý' => 'y',
            'Ñ' => 'n',
            'Ç' => 'c',
        ];

        $text = strtr($text, $transliteration);
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9\s\-]/', '', $text);
        $text = preg_replace('/[\s\-]+/', '-', trim($text));

        return $text;
    }

    /**
     * Ensures the slug is unique in the table
     */
    protected function ensureUniqueSlug(string $slug): string
    {
        $original = $slug;
        $count = 1;
        $id = $this->attributes[$this->primaryKey] ?? null;

        while (true) {
            $sql = "SELECT COUNT(*) FROM {$this->table} WHERE {$this->slugField} = :slug"
                . ($id ? " AND {$this->primaryKey} != :id" : '');

            $stmt = $this->pdo->prepare($sql);
            $params = ['slug' => $slug];
            if ($id)
                $params['id'] = $id;
            $stmt->execute($params);

            if ((int) $stmt->fetchColumn() === 0)
                break;

            $slug = $original . '-' . $count++;
        }

        return $slug;
    }

    /**
     * Finds a record by slug
     */
    public static function findBySlug(string $slug): ?static
    {
        $instance = new static();
        return static::where($instance->slugField, '=', $slug)->first();
    }

    /**
     * Fills attributes respecting fillable/guarded
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
     * Fills attributes ignoring fillable/guarded
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
        // Primary key is never fillable
        if ($key === $this->primaryKey)
            return false;

        if (!empty($this->fillable))
            return in_array($key, $this->fillable, true);
        if (!empty($this->guarded))
            return !in_array($key, $this->guarded, true);
        return true;
    }

    /**
     * Creates and saves a new record
     */
    public static function create(array $data): static
    {
        $instance = new static();
        $instance->fill($data);
        $instance->save();
        return $instance;
    }

    /**
     * Updates or creates a record based on search criteria
     *
     * @example User::updateOrCreate(['email' => 'x@x.com'], ['name' => 'John'])
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
     * Finds or creates a record
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
     * Finds or instantiates a record (without saving)
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
     * Saves the model (INSERT or UPDATE)
     */
    public function save(): bool
    {
        $isNew = $this->isNew();

        // Generate slug before saving if configured
        $this->generateSlug();

        static::fireHook($isNew ? 'creating' : 'updating', $this);
        static::fireHook('saving', $this);

        if ($this->timestamps) {
            $now = date('Y-m-d H:i:s');
            if ($isNew) {
                $this->attributes[$this->createdAt] = $now;
                $this->dirty[$this->createdAt] = $now;
            }
            $this->attributes[$this->updatedAt] = $now;
            $this->dirty[$this->updatedAt] = $now;
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
        if (empty($this->attributes))
            return false;

        $data = $this->serializeForDb($this->attributes);
        $columns = array_keys($data);
        $colList = implode(', ', array_map(fn($c) => "`{$c}`", $columns));
        $holders = ':' . implode(', :', $columns);

        $sql = "INSERT INTO `{$this->table}` ({$colList}) VALUES ({$holders})";
        $stmt = $this->pdo->prepare($sql);
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
        if (empty($this->dirty))
            return true;
        if (empty($this->attributes[$this->primaryKey])) {
            throw new \RuntimeException("Cannot update a model without a primary key.");
        }

        $data = $this->serializeForDb($this->dirty);
        $updates = implode(', ', array_map(fn($key) => "`{$key}` = :{$key}", array_keys($data)));
        $sql = "UPDATE `{$this->table}` SET {$updates} WHERE `{$this->primaryKey}` = :__pk__";

        $stmt = $this->pdo->prepare($sql);
        $params = array_merge($data, ['__pk__' => $this->attributes[$this->primaryKey]]);
        $result = $stmt->execute($params);

        if ($result)
            $this->dirty = [];

        return $result;
    }

    /**
     * Serializes attributes for the database (DateTime → string, array → JSON, etc.)
     */
    protected function serializeForDb(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if ($value instanceof DateTime) {
                $castType = strtolower($this->casts[$key] ?? '');
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
     * Updates attributes and saves
     */
    public function update(array $data = []): bool
    {
        if (!empty($data))
            $this->fill($data);
        return $this->save();
    }

    /**
     * Deletes the record (soft or permanent)
     */
    public function delete(): bool
    {
        if (empty($this->attributes[$this->primaryKey]))
            return false;

        static::fireHook('deleting', $this);

        if ($this->softDelete) {
            $sql = "UPDATE `{$this->table}` SET `{$this->deletedAt}` = :deleted_at WHERE `{$this->primaryKey}` = :pk";
            $stmt = $this->pdo->prepare($sql);
            $result = $stmt->execute([
                'deleted_at' => date('Y-m-d H:i:s'),
                'pk' => $this->attributes[$this->primaryKey],
            ]);
        } else {
            $sql = "DELETE FROM `{$this->table}` WHERE `{$this->primaryKey}` = :pk";
            $stmt = $this->pdo->prepare($sql);
            $result = $stmt->execute(['pk' => $this->attributes[$this->primaryKey]]);
        }

        if ($result)
            static::fireHook('deleted', $this);

        return $result;
    }

    /**
     * Forces a hard delete even when softDelete is active
     */
    public function forceDelete(): bool
    {
        if (empty($this->attributes[$this->primaryKey]))
            return false;

        $sql = "DELETE FROM `{$this->table}` WHERE `{$this->primaryKey}` = :pk";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute(['pk' => $this->attributes[$this->primaryKey]]);
    }

    /**
     * Restores a soft-deleted model
     */
    public function restore(): bool
    {
        if (!$this->softDelete || empty($this->attributes[$this->primaryKey]))
            return false;

        $sql = "UPDATE `{$this->table}` SET `{$this->deletedAt}` = NULL WHERE `{$this->primaryKey}` = :pk";
        $stmt = $this->pdo->prepare($sql);
        $result = $stmt->execute(['pk' => $this->attributes[$this->primaryKey]]);

        if ($result) {
            $this->attributes[$this->deletedAt] = null;
        }

        return $result;
    }

    /**
     * Finds a record by ID
     */
    public static function find(int|string $id): ?static
    {
        return static::newQuery()->where(static::make()->primaryKey, '=', $id)->first();
    }

    /**
     * Finds a record by ID or throws an exception
     */
    public static function findOrFail(int|string $id): static
    {
        return static::find($id) ?? throw new \RuntimeException(
            "Record with ID '{$id}' not found in table '" . static::make()->table . "'."
        );
    }

    /**
     * Returns a Collection of models by IDs
     */
    public static function findMany(array $ids): Collection
    {
        if (empty($ids))
            return new Collection();
        return static::newQuery()->whereIn(static::make()->primaryKey, $ids)->get();
    }

    /**
     * Finds the first record matching the given condition
     */
    public static function firstWhere(string $column, mixed $value): ?static
    {
        return static::newQuery()->where($column, '=', $value)->first();
    }

    /**
     * Returns all records as a Collection
     */
    public static function all(): Collection
    {
        return static::newQuery()->get();
    }

    /**
     * Returns the first record
     */
    public static function first(): ?static
    {
        return static::newQuery()->first();
    }

    /**
     * Returns the last record
     */
    public static function last(): ?static
    {
        return static::newQuery()->orderBy(static::make()->primaryKey, 'DESC')->first();
    }

    /**
     * Counts records
     */
    public static function count(): int
    {
        return static::newQuery()->count();
    }

    /**
     * Checks whether any record exists
     */
    public static function exists(): bool
    {
        return static::newQuery()->exists();
    }

    /**
     * Begin a fluent query against the database with a where clause.
     *
     * @param string $column
     * @param mixed $operator
     * @param mixed $value
     * @return QueryBuilder
     */
    public static function where(string $column, mixed $operator = null, mixed $value = null): QueryBuilder
    {
        return static::newQuery()->where($column, $operator, $value);
    }

    /**
     * Add an "or where" clause to the query.
     *
     * @param string $column
     * @param mixed $operator
     * @param mixed $value
     * @return QueryBuilder
     */
    public static function orWhere(string $column, mixed $operator = null, mixed $value = null): QueryBuilder
    {
        return static::newQuery()->orWhere($column, $operator, $value);
    }

    /**
     * Add a "where in" clause to the query.
     *
     * @param string $column
     * @param array $values
     * @return QueryBuilder
     */
    public static function whereIn(string $column, array $values): QueryBuilder
    {
        return static::newQuery()->whereIn($column, $values);
    }

    /**
     * Add a "where not in" clause to the query.
     *
     * @param string $column
     * @param array $values
     * @return QueryBuilder
     */
    public static function whereNotIn(string $column, array $values): QueryBuilder
    {
        return static::newQuery()->whereNotIn($column, $values);
    }

    /**
     * Add a "where between" clause to the query.
     *
     * @param string $column
     * @param mixed $min
     * @param mixed $max
     * @return QueryBuilder
     */
    public static function whereBetween(string $column, mixed $min, mixed $max): QueryBuilder
    {
        return static::newQuery()->whereBetween($column, $min, $max);
    }

    /**
     * Add a "where null" clause to the query.
     *
     * @param string $column
     * @return QueryBuilder
     */
    public static function whereNull(string $column): QueryBuilder
    {
        return static::newQuery()->whereNull($column);
    }

    /**
     * Add a "where not null" clause to the query.
     *
     * @param string $column
     * @return QueryBuilder
     */
    public static function whereNotNull(string $column): QueryBuilder
    {
        return static::newQuery()->whereNotNull($column);
    }

    /**
     * Set the columns to be selected.
     *
     * @param array|string $columns
     * @return QueryBuilder
     */
    public static function select(array|string $columns = ['*']): QueryBuilder
    {
        return static::newQuery()->select($columns);
    }

    /**
     * Force the query to only return distinct results.
     *
     * @return QueryBuilder
     */
    public static function distinct(): QueryBuilder
    {
        return static::newQuery()->distinct();
    }

    /**
     * Add an "order by" clause to the query.
     *
     * @param string $column
     * @param string $direction "ASC" or "DESC"
     * @return QueryBuilder
     */
    public static function orderBy(string $column, string $direction = 'ASC'): QueryBuilder
    {
        return static::newQuery()->orderBy($column, $direction);
    }

    /**
     * Add a descending "order by" clause to the query.
     *
     * @param string $column
     * @return QueryBuilder
     */
    public static function orderByDesc(string $column): QueryBuilder
    {
        return static::newQuery()->orderByDesc($column);
    }

    /**
     * Add an "order by" clause for the most recent record.
     *
     * @param string $column
     * @return QueryBuilder
     */
    public static function latest(string $column = 'created_at'): QueryBuilder
    {
        return static::newQuery()->latest($column);
    }

    /**
     * Add an "order by" clause for the oldest record.
     *
     * @param string $column
     * @return QueryBuilder
     */
    public static function oldest(string $column = 'created_at'): QueryBuilder
    {
        return static::newQuery()->oldest($column);
    }

    /**
     * Add a "group by" clause to the query.
     *
     * @param array|string $columns
     * @return QueryBuilder
     */
    public static function groupBy(array|string $columns): QueryBuilder
    {
        return static::newQuery()->groupBy($columns);
    }

    /**
     * Add a "having" clause to the query.
     *
     * @param string $column
     * @param string $operator
     * @param mixed $value
     * @return QueryBuilder
     */
    public static function having(string $column, string $operator, mixed $value): QueryBuilder
    {
        return static::newQuery()->having($column, $operator, $value);
    }

    /**
     * Set the "limit" value of the query.
     *
     * @param int $limit
     * @return QueryBuilder
     */
    public static function limit(int $limit): QueryBuilder
    {
        return static::newQuery()->limit($limit);
    }

    /**
     * Set the "offset" value of the query.
     *
     * @param int $offset
     * @return QueryBuilder
     */
    public static function offset(int $offset): QueryBuilder
    {
        return static::newQuery()->offset($offset);
    }

    /**
     * Alias for setting limit and offset based on pagination.
     *
     * @param int $perPage
     * @param int $page
     * @return QueryBuilder
     */
    public static function take(int $perPage, int $page = 1): QueryBuilder
    {
        return static::newQuery()->take($perPage, $page);
    }

    /**
     * Add a join clause to the query.
     *
     * @param string $table
     * @param string $first
     * @param string $operator
     * @param string $second
     * @return QueryBuilder
     */
    public static function join(string $table, string $first, string $operator, string $second): QueryBuilder
    {
        return static::newQuery()->join($table, $first, $operator, $second);
    }

    /**
     * Add a left join clause to the query.
     *
     * @param string $table
     * @param string $first
     * @param string $operator
     * @param string $second
     * @return QueryBuilder
     */
    public static function leftJoin(string $table, string $first, string $operator, string $second): QueryBuilder
    {
        return static::newQuery()->leftJoin($table, $first, $operator, $second);
    }

    /**
     * Paginate the query results.
     *
     * @param int $perPage
     * @param int $page
     * @return array{data: array, total: int, perPage: int, currentPage: int, lastPage: int}
     */
    public static function paginate(int $perPage = 15, int $page = 1): array
    {
        return static::newQuery()->paginate($perPage, $page);
    }

    /**
     * Begin querying a relationship with eager loading.
     *
     * @param array|string $relations
     * @return QueryBuilder
     */
    public static function with(array|string $relations): QueryBuilder
    {
        $relationsToLoad = is_array($relations) ? $relations : func_get_args();
        return static::newQuery()->withRelations($relationsToLoad);
    }

    /**
     * Processes records in chunks (ideal for large datasets)
     *
     * @example User::chunk(100, function(Collection $chunk) { ... })
     */
    public static function chunk(int $size, callable $callback): void
    {
        $page = 1;
        do {
            $results = static::newQuery()->take($size, $page)->get();
            if ($results->isEmpty())
                break;
            if ($callback($results) === false)
                break;
            $page++;
        } while ($results->count() === $size);
    }

    /**
     * Applies a local scope (scopeXxx method on the model)
     *
     * @example User::scope('active')->get()
     * @example User::scope('olderThan', 18)->get()
     */
    public static function scope(string $scope, mixed ...$params): QueryBuilder
    {
        $method = 'scope' . ucfirst($scope);
        $instance = new static();
        $query = static::newQuery();

        if (!method_exists($instance, $method)) {
            throw new \BadMethodCallException("Scope '{$scope}' not found on model " . static::class . ".");
        }

        $instance->$method($query, ...$params);
        return $query;
    }

    /**
     * Retrieve all records from the database as an associative array.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function allArray(): array
    {
        return static::newQuery()->getArray();
    }

    /**
     * Retrieve the first record from the database as an associative array.
     *
     * @return array<string, mixed>|null
     */
    public static function firstArray(): ?array
    {
        return static::newQuery()->firstArray();
    }

    /**
     * Register a callback to be executed before a model is created.
     *
     * @param callable(self): void $callback
     * @return void
     */
    public static function creating(callable $callback): void
    {
        static::on('creating', $callback);
    }

    /**
     * Register a callback to be executed after a model is created.
     *
     * @param callable(self): void $callback
     * @return void
     */
    public static function created(callable $callback): void
    {
        static::on('created', $callback);
    }

    /**
     * Register a callback to be executed before a model is updated.
     *
     * @param callable(self): void $callback
     * @return void
     */
    public static function updating(callable $callback): void
    {
        static::on('updating', $callback);
    }

    /**
     * Register a callback to be executed after a model is updated.
     *
     * @param callable(self): void $callback
     * @return void
     */
    public static function updated(callable $callback): void
    {
        static::on('updated', $callback);
    }

    /**
     * Register a callback to be executed before a model is saved (created or updated).
     *
     * @param callable(self): void $callback
     * @return void
     */
    public static function saving(callable $callback): void
    {
        static::on('saving', $callback);
    }

    /**
     * Register a callback to be executed after a model is saved (created or updated).
     *
     * @param callable(self): void $callback
     * @return void
     */
    public static function saved(callable $callback): void
    {
        static::on('saved', $callback);
    }

    /**
     * Register a callback to be executed before a model is deleted.
     *
     * @param callable(self): void $callback
     * @return void
     */
    public static function deleting(callable $callback): void
    {
        static::on('deleting', $callback);
    }

    /**
     * Register a callback to be executed after a model is deleted.
     *
     * @param callable(self): void $callback
     * @return void
     */
    public static function deleted(callable $callback): void
    {
        static::on('deleted', $callback);
    }

    /**
     * Register an event hook for the model.
     *
     * @param string $event The event name (e.g., 'saving', 'deleted').
     * @param callable $callback
     * @return void
     */
    protected static function on(string $event, callable $callback): void
    {
        static::$hooks[static::class][$event][] = $callback;
    }

    /**
     * Fire the given event hooks for the model instance.
     *
     * @param string $event The event name to trigger.
     * @param self $model The model instance to pass to the callbacks.
     * @return void
     */
    protected static function fireHook(string $event, self $model): void
    {
        foreach (static::$hooks[static::class][$event] ?? [] as $callback) {
            $callback($model);
        }
    }

    /**
     * Convert the model instance to an array.
     * * This handles date casting, hidden fields, computed appends, 
     * and nested loaded relationships.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = $this->attributes;

        // Serialize DateTime fields
        foreach ($array as $key => &$value) {
            if ($value instanceof DateTime) {
                $castType = strtolower($this->casts[$key] ?? '');
                $value = $castType === 'date'
                    ? $value->format('Y-m-d')
                    : $value->format('Y-m-d H:i:s');
            }
        }
        unset($value);

        // Remove hidden fields
        foreach ($this->hidden as $hidden) {
            unset($array[$hidden]);
        }

        // Appends (computed attributes)
        foreach ($this->appends as $append) {
            $accessor = 'get' . str_replace(' ', '', ucwords(str_replace('_', ' ', $append))) . 'Attribute';
            if (method_exists($this, $accessor)) {
                $array[$append] = $this->$accessor();
            }
        }

        // Loaded relations
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

    /**
     * Convert the model instance to a JSON string.
     *
     * @param int $options Bitmask of JSON encoding options.
     * @return string
     * @throws \JsonException
     */
    public function toJson(int $options = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE): string
    {
        return json_encode($this->toArray(), $options);
    }

    /**
     * Specify data which should be serialized to JSON.
     * * Compatible with the JsonSerializable interface.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Reload the model's attributes from the database.
     *
     * @return bool True if the model was refreshed, false otherwise.
     */
    public function refresh(): bool
    {
        if (empty($this->attributes[$this->primaryKey]))
            return false;

        $fresh = static::find($this->attributes[$this->primaryKey]);
        if ($fresh) {
            $this->attributes = $fresh->attributes;
            $this->relations = [];
            $this->dirty = [];
            return true;
        }

        return false;
    }

    /**
     * Determine if the model or a given attribute has been modified.
     *
     * @param string|null $key The attribute name to check.
     * @return bool
     */
    public function isDirty(?string $key = null): bool
    {
        return $key === null ? !empty($this->dirty) : array_key_exists($key, $this->dirty);
    }

    /**
     * Determine if the model or a given attribute remains persistent and unchanged.
     *
     * @param string|null $key The attribute name to check.
     * @return bool
     */
    public function isClean(?string $key = null): bool
    {
        return !$this->isDirty($key);
    }

    /**
     * Get the attributes that have been changed since the last sync.
     *
     * @return array
     */
    public function getDirty(): array
    {
        return $this->dirty;
    }

    /**
     * Determine if the model has not yet been persisted to the database.
     *
     * @return bool
     */
    public function isNew(): bool
    {
        return empty($this->attributes[$this->primaryKey]);
    }

    /**
     * Create a copy of the model instance without its primary key.
     *
     * @param array $except List of attributes to exclude from the replication.
     * @return static
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

    /**
     * Get the value of the model's primary key.
     *
     * @return mixed
     */
    public function getKey(): mixed
    {
        return $this->attributes[$this->primaryKey] ?? null;
    }

    /**
     * Get the name of the primary key for the model.
     *
     * @return string
     */
    public function getKeyName(): string
    {
        return $this->primaryKey;
    }

    /**
     * Get the table associated with the model.
     *
     * @return string
     */
    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * Get all the current attributes on the model.
     *
     * @return array
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Get a specific attribute from the model.
     *
     * @param string $key
     * @return mixed
     */
    public function getAttribute(string $key): mixed
    {
        return $this->__get($key);
    }

    /**
     * Internal helper to create a new instance of the model.
     *
     * @return static
     */
    protected static function make(): static
    {
        return new static();
    }

    /**
     * Registers a global scope that is automatically applied to every
     * query for this model.
     *
     * @example
     * // In a ServiceProvider or boot() method:
     * User::addGlobalScope('active', fn(QueryBuilder $q) => $q->where('active', 1));
     *
     * @param string   $name     Unique scope identifier (used to remove it later).
     * @param callable $callback Receives a QueryBuilder instance.
     * @return void
     */
    public static function addGlobalScope(string $name, callable $callback): void
    {
        static::$globalScopes[static::class][$name] = $callback;
    }

    /**
     * Removes a registered global scope by name.
     *
     * @param string $name Scope identifier.
     * @return void
     */
    public static function removeGlobalScope(string $name): void
    {
        unset(static::$globalScopes[static::class][$name]);
    }

    /**
     * Returns a QueryBuilder with the specified global scope(s) disabled.
     *
     * @example User::withoutGlobalScope('active')->get()
     * @example User::withoutGlobalScope(['active', 'verified'])->get()
     *
     * @param string|string[] $scopes Scope name(s) to skip.
     * @return \Slenix\Database\QueryBuilder
     */
    public static function withoutGlobalScope(string|array $scopes): QueryBuilder
    {
        $instance = new static();
        $query = new QueryBuilder(
            Connection::getInstance(),
            $instance->table,
            static::class
        );

        // Apply all scopes except the excluded ones
        foreach (static::$globalScopes[static::class] ?? [] as $name => $callback) {
            if (!in_array($name, (array) $scopes, true)) {
                $callback($query);
            }
        }

        if ($instance->softDelete) {
            $query->whereNull($instance->deletedAt);
        }

        return $query;
    }

    /**
     * Applies all registered global scopes to a QueryBuilder instance.
     * Called internally by newQuery().
     *
     * @param \Slenix\Database\QueryBuilder $query
     * @return void
     */
    protected static function applyGlobalScopes(\Slenix\Database\QueryBuilder $query): void
    {
        foreach (static::$globalScopes[static::class] ?? [] as $callback) {
            $callback($query);
        }
    }

    // NOTE: update newQuery() to call applyGlobalScopes() after construction:
//
// public static function newQuery(): QueryBuilder
// {
//     $instance = new static();
//     $query    = new QueryBuilder(Connection::getInstance(), $instance->table, static::class);
//     static::applyGlobalScopes($query);          // ← add this line
//     if ($instance->softDelete) {
//         $query->whereNull($instance->deletedAt);
//     }
//     return $query;
// }

    // ============================================================
// OBSERVERS
// ============================================================

    /**
     * Registers an observer class for this model.
     *
     * The observer class should define public methods matching event names:
     *   creating, created, updating, updated, saving, saved,
     *   deleting, deleted, restoring, restored
     *
     * @example
     * class UserObserver {
     *     public function creating(User $user): void { ... }
     *     public function deleted(User $user): void { ... }
     * }
     * User::observe(UserObserver::class);
     *
     * @param string|object $observer \Class name or instance.
     * @return void
     */
    public static function observe(string|object $observer): void
    {
        $instance = is_string($observer) ? new $observer() : $observer;
        $events = [
            'creating',
            'created',
            'updating',
            'updated',
            'saving',
            'saved',
            'deleting',
            'deleted',
            'restoring',
            'restored',
        ];

        foreach ($events as $event) {
            if (method_exists($instance, $event)) {
                static::on($event, fn($model) => $instance->$event($model));
            }
        }
    }

    /**
     * Casts a JSON string to a \Slenix\Database\Collection instance.
     * Register with: protected array $casts = ['tags' => 'collection'];
     *
     * @param mixed $value Raw DB value (JSON string or array).
     * @return \Slenix\Database\Collection
     */
    protected function castToCollection(mixed $value): Collection
    {
        if ($value instanceof Collection) {
            return $value;
        }

        $array = is_string($value) ? (json_decode($value, true) ?? []) : (array) $value;
        return new Collection($array);
    }

    /**
     * Casts an integer (cents) to a float (e.g. 1099 → 10.99).
     * Register with: protected array $casts = ['price' => 'money'];
     *
     * @param mixed $value Integer cents.
     * @return float Decimal currency value.
     */
    protected function castToMoney(mixed $value): float
    {
        return round((int) $value / 100, 2);
    }

    /**
     * Returns a subset of the model's attributes by key.
     *
     * @example $user->only(['name', 'email'])
     *
     * @param string[] $keys Attribute names to include.
     * @return array<string, mixed> Filtered attribute map.
     */
    public function only(array $keys): array
    {
        return array_intersect_key($this->toArray(), array_flip($keys));
    }

    /**
     * Returns all attributes except the specified keys.
     *
     * @example $user->except(['password', 'remember_token'])
     *
     * @param string[] $keys Attribute names to exclude.
     * @return array<string, mixed> Filtered attribute map.
     */
    public function except(array $keys): array
    {
        return array_diff_key($this->toArray(), array_flip($keys));
    }

    /**
     * Returns a plain-array snapshot of the current attribute state.
     * Use with diff() to detect what changed between two points in time.
     *
     * @example
     * $snap = $user->snapshot();
     * $user->name = 'New Name';
     * $diff = $user->diff($snap); // ['name' => ['from' => 'Old', 'to' => 'New Name']]
     *
     * @return array<string, mixed> Snapshot of current attributes.
     */
    public function snapshot(): array
    {
        return $this->attributes;
    }

    /**
     * Compares the current attribute state to a previously taken snapshot.
     *
     * @param array $snapshot Previous snapshot from snapshot().
     * @return array<string, array{from: mixed, to: mixed}> Changed attribute map.
     */
    public function diff(array $snapshot): array
    {
        $changes = [];

        foreach ($this->attributes as $key => $current) {
            $previous = $snapshot[$key] ?? null;

            if ($current !== $previous) {
                $changes[$key] = ['from' => $previous, 'to' => $current];
            }
        }

        return $changes;
    }

    /**
     * Checks whether a specific attribute was changed between the last
     * save() and the current state.
     *
     * @param string $key Attribute name.
     * @return bool True when the attribute value differs from its saved state.
     */
    public function wasChanged(string $key): bool
    {
        return array_key_exists($key, $this->changes);
    }

    /**
     * Returns the value an attribute had before it was last changed.
     * Returns null when the attribute was not changed.
     *
     * @param string $key Attribute name.
     * @return mixed Original value, or null.
     */
    public function original(string $key): mixed
    {
        return $this->original[$key] ?? null;
    }

    /**
     * Updates only the `updated_at` timestamp in the database without
     * triggering the full save() lifecycle (no dirty tracking, no hooks).
     *
     * @return bool True on success.
     */
    public function touch(): bool
    {
        if (empty($this->attributes[$this->primaryKey])) {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        $this->attributes[$this->updatedAt] = $now;

        $sql = "UPDATE `{$this->table}` SET `{$this->updatedAt}` = :ts
             WHERE `{$this->primaryKey}` = :pk";
        $stmt = $this->pdo->prepare($sql);

        return $stmt->execute(['ts' => $now, 'pk' => $this->attributes[$this->primaryKey]]);
    }

    /**
     * Increments a numeric column directly in the database.
     * Also updates the in-memory attribute value.
     *
     * @example $post->increment('views')
     * @example $post->increment('score', 5)
     *
     * @param string $column Column to increment.
     * @param int    $by     Amount to add (default 1).
     * @return bool True on success.
     */
    public function increment(string $column, int $by = 1): bool
    {
        if (empty($this->attributes[$this->primaryKey])) {
            return false;
        }

        $sql = "UPDATE `{$this->table}`
             SET `{$column}` = `{$column}` + :by
             WHERE `{$this->primaryKey}` = :pk";
        $stmt = $this->pdo->prepare($sql);
        $ok = $stmt->execute(['by' => $by, 'pk' => $this->attributes[$this->primaryKey]]);

        if ($ok) {
            $this->attributes[$column] = ($this->attributes[$column] ?? 0) + $by;
        }

        return $ok;
    }

    /**
     * Decrements a numeric column directly in the database.
     * Prevents going below zero when $floor is true.
     *
     * @example $product->decrement('stock')
     * @example $product->decrement('stock', 2)
     *
     * @param string $column Column to decrement.
     * @param int    $by     Amount to subtract (default 1).
     * @param bool   $floor  When true, clamps result at 0 (default false).
     * @return bool True on success.
     */
    public function decrement(string $column, int $by = 1, bool $floor = false): bool
    {
        if (empty($this->attributes[$this->primaryKey])) {
            return false;
        }

        $setExpr = $floor
            ? "`{$column}` = GREATEST(0, `{$column}` - :by)"
            : "`{$column}` = `{$column}` - :by";

        $sql = "UPDATE `{$this->table}` SET {$setExpr} WHERE `{$this->primaryKey}` = :pk";
        $stmt = $this->pdo->prepare($sql);
        $ok = $stmt->execute(['by' => $by, 'pk' => $this->attributes[$this->primaryKey]]);

        if ($ok) {
            $current = $this->attributes[$column] ?? 0;
            $this->attributes[$column] = $floor ? max(0, $current - $by) : $current - $by;
        }

        return $ok;
    }

    /**
     * Lazy-loads one or more relations only when they have not already been loaded.
     * Avoids redundant queries when you are not sure if with() was called upstream.
     *
     * @example $user->loadMissing(['profile', 'posts'])
     *
     * @param string|string[] $relations Relation name(s) to load.
     * @return static Fluent (self).
     */
    public function loadMissing(string|array $relations): static
    {
        foreach ((array) $relations as $relation) {
            if (!$this->relationLoaded($relation)) {
                $loaded = $this->$relation;  // triggers __get → lazy load
                // __get already caches in $this->relations via setRelation
            }
        }

        return $this;
    }

    /**
     * Appends a `{relation}_count` attribute to this model by running a
     * COUNT subquery. Does NOT load the related models themselves.
     *
     * @example $user->loadCount('posts') → $user->posts_count
     *
     * @param string|string[] $relations Relation name(s).
     * @return static Fluent (self).
     */
    public function loadCount(string|array $relations): static
    {
        foreach ((array) $relations as $relation) {
            $relationObj = $this->$relation();
            $foreignKey = $relationObj->getForeignKey();
            $relatedTable = $relationObj->getRelated()->getTable();
            $localKey = $relationObj->getLocalKey();
            $parentId = $this->attributes[$localKey] ?? $this->getKey();

            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM `{$relatedTable}` WHERE `{$foreignKey}` = :pk"
            );
            $stmt->execute(['pk' => $parentId]);

            $virtualKey = "{$relation}_count";
            $this->attributes[$virtualKey] = (int) $stmt->fetchColumn();
        }

        return $this;
    }

    /**
     * Truncate the table associated with the model.
     *
     * @return bool
     */
    public static function truncate(): bool
    {
        $instance = new static();
        return $instance->pdo->exec("TRUNCATE TABLE `{$instance->table}`") !== false;
    }

    /**
     * Set a specific relationship on the model.
     *
     * @param string $key
     * @param mixed $value
     * @return static
     */
    public function setRelation(string $key, mixed $value): static
    {
        $this->relations[$key] = $value;
        return $this;
    }

    /**
     * Get a specific relationship from the model.
     *
     * @param string $key
     * @return mixed
     */
    public function getRelation(string $key): mixed
    {
        return $this->relations[$key] ?? null;
    }

    /**
     * Determine if a specific relationship has been loaded.
     *
     * @param string $key
     * @return bool
     */
    public function relationLoaded(string $key): bool
    {
        return array_key_exists($key, $this->relations);
    }

    /**
     * Executes a raw SQL query returning a Collection of models
     * @param string $sql
     * @param array $params
     * @return Collection
     */
    public static function query(string $sql, array $params = []): Collection
    {
        $instance = new static();
        $stmt = $instance->pdo->prepare($sql);
        $stmt->execute($params);

        $results = [];
        while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = static::hydrate($data);
        }

        return new Collection($results);
    }

    /**
     * One-to-one relationship (this model has one related)
     *
     * @example return $this->hasOne(Profile::class);
     * @example return $this->hasOne(Profile::class, 'user_id', 'id');
     */
    protected function hasOne(string $related, ?string $foreignKey = null, ?string $localKey = null): HasOne
    {
        $relatedInstance = new $related();
        $foreignKey = $foreignKey ?? $this->getForeignKeyName();
        $localKey = $localKey ?? $this->primaryKey;
        return new HasOne($relatedInstance, $this, $foreignKey, $localKey);
    }

    /**
     * One-to-many relationship (this model has many related)
     *
     * @example return $this->hasMany(Post::class);
     * @example return $this->hasMany(Post::class, 'author_id', 'id');
     */
    protected function hasMany(string $related, ?string $foreignKey = null, ?string $localKey = null): HasMany
    {
        $relatedInstance = new $related();
        $foreignKey = $foreignKey ?? $this->getForeignKeyName();
        $localKey = $localKey ?? $this->primaryKey;
        return new HasMany($relatedInstance, $this, $foreignKey, $localKey);
    }

    /**
     * Inverse relationship: this model belongs to another
     *
     * @example return $this->belongsTo(User::class);
     * @example return $this->belongsTo(User::class, 'author_id', 'id');
     */
    protected function belongsTo(string $related, ?string $foreignKey = null, ?string $ownerKey = null): BelongsTo
    {
        $relatedInstance = new $related();
        $foreignKey = $foreignKey ?? $relatedInstance->getForeignKeyName();
        $ownerKey = $ownerKey ?? $relatedInstance->getKeyName();
        return new BelongsTo($relatedInstance, $this, $foreignKey, $ownerKey);
    }

    /**
     * Many-to-many relationship via pivot table
     *
     * @example return $this->belongsToMany(Role::class);
     * @example return $this->belongsToMany(Role::class, 'user_roles', 'user_id', 'role_id');
     */
    protected function belongsToMany(
        string $related,
        ?string $pivotTable = null,
        ?string $pivotForeignKey = null,
        ?string $pivotRelatedKey = null,
        ?string $parentKey = null,
        ?string $relatedKey = null
    ): BelongsToMany {
        $relatedInstance = new $related();

        if ($pivotTable === null) {
            $tables = [$this->table, $relatedInstance->getTable()];
            sort($tables);
            $pivotTable = implode('_', $tables);
        }

        $pivotForeignKey = $pivotForeignKey ?? $this->getForeignKeyName();
        $pivotRelatedKey = $pivotRelatedKey ?? $relatedInstance->getForeignKeyName();
        $parentKey = $parentKey ?? $this->primaryKey;
        $relatedKey = $relatedKey ?? $relatedInstance->getKeyName();

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
     * Returns the default FK name for this model (e.g. 'user_id' for User)
     * @return string
     */
    public function getForeignKeyName(): string
    {
        $basename = basename(str_replace('\\', '/', static::class));
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $basename)) . '_id';
    }
}