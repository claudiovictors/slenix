<?php

/*
|--------------------------------------------------------------------------
| Classe Session
|--------------------------------------------------------------------------
|
| Esta classe fornece uma interface para gerenciar a sessão do usuário de
| forma segura. Ela inclui métodos para iniciar, definir, obter, remover
| e destruir a sessão, além de configurações de segurança para cookies.
| Suporta flash data para armazenar dados temporários (ex.: old input de formulários).
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Security;

class Session
{
    /**
     * Inicia a sessão com configurações de segurança.
     *
     * @return void
     */
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start(
                [
                    'cookie_secure' => isset($_SERVER['HTTPS']),
                    'cookie_httponly' => true,
                    'cookie_samesite' => 'Lax',
                    'use_strict_mode' => true,
                    'use_cookies' => true,
                    'use_only_cookies' => true,
                ]
            );
        }
    }

    /**
     * Define um valor na sessão para uma chave específica.
     *
     * @param string $key A chave para armazenar o valor na sessão.
     * @param mixed $value O valor a ser armazenado.
     * @return void
     */
    public static function set(string $key, mixed $value): void
    {
        self::start();
        $_SESSION[$key] = $value;
    }

    /**
     * Obtém um valor da sessão com base na chave.
     *
     * @param string $key A chave do valor a ser recuperado.
     * @param mixed $default O valor padrão a ser retornado caso a chave não exista.
     * @return mixed O valor da sessão ou o valor padrão.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        self::start();
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Verifica se uma chave existe na sessão.
     *
     * @param string $key A chave a ser verificada.
     * @return bool True se a chave existir na sessão, false caso contrário.
     */
    public static function has(string $key): bool
    {
        self::start();
        return isset($_SESSION[$key]);
    }

    /**
     * Obtém todos os dados armazenados na sessão.
     *
     * @return array<string, mixed> Um array associativo contendo todos os dados da sessão.
     */
    public static function all(): array
    {
        self::start();
        return $_SESSION ?? [];
    }

    /**
     * Remove uma chave e seu valor da sessão.
     *
     * @param string $key A chave a ser removida.
     * @return void
     */
    public static function remove(string $key): void
    {
        self::start();
        unset($_SESSION[$key]);
    }

    /**
     * Regenera o ID da sessão atual.
     *
     * @param bool $deleteOldSession Se true, a sessão antiga será destruída.
     * @return bool True em caso de sucesso, false em caso de falha.
     */
    public static function regenerateId(bool $deleteOldSession = false): bool
    {
        self::start();
        return session_regenerate_id($deleteOldSession);
    }

    /**
     * Destrói a sessão atual.
     *
     * @return void
     */
    public static function destroy(): void
    {
        self::start();
        $_SESSION = [];

        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }

        session_destroy();
    }

    /**
     * Armazena dados na sessão como flash data (disponível apenas na próxima requisição).
     *
     * @param string $key A chave para armazenar o flash data.
     * @param mixed $value O valor a ser armazenado.
     * @return void
     */
    public static function flash(string $key, mixed $value): void
    {
        self::start();
        $_SESSION['_flash'][$key] = $value;
    }

    /**
     * Obtém um valor de flash data e o remove da sessão.
     *
     * @param string $key A chave do flash data a ser recuperado.
     * @param mixed $default O valor padrão a ser retornado caso a chave não exista.
     * @return mixed O valor do flash data ou o valor padrão.
     */
    public static function getFlash(string $key, mixed $default = null): mixed
    {
        self::start();
        $value = $_SESSION['_flash'][$key] ?? $default;
        unset($_SESSION['_flash'][$key]);
        if (empty($_SESSION['_flash'])) {
            unset($_SESSION['_flash']);
        }
        return $value;
    }

    /**
     * Verifica se uma chave de flash data existe na sessão.
     *
     * @param string $key A chave a ser verificada.
     * @return bool True se a chave de flash data existir, false caso contrário.
     */
    public static function hasFlash(string $key): bool
    {
        self::start();
        return isset($_SESSION['_flash'][$key]);
    }

    /**
     * Salva os inputs antigos na sessão como flash data.
     *
     * @param array $data Array de dados, normalmente $_POST.
     * @return void
     */
    public static function flashOldInput(array $data): void
    {
        self::start();
        foreach ($data as $key => $value) {
            $_SESSION['_flash']['_old_input_' . $key] = $value;
        }
    }
}
