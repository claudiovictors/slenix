<?php
/*
|--------------------------------------------------------------------------
| Classe Flash
|--------------------------------------------------------------------------
|
| Sistema para armazenar e recuperar mensagens flash na sessão.
| Mensagens flash são úteis para exibir informações temporárias
| como confirmações ou erros após redirecionamentos.
*/

declare(strict_types=1);

namespace Slenix\Libraries;

/**
 * Classe para gerenciar mensagens flash na sessão.
 */
class Flash
{
    public const TYPE_SUCCESS = 'success';
    public const TYPE_ERROR = 'error';
    public const TYPE_WARNING = 'warning';
    public const TYPE_INFO = 'info';

    /**
     * Inicia a sessão se ainda não estiver ativa.
     */
    private static function ensureSessionStarted(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Inicializa o array de flash se não existir
        if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
            $_SESSION['flash'] = [];
        }
    }

    /**
     * Define uma nova mensagem flash para uma chave específica.
     *
     * @param string $key A chave para identificar a mensagem flash.
     * @param mixed $message A mensagem a ser armazenada (string ou array).
     * @param bool $allowHtml Se true, armazena HTML sem sanitização.
     * @throws \InvalidArgumentException Se a chave ou mensagem forem inválidas.
     */
    public static function set(string $key, mixed $message, bool $allowHtml = false): void
    {
        if (empty($key)) {
            throw new \InvalidArgumentException('Flash message key cannot be empty');
        }

        if (empty($message)) {
            throw new \InvalidArgumentException('Flash message cannot be empty');
        }

        self::ensureSessionStarted();

        if (!$allowHtml && is_string($message)) {
            $message = htmlspecialchars($message, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        // Se já existir mensagem para esta chave, converte para array
        if (isset($_SESSION['flash'][$key])) {
            if (!is_array($_SESSION['flash'][$key])) {
                $_SESSION['flash'][$key] = [$_SESSION['flash'][$key]];
            }
            $_SESSION['flash'][$key][] = $message;
        } else {
            $_SESSION['flash'][$key] = $message;
        }
    }

    /**
     * Métodos auxiliares para tipos comuns de mensagens
     */
    public static function success(string $message, bool $allowHtml = false): void
    {
        self::set(self::TYPE_SUCCESS, $message, $allowHtml);
    }

    public static function error(string $message, bool $allowHtml = false): void
    {
        self::set(self::TYPE_ERROR, $message, $allowHtml);
    }

    public static function warning(string $message, bool $allowHtml = false): void
    {
        self::set(self::TYPE_WARNING, $message, $allowHtml);
    }

    public static function info(string $message, bool $allowHtml = false): void
    {
        self::set(self::TYPE_INFO, $message, $allowHtml);
    }

    /**
     * Verifica se existe uma mensagem flash para uma determinada chave.
     */
    public static function has(string $key): bool
    {
        self::ensureSessionStarted();
        return isset($_SESSION['flash'][$key]);
    }

    /**
     * Recupera e remove uma mensagem flash com base na chave.
     *
     * @return mixed A mensagem flash ou null se a chave não existir.
     */
    public static function put(string $key): mixed
    {
        self::ensureSessionStarted();
        
        if (!isset($_SESSION['flash'][$key])) {
            return null;
        }

        $message = $_SESSION['flash'][$key];
        unset($_SESSION['flash'][$key]);
        return $message;
    }

    /**
     * Recupera todas as mensagens flash de um tipo e as remove.
     *
     * @return array Lista de mensagens ou array vazio se não houver mensagens.
     */
    public static function putAll(string $key): array
    {
        $message = self::put($key);
        return is_array($message) ? $message : (array) $message;
    }

    /**
     * Recupera uma mensagem flash sem removê-la da sessão.
     */
    public static function peek(string $key): mixed
    {
        self::ensureSessionStarted();
        return $_SESSION['flash'][$key] ?? null;
    }

    /**
     * Remove explicitamente uma mensagem flash da sessão.
     */
    public static function forget(string $key): void
    {
        self::ensureSessionStarted();
        unset($_SESSION['flash'][$key]);
    }

    /**
     * Limpa todas as mensagens flash da sessão.
     */
    public static function clear(): void
    {
        self::ensureSessionStarted();
        $_SESSION['flash'] = [];
    }

    /**
     * Recupera uma variável diretamente da sessão e a remove.
     *
     * @return mixed O valor da variável de sessão ou null se não existir.
     */
    public static function get(string $key): mixed
    {
        self::ensureSessionStarted();
        
        if (!array_key_exists($key, $_SESSION)) {
            return null;
        }

        $value = $_SESSION[$key];
        unset($_SESSION[$key]);
        return $value;
    }
}