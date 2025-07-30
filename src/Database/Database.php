<?php

declare(strict_types=1);

namespace Slenix\Database;

use PDO, PDOException;

class Database {
    private static $connection = null;
    private ?object $pdo;

    public function __construct(array $config = null) {
        if ($config === null) {
            $config = require_once __DIR__ . '/../Configs/Config.php';
            $config = $config['db_connect'];
        }

        try {
            $dsn = "{$config['drive']}:host={$config['hostname']};port={$config['port']};dbname={$config['dbname']};charset={$config['charset']}";
            $this->pdo = new PDO($dsn, $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            die('Falha na conexão: ' . $e->getMessage());
        }
    }

    public static function getInstance(): PDO {
        if (self::$connection === null) {
            self::$connection = new self();
        }
        return self::$connection->pdo;
    }

    public static function raw(string $sql, array $params = []): mixed {
        $pdo = self::getInstance();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function getPdo(): PDO {
        return $this->pdo;
    }
}