<?php
/*
|--------------------------------------------------------------------------
| Classe Flash
|--------------------------------------------------------------------------
|
| Esta classe fornece um sistema simples para armazenar e recuperar mensagens
| flash na sessão do usuário. As mensagens flash são úteis para exibir
| informações temporárias (como confirmações ou erros) após um redirecionamento.
| Funcionalidades adicionais foram incluídas para verificar a existência de
| mensagens, visualizá-las sem remover, remover explicitamente e limpar todas.
|
*/
declare(strict_types=1);

namespace Slenix\Builds;

/**
 * Classe para gerenciar mensagens flash na sessão.
 */
class Flash
{
    /**
     * Inicia a sessão se ainda não estiver ativa.
     *
     * @return void
     */
    private static function ensureSessionStarted(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * Define uma nova mensagem flash para uma chave específica.
     *
     * @param string $key A chave para identificar a mensagem flash.
     * @param string $message A mensagem a ser armazenada.
     * @return void
     */
    public static function set(string $key, string $message): void
    {
        self::ensureSessionStarted();
        $_SESSION['flash'][$key] = $message;
    }

    /**
     * Verifica se existe uma mensagem flash para uma determinada chave.
     *
     * @param string $key A chave da mensagem flash a ser verificada.
     * @return bool True se a mensagem flash existir, false caso contrário.
     */
    public static function has(string $key): bool
    {
        self::ensureSessionStarted();
        return isset($_SESSION['flash'][$key]);
    }

    /**
     * Recupera e remove uma mensagem flash com base na chave.
     *
     * @param string $key A chave da mensagem flash a ser recuperada.
     * @return string|null A mensagem flash ou null se a chave não existir.
     */
    public static function put(string $key): ?string
    {
        self::ensureSessionStarted();
        if (isset($_SESSION['flash'][$key])) {
            $message = $_SESSION['flash'][$key];
            unset($_SESSION['flash'][$key]);
            return $message;
        }
        return null;
    }

    /**
     * Recupera uma mensagem flash sem removê-la da sessão.
     *
     * @param string $key A chave da mensagem flash a ser visualizada.
     * @return string|null A mensagem flash ou null se a chave não existir.
     */
    public static function peek(string $key): ?string
    {
        self::ensureSessionStarted();
        return $_SESSION['flash'][$key] ?? null;
    }

    /**
     * Remove explicitamente uma mensagem flash da sessão.
     *
     * @param string $key A chave da mensagem flash a ser removida.
     * @return void
     */
    public static function forget(string $key): void
    {
        self::ensureSessionStarted();
        unset($_SESSION['flash'][$key]);
    }

    /**
     * Limpa todas as mensagens flash da sessão.
     *
     * @return void
     */
    public static function clear(): void
    {
        self::ensureSessionStarted();
        unset($_SESSION['flash']);
    }

    /**
     * Recupera uma variável diretamente da sessão e a remove.
     *
     * @param string $key A chave da variável de sessão a ser recuperada.
     * @return mixed O valor da variável de sessão ou null se não existir.
     */
    public static function get(string $key)
    {
        self::ensureSessionStarted();
        $message = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);
        return $message;
    }
}