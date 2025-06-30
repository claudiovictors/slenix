<?php

/** 
 * |--------------------------------------------------------------------------
 * | SLENIX MODEL - abstrata para implementação de Active Record Pattern
 * |--------------------------------------------------------------------------
 * |
 * | Fornece funcionalidades básicas de CRUD e consultas para modelos de dados.
 * | Todas as classes modelo devem estender esta classe e definir a propriedade $table.
 * |
 * | @package Slenix\Database
 * | @author Slenix
 * | @version 1.1
*/


declare(strict_types=1);

namespace Slenix\Database;

use PDO, PDOStatement;

abstract class Model {
    /** @var string Nome da tabela no banco de dados */
    protected string $table = '';
    
    /** @var string Nome da chave primária */
    protected string $primaryKey = 'id';
    
    /** @var array Atributos do modelo */
    protected array $attributes = [];
    
    /** @var array Atributos modificados (dirty attributes) */
    protected array $dirty = [];
    
    /** @var PDO Instância da conexão com o banco */
    protected $pdo;

    /**
     * Construtor da classe
     * 
     * @throws \Exception Se a propriedade $table não estiver definida
     */
    public function __construct() {
        if (empty($this->table)) {
            throw new \Exception('A propriedade $table deve ser definida na classe modelo.');
        }
        $this->pdo = Database::getInstance();
    }

    /**
     * Setter mágico para definir atributos
     * 
     * @param string $name Nome do atributo
     * @param mixed $value Valor do atributo
     */
    public function __set($name, $value) {
        $this->attributes[$name] = $value;
        $this->dirty[$name] = $value;
    }

    /**
     * Getter mágico para obter atributos
     * 
     * @param string $name Nome do atributo
     * @return mixed Valor do atributo ou null se não existir
     */
    public function __get($name) {
        return $this->attributes[$name] ?? null;
    }

    /**
     * Cria uma nova instância do modelo com os dados fornecidos
     * 
     * @param array $data Dados para criar o modelo
     * @return static Nova instância do modelo
     */
    public static function create(array $data): self {
        $instance = new static();
        foreach ($data as $key => $value) {
            $instance->$key = $value;
        }
        $instance->save();
        return $instance;
    }

