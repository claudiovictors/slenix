<?php

/*
|--------------------------------------------------------------------------
| Classe CSRF
|--------------------------------------------------------------------------
|
| Gerencia tokens CSRF para proteger formulários e requisições
| mutáveis (POST, PUT, PATCH, DELETE) contra Cross-Site Request Forgery.
|
| Integração automática:
|   - O Kernel verifica automaticamente o token em toda requisição
|     com método POST/PUT/PATCH/DELETE.
|   - Nos templates Luna, use @csrf para inserir o campo oculto.
|   - Em APIs via Ajax, envie o header X-CSRF-Token.
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Security;

class CSRF
{
    private const SESSION_KEY  = '_csrf_token';
    private const TOKEN_LENGTH = 32; // bytes → 64 chars hex
    private const HEADER_NAME  = 'HTTP_X_CSRF_TOKEN';
    private const FIELD_NAME   = '_csrf_token';

    // -------------------------------------------------------------------------
    // Geração
    // -------------------------------------------------------------------------

    /**
     * Retorna o token CSRF da sessão atual.
     * Gera um novo se não existir.
     */
    public static function token(): string
    {
        Session::start();

        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(self::TOKEN_LENGTH));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    /**
     * Regenera o token (usar após login/logout para segurança extra).
     */
    public static function regenerate(): string
    {
        Session::start();
        $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(self::TOKEN_LENGTH));
        return $_SESSION[self::SESSION_KEY];
    }

    // -------------------------------------------------------------------------
    // Validação
    // -------------------------------------------------------------------------

    /**
     * Verifica se o token enviado na requisição é válido.
     * Aceita tanto campo de formulário quanto header HTTP (Ajax).
     */
    public static function verify(): bool
    {
        $tokenInSession = $_SESSION[self::SESSION_KEY] ?? null;

        if (!$tokenInSession) {
            return false;
        }

        // Tenta pegar do header (Ajax) ou do corpo da requisição (form)
        $tokenInRequest = static::getTokenFromRequest();

        if (!$tokenInRequest) {
            return false;
        }

        // hash_equals → constant-time comparison (previne timing attacks)
        return hash_equals($tokenInSession, $tokenInRequest);
    }

    /**
     * Verifica o token e lança exceção se inválido.
     *
     * @throws \RuntimeException
     */
    public static function verifyOrFail(): void
    {
        if (!static::verify()) {
            http_response_code(419);
            throw new \RuntimeException(
                'CSRF token inválido ou ausente. Requisição bloqueada.',
                419
            );
        }
    }

    /**
     * Verifica se a requisição é "segura" (não precisa de CSRF).
     * Métodos seguros: GET, HEAD, OPTIONS
     */
    public static function isSafeMethod(): bool
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        return in_array($method, ['GET', 'HEAD', 'OPTIONS'], true);
    }

    /**
     * Verifica se a requisição atual precisa de verificação CSRF.
     */
    public static function shouldVerify(): bool
    {
        return !static::isSafeMethod();
    }

    // -------------------------------------------------------------------------
    // Helpers para views
    // -------------------------------------------------------------------------

    /**
     * Retorna o campo HTML hidden com o token CSRF.
     * Usar em formulários: echo CSRF::field();
     *
     * Nos templates Luna, use @csrf (que chama este método).
     */
    public static function field(): string
    {
        $token = htmlspecialchars(static::token(), ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="' . self::FIELD_NAME . '" value="' . $token . '">';
    }

    /**
     * Retorna a meta tag para uso em Ajax (colocar no <head>).
     * <meta name="csrf-token" content="...">
     *
     * No JS: headers: { 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content }
     */
    public static function meta(): string
    {
        $token = htmlspecialchars(static::token(), ENT_QUOTES, 'UTF-8');
        return '<meta name="csrf-token" content="' . $token . '">';
    }

    /**
     * Retorna campo + meta juntos (conveniente para o <head> + formulário).
     */
    public static function fieldAndMeta(): string
    {
        return static::meta() . "\n" . static::field();
    }

    // -------------------------------------------------------------------------
    // Exclusões (rotas/caminhos que não precisam de CSRF)
    // -------------------------------------------------------------------------

    /** @var string[] */
    private static array $except = [];

    /**
     * Define padrões de URL que devem ser ignorados na verificação.
     * Suporta wildcard *: '/api/*', '/webhook/stripe'
     */
    public static function except(array $patterns): void
    {
        self::$except = $patterns;
    }

    /**
     * Verifica se a URL atual está na lista de exclusões.
     */
    public static function isExcluded(): bool
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?? $uri;

        foreach (self::$except as $pattern) {
            $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#';
            if (preg_match($regex, $path)) {
                return true;
            }
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Helpers internos
    // -------------------------------------------------------------------------

    private static function getTokenFromRequest(): ?string
    {
        // 1) Header X-CSRF-Token (Ajax/Fetch/Axios)
        $header = $_SERVER[self::HEADER_NAME]
            ?? $_SERVER['HTTP_X_XSRF_TOKEN']
            ?? null;

        if ($header) {
            return trim($header);
        }

        // 2) Campo de formulário POST
        if (isset($_POST[self::FIELD_NAME])) {
            return trim($_POST[self::FIELD_NAME]);
        }

        // 3) JSON body (APIs que enviam JSON com _csrf_token)
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $body = json_decode(file_get_contents('php://input'), true);
            if (is_array($body) && isset($body[self::FIELD_NAME])) {
                return trim($body[self::FIELD_NAME]);
            }
        }

        return null;
    }
}