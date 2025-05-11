<?php
/*
|--------------------------------------------------------------------------
| Classe Csrf
|--------------------------------------------------------------------------
|
| Esta classe gerencia a geração e validação de tokens CSRF para proteger
| contra ataques de Cross-Site Request Forgery.
|
*/
declare(strict_types=1);

namespace Slenix\Http\Auth;

use Slenix\Builds\Session;

class Csrf
{
    private static string $tokenKey = '_csrf_token';

    public function __construct()
    {
        Session::start();
    }

    /**
     * Gera um novo token CSRF e o armazena na sessão.
     *
     * @return string O token gerado.
     */
    public static function generateToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION[self::$tokenKey] = $token;
        return $token;
    }

    /**
     * Valida um token CSRF.
     *
     * @param string $token O token a ser validado.
     * @return bool Retorna true se o token for válido, false caso contrário.
     */
    public static function checkToken(string $token): bool
    {
        if (!isset($_SESSION[self::$tokenKey])) {
            return false;
        }

        $valid = hash_equals($_SESSION[self::$tokenKey], $token);
        if ($valid) {
            self::generateToken(); // Regenera o token após validação bem-sucedida
        }

        return $valid;
    }

    /**
     * Retorna a chave do token CSRF.
     *
     * @return string A chave do token.
     */
    public static function getToken(): string
    {
        return self::$tokenKey;
    }
}