    /**
     * Salva o modelo no banco de dados (insert ou update)
     * 
     * @return bool True se salvou com sucesso, false caso contrário
     */
    public function save(): bool {
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

    /**
     * Atualiza o registro existente no banco de dados
     * 
     * @return bool True se atualizou com sucesso, false caso contrário
     */
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

    /**
     * Deleta o registro do banco de dados
     * 
     * @return bool True se deletou com sucesso, false caso contrário
     */
    public function delete(): bool {
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

    /**
     * Busca múltiplos registros por IDs
     * 
     * @param array $ids Array de IDs
     * @return array Array de instâncias do modelo
     */
    public static function findMany(array $ids): array {
        if (empty($ids)) {
            return [];
        }
        
        $instance = new static();
        $placeholders = str_repeat('?,', count($ids) - 1) . '?';
        $sql = "SELECT * FROM {$instance->table} WHERE {$instance->primaryKey} IN ($placeholders)";
        $stmt = $instance->pdo->prepare($sql);
        $stmt->execute($ids);
        
        $results = [];
        while ($data = $stmt->fetch()) {
            $model = new static();
            $model->fill((array) $data);
            $results[] = $model;
        }
        return $results;
    }

    /**
     * Busca todos os registros da tabela
     * 
     * @return array Array de instâncias do modelo
     */
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

    /**
     * Conta o número total de registros na tabela
     * 
     * @return int Número de registros
     */
    public static function count(): int {
        $instance = new static();
        $stmt = $instance->pdo->query("SELECT COUNT(*) FROM {$instance->table}");
        return (int) $stmt->fetchColumn();
    }

    /**
     * Conta registros com condição
     * 
     * @param string $column Nome da coluna
     * @param string $operator Operador (=, >, <, etc.)
     * @param mixed $value Valor para comparação
     * @return int Número de registros
     */
    public static function countWhere(string $column, string $operator, $value): int {
        $instance = new static();
        $sql = "SELECT COUNT(*) FROM {$instance->table} WHERE $column $operator :value";
        $stmt = $instance->pdo->prepare($sql);
        $stmt->execute(['value' => $value]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Verifica se existe algum registro na tabela
     * 
     * @return bool True se existir pelo menos um registro
     */
    public static function exists(): bool {
        return static::count() > 0;
    }

    /**
     * Verifica se existe registro com condição
     * 
     * @param string $column Nome da coluna
     * @param string $operator Operador
     * @param mixed $value Valor
     * @return bool True se existir
     */
    public static function existsWhere(string $column, string $operator, $value): bool {
        return static::countWhere($column, $operator, $value) > 0;
    }

    /**
     * Busca registros com condições
     * 
     * @param string $column Nome da coluna
     * @param string $operator Operador
     * @param mixed $value Valor
     * @return array Array de instâncias do modelo
     */
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

    /**
     * Busca registros com múltiplas condições WHERE
     * 
     * @param array $conditions Array de condições [['column', 'operator', 'value'], ...]
     * @param string $logic Lógica entre condições (AND/OR)
     * @return array Array de instâncias do modelo
     */
    public static function whereMultiple(array $conditions, string $logic = 'AND'): array {
        if (empty($conditions)) {
            return [];
        }

        $instance = new static();
        $whereClauses = [];
        $params = [];
        
        foreach ($conditions as $i => $condition) {
            [$column, $operator, $value] = $condition;
            $paramName = "param_$i";
            $whereClauses[] = "$column $operator :$paramName";
            $params[$paramName] = $value;
        }
        
        $sql = "SELECT * FROM {$instance->table} WHERE " . implode(" $logic ", $whereClauses);
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

    /**
     * Busca registros com valores em um array (WHERE IN)
     * 
     * @param string $column Nome da coluna
     * @param array $values Array de valores
     * @return array Array de instâncias do modelo
     */
    public static function whereIn(string $column, array $values): array {
        if (empty($values)) {
            return [];
        }
        
        $instance = new static();
        $placeholders = str_repeat('?,', count($values) - 1) . '?';
        $sql = "SELECT * FROM {$instance->table} WHERE $column IN ($placeholders)";
        $stmt = $instance->pdo->prepare($sql);
        $stmt->execute($values);
        
        $results = [];
        while ($data = $stmt->fetch()) {
            $model = new static();
            $model->fill((array) $data);
            $results[] = $model;
        }
        return $results;
    }

    /**
     * Busca o primeiro registro com condição
     * 
     * @param string $column Nome da coluna
     * @param string $operator Operador
     * @param mixed $value Valor
     * @return static|null Instância do modelo ou null
     */
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

    /**
     * Busca o primeiro registro da tabela
     * 
     * @return static|null Instância do modelo ou null
     */
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

    /**
     * Busca o último registro da tabela
     * 
     * @return static|null Instância do modelo ou null
     */
    public static function last(): ?self {
        $instance = new static();
        $sql = "SELECT * FROM {$instance->table} ORDER BY {$instance->primaryKey} DESC LIMIT 1";
        $stmt = $instance->pdo->query($sql);
        $data = $stmt->fetch();
        if ($data) {
            $instance->fill((array) $data);
            return $instance;
        }
        return null;
    }

    /**
     * Ordena registros por uma coluna
     * 
     * @param string $column Nome da coluna
     * @param string $direction Direção (ASC/DESC)
     * @return array Array de instâncias do modelo
     */
    public static function orderBy(string $column, string $direction = 'ASC'): array {
        $instance = new static();
        $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        $sql = "SELECT * FROM {$instance->table} ORDER BY $column $direction";
        $stmt = $instance->pdo->query($sql);
        
        $results = [];
        while ($data = $stmt->fetch()) {
            $model = new static();
            $model->fill((array) $data);
            $results[] = $model;
        }
        return $results;
    }

    /**
     * Limita o número de resultados
     * 
     * @param int $limit Número máximo de resultados
     * @param int $offset Offset (opcional)
     * @return array Array de instâncias do modelo
     */
    public static function limit(int $limit, int $offset = 0): array {
        $instance = new static();
        $sql = "SELECT * FROM {$instance->table} LIMIT :limit OFFSET :offset";
        $stmt = $instance->pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        $results = [];
        while ($data = $stmt->fetch()) {
            $model = new static();
            $model->fill((array) $data);
            $results[] = $model;
        }
        return $results;
    }

    /**
     * Obtém valores únicos de uma coluna
     * 
     * @param string $column Nome da coluna
     * @return array Array de valores únicos
     */
    public static function distinct(string $column): array {
        $instance = new static();
        $sql = "SELECT DISTINCT $column FROM {$instance->table}";
        $stmt = $instance->pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Calcula a soma de uma coluna
     * 
     * @param string $column Nome da coluna
     * @return float Soma dos valores
     */
    public static function sum(string $column): float {
        $instance = new static();
        $sql = "SELECT SUM($column) FROM {$instance->table}";
        $stmt = $instance->pdo->query($sql);
        return (float) $stmt->fetchColumn();
    }

    /**
     * Calcula a média de uma coluna
     * 
     * @param string $column Nome da coluna
     * @return float Média dos valores
     */
    public static function avg(string $column): float {
        $instance = new static();
        $sql = "SELECT AVG($column) FROM {$instance->table}";
        $stmt = $instance->pdo->query($sql);
        return (float) $stmt->fetchColumn();
    }

    /**
     * Encontra o valor máximo de uma coluna
     * 
     * @param string $column Nome da coluna
     * @return mixed Valor máximo
     */
    public static function max(string $column) {
        $instance = new static();
        $sql = "SELECT MAX($column) FROM {$instance->table}";
        $stmt = $instance->pdo->query($sql);
        return $stmt->fetchColumn();
    }

    /**
     * Encontra o valor mínimo de uma coluna
     * 
     * @param string $column Nome da coluna
     * @return mixed Valor mínimo
     */
    public static function min(string $column) {
        $instance = new static();
        $sql = "SELECT MIN($column) FROM {$instance->table}";
        $stmt = $instance->pdo->query($sql);
        return $stmt->fetchColumn();
    }

    /**
     * Deleta múltiplos registros com condição
     * 
     * @param string $column Nome da coluna
     * @param string $operator Operador
     * @param mixed $value Valor
     * @return int Número de registros deletados
     */
    public static function deleteWhere(string $column, string $operator, $value): int {
        $instance = new static();
        $sql = "DELETE FROM {$instance->table} WHERE $column $operator :value";
        $stmt = $instance->pdo->prepare($sql);
        $stmt->execute(['value' => $value]);
        return $stmt->rowCount();
    }

    /**
     * Trunca a tabela (remove todos os registros)
     * 
     * @return bool True se executou com sucesso
     */
    public static function truncate(): bool {
        $instance = new static();
        $sql = "TRUNCATE TABLE {$instance->table}";
        return $instance->pdo->exec($sql) !== false;
    }

    /**
     * Executa uma consulta personalizada
     * 
     * @param string $sql Query SQL
     * @param array $params Parâmetros para a query
     * @return array Array de instâncias do modelo
     */
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

    /**
     * Preenche os atributos do modelo a partir de um array
     * 
     * @param array $data Dados para preencher
     */
    protected function fill(array $data): void {
        $this->attributes = $data;
        $this->dirty = [];
    }

    /**
     * Converte o modelo para array
     * 
     * @return array Array com todos os atributos
     */
    public function toArray(): array {
        return $this->attributes;
    }

    /**
     * Converte o modelo para JSON
     * 
     * @return string JSON string
     */
    public function toJson(): string {
        return json_encode($this->attributes);
    }

    /**
     * Implementa paginação simples
     * 
     * @param int $perPage Registros por página
     * @param int $page Página atual
     * @return array Array com dados paginados e metadados
     */
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

    /**
     * Recarrega o modelo do banco de dados
     * 
     * @return bool True se recarregou com sucesso
     */
    public function refresh(): bool {
        if (empty($this->attributes[$this->primaryKey])) {
            return false;
        }
        
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} WHERE {$this->primaryKey} = :id");
        $stmt->execute(['id' => $this->attributes[$this->primaryKey]]);
        $data = $stmt->fetch();
        
        if ($data) {
            $this->fill((array) $data);
            return true;
        }
        return false;
    }

    /**
     * Verifica se o modelo foi modificado
     * 
     * @return bool True se há modificações não salvas
     */
    public function isDirty(): bool {
        return !empty($this->dirty);
    }

    /**
     * Obtém os atributos modificados
     * 
     * @return array Array com atributos modificados
     */
    public function getDirty(): array {
        return $this->dirty;
    }

    /**
     * Clona o modelo
     * 
     * @return static Nova instância com os mesmos atributos
     */
    public function replicate(): self {
        $instance = new static();
        $attributes = $this->attributes;
        unset($attributes[$this->primaryKey]); // Remove a chave primária
        foreach ($attributes as $key => $value) {
            $instance->$key = $value;
        }
        return $instance;
    }
}