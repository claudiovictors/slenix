<?php

/*
|--------------------------------------------------------------------------
| Class Database
|--------------------------------------------------------------------------
|
| Gerencia a conexão com o banco de dados utilizando PDO.
| Implementa o padrão de design Singleton para garantir uma única instância
| da conexão.
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Database;

use PDO;
use PDOException;
use Slenix\Core\Exceptions\ErrorHandler;

class Connection extends ErrorHandler {
    /**
     * @var Connection|null A única instância da classe de conexão.
     */
    private static $connection = null;

    /**
     * @var PDO|null A instância do objeto PDO.
     */
    private ?object $pdo;

    /**
     * Construtor da classe.
     *
     * Cria uma nova conexão PDO com base nas configurações fornecidas.
     * Carrega as configurações de um arquivo se nenhuma for passada.
     *
     * @param array|null $config As configurações de conexão (opcional).
     * @throws \Exception Se o arquivo de configuração não for encontrado.
     * @throws \InvalidArgumentException Se o driver do banco de dados não for suportado.
     */
    public function __construct(array $config = null) {
        if ($config === null) {
            $configFile = __DIR__ . '/../../Config/app.php';
            if (!file_exists($configFile)) {
                throw new \Exception('Arquivo de configuração não encontrado: ' . $configFile);
            }
            $config = require_once $configFile;
            $config = $config['db_connect'];
        }

        $driver = strtolower($config['drive']);
        if (!in_array($driver, ['mysql', 'pgsql'])) {
            throw new \InvalidArgumentException("Driver de banco não suportado: {$config['drive']}. Use: mysql ou pgsql.");
        }

        try {
            // Monta a string DSN e as opções do PDO.
            $dsn = $this->buildDsn($config, $driver);
            $options = $this->getPdoOptions($driver);

            // Tenta criar a conexão PDO.
            $this->pdo = new PDO($dsn, $config['username'], $config['password'], $options);
        } catch (PDOException $e) {
            // Em caso de falha na conexão, lida com a exceção.
            $this->handleException($e);
        }
    }

    /**
     * Monta a string DSN baseada no driver detectado.
     *
     * @param array $config As configurações de conexão.
     * @param string $driver O driver de banco de dados ('mysql' ou 'pgsql').
     * @return string A string DSN formatada.
     */
    private function buildDsn(array $config, string $driver): string {
        $dsn = $driver . ':';

        switch ($driver):
            case 'mysql':
                $dsn .= "host={$config['hostname']};port={$config['port']};dbname={$config['dbname']};charset={$config['charset']}";
                if (!empty($config['collation'])):
                    $dsn .= ";collation={$config['collation']}";
                endif;
                break;

            case 'pgsql':
                // Para PostgreSQL, apenas as opções são usadas para codificação, o `charset` no DSN não é suportado.
                $dsn .= "host={$config['hostname']} port={$config['port']} dbname={$config['dbname']} options='--client_encoding=UTF8'";
                break;
        endswitch;

        return $dsn;
    }

    /**
     * Retorna um array de opções PDO específicas por driver.
     *
     * @param string $driver O driver de banco de dados.
     * @return array As opções do PDO.
     */
    private function getPdoOptions(string $driver): array {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        // Se o driver for PostgreSQL, adiciona a opção para evitar a stringificação de números.
        if ($driver === 'pgsql') {
            $options[PDO::ATTR_STRINGIFY_FETCHES] = false;
        }

        return $options;
    }

    /**
     * Retorna a única instância da conexão PDO.
     *
     * Garante que apenas uma instância da conexão seja criada.
     *
     * @return PDO A instância do objeto PDO.
     */
    public static function getInstance(): PDO {
        if (self::$connection === null) {
            self::$connection = new self();
        }
        return self::$connection->pdo;
    }

    /**
     * Executa uma consulta SQL bruta e retorna os resultados.
     *
     * @param string $sql A string da consulta SQL.
     * @param array $params Os parâmetros a serem vinculados na consulta.
     * @return mixed Os resultados da consulta.
     */
    public static function raw(string $sql, array $params = []): mixed {
        $pdo = self::getInstance();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Retorna o objeto PDO.
     *
     * @return PDO O objeto PDO.
     */
    public function getPdo(): PDO {
        return $this->pdo;
    }
}
