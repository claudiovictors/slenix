<?php

declare(strict_types=1);

namespace Slenix\Database;

use PDO, PDOStatement;

abstract class Model {
    protected string $table = '';
    protected string $primaryKey = 'id';
    protected array $attributes = [];
    protected array $dirty = [];
    protected $pdo;

    public function __construct() {
        if (empty($this->table)) {
            throw new \Exception('A propriedade $table deve ser definida na classe modelo.');
        }
        $this->pdo = Database::getInstance();
    }

    public function __set($name, $value) {
        $this->attributes[$name] = $value;
        $this->dirty[$name] = $value;
    }

    public function __get($name) {
        return $this->attributes[$name] ?? null;
    }

    public static function create(array $data): self {
        $instance = new static();
        foreach ($data as $key => $value) {
            $instance->$key = $value;
        }
        $instance->save();
        return $instance;
    }

    public function save(): bool {
        if (empty($this->attributes[$this->primaryKey])) {
            return $this->insert();
        }
        return $this->update();
    }

    protected function insert(): bool {
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

    public function update(): bool {
        if (empty($this->dirty)) {
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

    // Deletar registro
    public function delete(): bool {
        if (empty($this->attributes[$this->primaryKey])) {
            return false;
        }
        $sql = "DELETE FROM {$this->table} WHERE {$this->primaryKey} = :{$this->primaryKey}";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$this->primaryKey => $this->attributes[$this->primaryKey]]);
    }

    // Buscar por ID
    public static function find($id): ?self {
        $instance = new static();
        $stmt = $instance->pdo->prepare("SELECT * FROM {$instance->table} WHERE {$instance->primaryKey} = :id");
        $stmt->execute(['id' => $id]);
        $data = $stmt->fetch();
        if ($data) {
            $instance->fill((array) $data);
            return $instance;
        }
        return null;
    }

    // Buscar todos os registros
    public static function all(): array {
        $instance = new static();
        $stmt = $instance->pdo->query("SELECT * FROM {$instance->table}");
        $results = [];
        while ($data = $stmt->fetch()) {
            $model = new static();
            $model->fill((array) $data);
            $results[] = $model;
        }
        return $results;
    }

    // Consulta com condições
    public static function where(string $column, string $operator, $value): array {
        $instance = new static();
        $sql = "SELECT * FROM {$instance->table} WHERE $column $operator :value";
        $stmt = $instance->pdo->prepare($sql);
        $stmt->execute(['value' => $value]);
        $results = [];
        while ($data = $stmt->fetch()) {
            $model = new static();
            $model->fill((array) $data);
            $results[] = $model;
        }
        return $results;
    }

    // Primeiro registro com condição
    public static function firstWhere(string $column, string $operator, $value): ?self {
        $instance = new static();
        $sql = "SELECT * FROM {$instance->table} WHERE $column $operator :value LIMIT 1";
        $stmt = $instance->pdo->prepare($sql);
        $stmt->execute(['value' => $value]);
        $data = $stmt->fetch();
        if ($data) {
            $instance->fill((array) $data);
            return $instance;
        }
        return null;
    }

    // Primeiro registro da tabela
    public static function first(): ?self {
        $instance = new static();
        $stmt = $instance->pdo->query("SELECT * FROM {$instance->table} LIMIT 1");
        $data = $stmt->fetch();
        if ($data) {
            $instance->fill((array) $data);
            return $instance;
        }
        return null;
    }

    // Executar consulta personalizada
    public static function query(string $sql, array $params = []): array {
        $instance = new static();
        $stmt = $instance->pdo->prepare($sql);
        $stmt->execute($params);
        $results = [];
        while ($data = $stmt->fetch()) {
            $model = new static();
            $model->fill((array) $data);
            $results[] = $model;
        }
        return $results;
    }

    // Preencher atributos a partir de um array
    protected function fill(array $data): void {
        $this->attributes = $data;
        $this->dirty = [];
    }

    // Obter todos os atributos
    public function toArray(): array {
        return $this->attributes;
    }

    // Paginação simples
    public static function paginate(int $perPage = 10, int $page = 1): array {
        $instance = new static();
        $offset = ($page - 1) * $perPage;
        $sql = "SELECT * FROM {$instance->table} LIMIT :limit OFFSET :offset";
        $stmt = $instance->pdo->prepare($sql);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $results = [];
        while ($data = $stmt->fetch()) {
            $model = new static();
            $model->fill((array) $data);
            $results[] = $model;
        }
        $total = $instance->pdo->query("SELECT COUNT(*) FROM {$instance->table}")->fetchColumn();
        return [
            'data' => $results,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => ceil($total / $perPage),
        ];
    }
}