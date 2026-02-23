<?php

/*
|--------------------------------------------------------------------------
| Helpers Globais — Slenix Framework
|--------------------------------------------------------------------------
|
| Funções auxiliares disponíveis em todo o projeto, inspiradas no Laravel.
| Estrutura: src/Supports/Helpers/Helpers.php
|
*/

declare(strict_types=1);

use Slenix\Http\Response;
use Slenix\Http\Routing\Router;
use Slenix\Supports\Template\Luna;
use Slenix\Supports\Security\Session;

// =============================================================================
// CONSTANTES DO PROJETO
// =============================================================================

defined('SLENIX_START') or define('SLENIX_START', microtime(true));
defined('ROOT_PATH')    or define('ROOT_PATH',    dirname(__DIR__, 3));
defined('APP_PATH')     or define('APP_PATH',     ROOT_PATH . '/app');
defined('PUBLIC_PATH')  or define('PUBLIC_PATH',  ROOT_PATH . '/public');
defined('SRC_PATH')     or define('SRC_PATH',     ROOT_PATH . '/src');
defined('ROUTES_PATH')  or define('ROUTES_PATH',  ROOT_PATH . '/routes');
defined('VIEWS_PATH')   or define('VIEWS_PATH',   ROOT_PATH . '/views');
defined('STORAGE_PATH') or define('STORAGE_PATH', ROOT_PATH . '/storage');
defined('CONFIG_PATH')  or define('CONFIG_PATH',  ROOT_PATH . '/src/Config');

// =============================================================================
// AMBIENTE
// =============================================================================

if (!function_exists('env')) {
    /**
     * Obtém variável de ambiente com valor padrão.
     * Converte automaticamente "true"/"false"/"null" para seus tipos nativos.
     */
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === null) {
            return $default;
        }

        return match (strtolower((string) $value)) {
            'true',  '(true)'  => true,
            'false', '(false)' => false,
            'null',  '(null)'  => null,
            'empty', '(empty)' => '',
            default            => $value,
        };
    }
}

if (!function_exists('config')) {
    /**
     * Lê configurações do .env com notação simples.
     * config('app_debug') → env('APP_DEBUG')
     */
    function config(string $key, mixed $default = null): mixed
    {
        return env(strtoupper(str_replace('.', '_', $key)), $default);
    }
}

if (!function_exists('app_env')) {
    /**
     * Verifica o ambiente atual da aplicação.
     * app_env('production') → true se APP_ENV === 'production'
     */
    function app_env(string ...$environments): bool
    {
        $current = env('APP_ENV', 'local');
        return in_array($current, $environments, true);
    }
}

if (!function_exists('is_debug')) {
    /**
     * Verifica se o modo debug está ativo.
     */
    function is_debug(): bool
    {
        return (bool) env('APP_DEBUG', false);
    }
}

// =============================================================================
// CAMINHOS
// =============================================================================

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        return ROOT_PATH . ($path ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : '');
    }
}

if (!function_exists('app_path')) {
    function app_path(string $path = ''): string
    {
        return APP_PATH . ($path ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : '');
    }
}

if (!function_exists('public_path')) {
    function public_path(string $path = ''): string
    {
        return PUBLIC_PATH . ($path ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : '');
    }
}

if (!function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        return STORAGE_PATH . ($path ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : '');
    }
}

if (!function_exists('views_path')) {
    function views_path(string $path = ''): string
    {
        return VIEWS_PATH . ($path ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : '');
    }
}

// =============================================================================
// VIEWS
// =============================================================================

if (!function_exists('view')) {
    /**
     * Renderiza um template Luna e envia como resposta HTML.
     */
    function view(string $template, array $data = []): void
    {
        $luna = new Luna($template, $data);
        echo $luna->render();
    }
}

// =============================================================================
// REDIRECT — Fluente, estilo Laravel
// =============================================================================

if (!function_exists('redirect')) {
    /**
     * Retorna um objeto RedirectResponse fluente.
     *
     * Uso:
     *   redirect('/home');
     *   redirect()->back();
     *   redirect()->route('login');
     *   redirect('/home')->with('success', 'Salvo!');
     *   redirect('/home')->withErrors(['email' => 'Inválido']);
     *   redirect('/home')->withInput();
     */
    function redirect(?string $url = null, int $status = 302): RedirectResponse
    {
        $r = new RedirectResponse($status);
        if ($url !== null) {
            $r->to($url);
        }
        return $r;
    }
}

/**
 * Objeto fluente para redirecionamentos.
 * Não usa namespace para ficar disponível globalmente.
 */
class RedirectResponse
{
    private int     $status;
    private ?string $url     = null;
    private array   $flashData = [];

    public function __construct(int $status = 302)
    {
        $this->status = $status;
    }

    /** Redireciona para uma URL */
    public function to(string $url): never
    {
        $this->url = $url;
        $this->sendFlash();
        $url = str_replace(["\r", "\n", "\0"], '', $url);
        header("Location: {$url}", true, $this->status);
        exit;
    }

    /** Redireciona de volta ao referer */
    public function back(string $fallback = '/'): never
    {
        $this->to($_SERVER['HTTP_REFERER'] ?? $fallback);
    }

    /** Redireciona para uma rota nomeada */
    public function route(string $name, array $params = []): never
    {
        $url = Router::route($name, $params) ?? '/';
        $this->to($url);
    }

    /** Adiciona flash data ao redirecionar */
    public function with(string $key, mixed $value): static
    {
        $this->flashData[$key] = $value;
        return $this;
    }

    /** Adiciona múltiplos flash data */
    public function withMany(array $data): static
    {
        foreach ($data as $key => $value) {
            $this->flashData[$key] = $value;
        }
        return $this;
    }

    /**
     * Flash de erros de validação.
     * Acessível com errors('campo') na próxima view.
     */
    public function withErrors(array $errors, string $bag = 'default'): static
    {
        $this->flashData['_errors'][$bag] = $errors;
        return $this;
    }

    /**
     * Flash dos inputs do formulário atual (para repopular campos).
     * Acessível com old('campo') na próxima view.
     */
    public function withInput(?array $input = null): static
    {
        $input ??= $_POST;
        // Remove campos sensíveis
        unset($input['password'], $input['password_confirmation'], $input['_token']);
        $this->flashData['_old_input'] = $input;
        return $this;
    }

    /** Envia os dados flash para a sessão antes de redirecionar */
    private function sendFlash(): void
    {
        foreach ($this->flashData as $key => $value) {
            Session::flash($key, $value);
        }
    }
}

// =============================================================================
// FLASH — Mensagens rápidas entre requests
// =============================================================================

if (!function_exists('flash')) {
    /**
     * Retorna objeto fluente para flash messages.
     *
     * Escrita:
     *   flash()->success('Guardado com sucesso!');
     *   flash()->error('Ocorreu um erro.');
     *   flash()->warning('Atenção!');
     *   flash()->info('Informação.');
     *   flash()->write('custom_key', 'Valor');
     *
     * Leitura:
     *   flash()->has('success');
     *   flash()->get('success');
     */
    function flash(): FlashMessage
    {
        return new FlashMessage();
    }
}

class FlashMessage
{
    /** Flash com tipo 'success' */
    public function success(string $message): static
    {
        Session::flash('_flash_success', $message);
        return $this;
    }

    /** Flash com tipo 'error' */
    public function error(string $message): static
    {
        Session::flash('_flash_error', $message);
        return $this;
    }

    /** Flash com tipo 'warning' */
    public function warning(string $message): static
    {
        Session::flash('_flash_warning', $message);
        return $this;
    }

    /** Flash com tipo 'info' */
    public function info(string $message): static
    {
        Session::flash('_flash_info', $message);
        return $this;
    }

    /** Flash com chave personalizada */
    public function write(string $key, mixed $value): static
    {
        Session::flash($key, $value);
        return $this;
    }

    /** Verifica se há flash de um tipo */
    public function has(string $key): bool
    {
        return Session::hasFlash($key)
            || Session::hasFlash('_flash_' . $key);
    }

    /**
     * Obtém e remove um flash message.
     * flash()->get('success') ou flash()->get('_flash_success')
     */
    public function get(string $key, mixed $default = null): mixed
    {
        // Tenta direto, depois com prefixo
        if (Session::hasFlash($key)) {
            return Session::getFlash($key, $default);
        }
        return Session::getFlash('_flash_' . $key, $default);
    }

    /** Obtém todos os flash messages sem removê-los */
    public function all(): array
    {
        return $_SESSION['_flash'] ?? [];
    }
}

// =============================================================================
// SESSION — Interface fluente para sessão
// =============================================================================

if (!function_exists('session')) {
    /**
     * Acessa a sessão de forma fluente.
     *
     * session()              → instância de SessionManager
     * session('key')         → Session::get('key')
     * session('key', 'val')  → Session::set('key', 'val')
     * session(['k' => 'v'])  → Session::set múltiplo
     */
    function session(string|array|null $key = null, mixed $value = null): mixed
    {
        $manager = new SessionManager();

        // Sem args → retorna o manager fluente
        if ($key === null) {
            return $manager;
        }

        // Array → set múltiplo
        if (is_array($key)) {
            foreach ($key as $k => $v) {
                Session::set((string) $k, $v);
            }
            return $manager;
        }

        // Com value → set
        if ($value !== null) {
            Session::set($key, $value);
            return $manager;
        }

        // Só key → get
        return Session::get($key);
    }
}

class SessionManager
{
    /** Define um valor na sessão */
    public function set(string $key, mixed $value): static
    {
        Session::set($key, $value);
        return $this;
    }

    /** Define múltiplos valores */
    public function put(string|array $key, mixed $value = null): static
    {
        if (is_array($key)) {
            foreach ($key as $k => $v) {
                Session::set((string) $k, $v);
            }
        } else {
            Session::set($key, $value);
        }
        return $this;
    }

    /** Obtém um valor da sessão */
    public function get(string $key, mixed $default = null): mixed
    {
        return Session::get($key, $default);
    }

    /** Verifica se uma chave existe */
    public function has(string $key): bool
    {
        return Session::has($key);
    }

    /** Verifica se uma chave NÃO existe */
    public function missing(string $key): bool
    {
        return !Session::has($key);
    }

    /** Obtém e remove um valor (pull) */
    public function pull(string $key, mixed $default = null): mixed
    {
        $value = Session::get($key, $default);
        Session::remove($key);
        return $value;
    }

    /** Remove uma ou mais chaves */
    public function forget(string|array $keys): static
    {
        foreach ((array) $keys as $key) {
            Session::remove($key);
        }
        return $this;
    }

    /** Remove todas as chaves da sessão (sem destruir) */
    public function flush(): static
    {
        Session::start();
        $_SESSION = [];
        return $this;
    }

    /** Destrói a sessão completamente */
    public function invalidate(): static
    {
        Session::destroy();
        return $this;
    }

    /** Regenera o ID da sessão (segurança pós-login) */
    public function regenerate(bool $deleteOld = true): static
    {
        Session::regenerateId($deleteOld);
        return $this;
    }

    /** Armazena flash data */
    public function flash(string $key, mixed $value): static
    {
        Session::flash($key, $value);
        return $this;
    }

    /** Obtém e remove flash data */
    public function getFlash(string $key, mixed $default = null): mixed
    {
        return Session::getFlash($key, $default);
    }

    /** Verifica se flash data existe */
    public function hasFlash(string $key): bool
    {
        return Session::hasFlash($key);
    }

    /** Armazena old input (para repopular formulários) */
    public function flashInput(array $data): static
    {
        // Remove campos sensíveis
        unset($data['password'], $data['password_confirmation'], $data['_token']);
        Session::flashOldInput($data);
        return $this;
    }

    /** Retorna todos os dados da sessão */
    public function all(): array
    {
        return Session::all();
    }

    /** Incrementa valor numérico na sessão */
    public function increment(string $key, int $amount = 1): int
    {
        $new = ((int) Session::get($key, 0)) + $amount;
        Session::set($key, $new);
        return $new;
    }

    /** Decrementa valor numérico na sessão */
    public function decrement(string $key, int $amount = 1): int
    {
        return $this->increment($key, -$amount);
    }

    /** Empurra valor para um array na sessão */
    public function push(string $key, mixed $value): static
    {
        $array   = (array) Session::get($key, []);
        $array[] = $value;
        Session::set($key, $array);
        return $this;
    }

    /** ID da sessão atual */
    public function id(): string
    {
        Session::start();
        return session_id();
    }
}

// =============================================================================
// OLD INPUT & ERRORS (formulários)
// =============================================================================

if (!function_exists('old')) {
    /**
     * Retorna o valor antigo de um campo de formulário.
     * Alimentado por redirect()->withInput() ou session()->flashInput().
     */
    function old(string $key, mixed $default = ''): mixed
    {
        // Tenta primeiro o flash agrupado
        $oldInput = Session::getFlash('_old_input');
        if (is_array($oldInput) && isset($oldInput[$key])) {
            // Re-flasheia para a próxima leitura do ciclo atual
            Session::flash('_old_input', $oldInput);
            return $oldInput[$key];
        }

        // Fallback: chave individual (compatibilidade com flashOldInput())
        $individual = Session::getFlash('_old_input_' . $key);
        if ($individual !== null) {
            return $individual;
        }

        return $default;
    }
}

if (!function_exists('errors')) {
    /**
     * Retorna erros de validação do bag especificado.
     *
     * errors()              → array com todos os erros
     * errors('email')       → string com erro do campo 'email'
     * errors('email', true) → array com todos os erros do campo
     */
    function errors(?string $field = null, bool $all = false): array|string|null
    {
        $bags = Session::getFlash('_errors') ?? [];

        // Re-flasheia para ficar disponível durante o render
        if (!empty($bags)) {
            Session::flash('_errors', $bags);
        }

        // Todos os erros de todos os bags
        if ($field === null) {
            $all_errors = [];
            foreach ($bags as $bag) {
                foreach ((array) $bag as $f => $msg) {
                    $all_errors[$f] = $msg;
                }
            }
            return $all_errors;
        }

        // Erros de um campo específico
        foreach ($bags as $bag) {
            if (isset($bag[$field])) {
                return $all ? (array) $bag[$field] : (is_array($bag[$field]) ? $bag[$field][0] : $bag[$field]);
            }
        }

        return $all ? [] : null;
    }
}

if (!function_exists('has_error')) {
    /**
     * Verifica se um campo tem erro de validação.
     */
    function has_error(string $field): bool
    {
        return errors($field) !== null;
    }
}

// =============================================================================
// URL & ROTAS
// =============================================================================

if (!function_exists('url')) {
    /**
     * Gera URL completa a partir de um caminho.
     */
    function url(string $path = '', array $query = []): string
    {
        $base = rtrim(env('APP_BASE_URL', ''), '/');
        $path = '/' . ltrim($path, '/');
        $url  = $base . $path;

        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        return $url;
    }
}

if (!function_exists('asset')) {
    /**
     * URL para arquivo estático em /public.
     */
    function asset(string $path): string
    {
        return url($path);
    }
}

if (!function_exists('route')) {
    /**
     * Gera URL para uma rota nomeada.
     */
    function route(string $name, array $params = []): ?string
    {
        return Router::route($name, $params);
    }
}

if (!function_exists('current_url')) {
    /**
     * Retorna a URL atual completa.
     */
    function current_url(): string
    {
        $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $uri    = $_SERVER['REQUEST_URI'] ?? '/';
        return "{$scheme}://{$host}{$uri}";
    }
}

if (!function_exists('request_path')) {
    /**
     * Retorna apenas o path da URL atual (sem query string).
     */
    function request_path(): string
    {
        return parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
    }
}

if (!function_exists('is_active')) {
    /**
     * Verifica se o path atual corresponde ao padrão (útil para menus).
     *
     * is_active('/home')        → exact match
     * is_active('/blog/*')      → wildcard
     */
    function is_active(string $pattern, string $active = 'active', string $inactive = ''): string
    {
        $path = request_path();

        if (str_ends_with($pattern, '*')) {
            $prefix = rtrim($pattern, '*');
            $match  = str_starts_with($path, $prefix);
        } else {
            $match = ($path === $pattern);
        }

        return $match ? $active : $inactive;
    }
}

// =============================================================================
// HTTP / ABORT
// =============================================================================

if (!function_exists('abort')) {
    /**
     * Aborta a requisição com um código HTTP.
     */
    function abort(int $code = 500, string $message = ''): never
    {
        $texts = [
            400 => 'Bad Request',       401 => 'Unauthorized',
            403 => 'Forbidden',         404 => 'Not Found',
            405 => 'Method Not Allowed',408 => 'Request Timeout',
            409 => 'Conflict',          422 => 'Unprocessable Entity',
            429 => 'Too Many Requests', 500 => 'Internal Server Error',
            502 => 'Bad Gateway',       503 => 'Service Unavailable',
        ];

        $msg = $message ?: ($texts[$code] ?? 'Error');

        http_response_code($code);

        $wantsJson = isset($_SERVER['HTTP_ACCEPT'])
            && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json');

        if ($wantsJson) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['error' => true, 'message' => $msg, 'status' => $code]);
        } else {
            $errFile = SRC_PATH . "/Core/Exceptions/errors/{$code}.php";
            if (file_exists($errFile)) {
                extract(['code' => $code, 'message' => $msg]);
                include $errFile;
            } else {
                echo "<h1>{$code} — {$msg}</h1>";
            }
        }

        exit;
    }
}

if (!function_exists('abort_if')) {
    /**
     * Aborta se a condição for verdadeira.
     */
    function abort_if(bool $condition, int $code = 500, string $message = ''): void
    {
        if ($condition) {
            abort($code, $message);
        }
    }
}

if (!function_exists('abort_unless')) {
    /**
     * Aborta se a condição for falsa.
     */
    function abort_unless(bool $condition, int $code = 500, string $message = ''): void
    {
        if (!$condition) {
            abort($code, $message);
        }
    }
}

// =============================================================================
// RESPOSTA
// =============================================================================

if (!function_exists('response')) {
    /**
     * Cria uma instância de Response.
     * response()              → instância vazia
     * response('texto', 200)  → response com conteúdo
     */
    function response(mixed $content = null, int $status = 200): Response
    {
        $r = new Response();
        $r->status($status);
        if ($content !== null) {
            $r->setContent($content);
        }
        return $r;
    }
}

// =============================================================================
// STRINGS
// =============================================================================

if (!function_exists('sanitize')) {
    function sanitize(string $string): string
    {
        return trim(htmlspecialchars($string, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }
}

if (!function_exists('validate_name')) {
    function validate_name(string $string): bool
    {
        return (bool) preg_match('/^[A-Za-zÀ-ÖØ-öø-ÿ\s]+$/u', $string);
    }
}

if (!function_exists('camel_case')) {
    function camel_case(string $string): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $string))));
    }
}

if (!function_exists('snake_case')) {
    function snake_case(string $string, string $delimiter = '_'): string
    {
        if (!ctype_lower($string)) {
            $string = (string) preg_replace('/\s+/u', '', $string);
            $string = mb_strtolower(
                (string) preg_replace('/(.)(?=[A-Z])/u', '$1' . $delimiter, $string),
                'UTF-8'
            );
        }
        return $string;
    }
}

if (!function_exists('pascal_case')) {
    function pascal_case(string $string): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $string)));
    }
}

if (!function_exists('kebab_case')) {
    function kebab_case(string $string): string
    {
        return snake_case($string, '-');
    }
}

if (!function_exists('str_default')) {
    function str_default(?string $string, string $default): string
    {
        return empty($string) ? $default : $string;
    }
}

if (!function_exists('limit')) {
    function limit(string $text, int $limit, string $end = '...'): string
    {
        return mb_strlen($text) > $limit
            ? mb_substr($text, 0, $limit, 'UTF-8') . $end
            : $text;
    }
}

if (!function_exists('str_slug')) {
    function str_slug(string $text, string $separator = '-'): string
    {
        $text = iconv('UTF-8', 'ASCII//TRANSLIT', $text) ?: $text;
        $text = (string) preg_replace('/[^a-zA-Z0-9]+/', $separator, $text);
        return strtolower(trim($text, $separator));
    }
}

if (!function_exists('str_contains_any')) {
    /**
     * Verifica se a string contém qualquer um dos valores do array.
     */
    function str_contains_any(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('mask')) {
    /**
     * Mascara parte de uma string.
     * mask('joao@email.com', '*', 2, 8) → 'jo********mail.com'
     */
    function mask(string $string, string $char = '*', int $start = 0, ?int $length = null): string
    {
        $len    = mb_strlen($string);
        $length = $length ?? $len - $start;
        $masked = str_repeat($char, min($length, $len - $start));
        return mb_substr($string, 0, $start) . $masked . mb_substr($string, $start + $length);
    }
}

// =============================================================================
// ARRAYS
// =============================================================================

if (!function_exists('is_empty')) {
    function is_empty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }
}

if (!function_exists('array_get')) {
    /**
     * Obtém valor de array com dot-notation.
     * array_get($user, 'address.city', 'N/A')
     */
    function array_get(array $array, string|int $key, mixed $default = null): mixed
    {
        if (isset($array[$key])) {
            return $array[$key];
        }

        // Dot-notation
        if (is_string($key) && str_contains($key, '.')) {
            $keys    = explode('.', $key);
            $current = $array;
            foreach ($keys as $k) {
                if (!is_array($current) || !array_key_exists($k, $current)) {
                    return $default;
                }
                $current = $current[$k];
            }
            return $current;
        }

        return $default;
    }
}

if (!function_exists('array_set')) {
    /**
     * Define valor em array com dot-notation.
     */
    function array_set(array &$array, string $key, mixed $value): void
    {
        $keys    = explode('.', $key);
        $current = &$array;
        foreach ($keys as $k) {
            if (!isset($current[$k]) || !is_array($current[$k])) {
                $current[$k] = [];
            }
            $current = &$current[$k];
        }
        $current = $value;
    }
}

if (!function_exists('array_only')) {
    /**
     * Retorna apenas as chaves especificadas de um array.
     */
    function array_only(array $array, array $keys): array
    {
        return array_intersect_key($array, array_flip($keys));
    }
}

if (!function_exists('array_except')) {
    /**
     * Retorna o array sem as chaves especificadas.
     */
    function array_except(array $array, array $keys): array
    {
        return array_diff_key($array, array_flip($keys));
    }
}

if (!function_exists('array_flatten')) {
    /**
     * Achata um array multidimensional em um único nível.
     */
    function array_flatten(array $array): array
    {
        $result = [];
        array_walk_recursive($array, function ($v) use (&$result) {
            $result[] = $v;
        });
        return $result;
    }
}

if (!function_exists('array_wrap')) {
    /**
     * Garante que o valor seja um array.
     */
    function array_wrap(mixed $value): array
    {
        if (is_null($value)) {
            return [];
        }
        return is_array($value) ? $value : [$value];
    }
}

if (!function_exists('collect')) {
    /**
     * Coleção simples (encapsula array com métodos fluentes básicos).
     */
    function collect(array $items = []): Collection
    {
        return new Collection($items);
    }
}

class Collection
{
    private array $items;

    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    public function all(): array           { return $this->items; }
    public function count(): int           { return count($this->items); }
    public function isEmpty(): bool        { return empty($this->items); }
    public function isNotEmpty(): bool     { return !empty($this->items); }
    public function first(mixed $default = null): mixed { return $this->items[0] ?? $default; }
    public function last(mixed $default = null): mixed  { return !empty($this->items) ? end($this->items) : $default; }
    public function toArray(): array       { return $this->items; }
    public function toJson(): string       { return json_encode($this->items, JSON_UNESCAPED_UNICODE); }

    public function map(callable $callback): static
    {
        return new static(array_map($callback, $this->items));
    }

    public function filter(?callable $callback = null): static
    {
        return new static(array_values(
            $callback ? array_filter($this->items, $callback) : array_filter($this->items)
        ));
    }

    public function each(callable $callback): static
    {
        foreach ($this->items as $key => $item) {
            $callback($item, $key);
        }
        return $this;
    }

    public function pluck(string $key): static
    {
        return new static(array_column($this->items, $key));
    }

    public function where(string $key, mixed $value): static
    {
        return $this->filter(fn ($item) => (is_array($item) ? $item[$key] : $item->$key ?? null) === $value);
    }

    public function sortBy(string $key, string $direction = 'asc'): static
    {
        $items = $this->items;
        usort($items, function ($a, $b) use ($key, $direction) {
            $va = is_array($a) ? $a[$key] : $a->$key ?? null;
            $vb = is_array($b) ? $b[$key] : $b->$key ?? null;
            return $direction === 'asc' ? $va <=> $vb : $vb <=> $va;
        });
        return new static($items);
    }

    public function unique(?string $key = null): static
    {
        if ($key) {
            $seen  = [];
            $items = [];
            foreach ($this->items as $item) {
                $v = is_array($item) ? $item[$key] : $item->$key ?? null;
                if (!in_array($v, $seen, true)) {
                    $seen[]  = $v;
                    $items[] = $item;
                }
            }
            return new static($items);
        }
        return new static(array_values(array_unique($this->items)));
    }

    public function chunk(int $size): static
    {
        return new static(array_chunk($this->items, $size));
    }

    public function take(int $limit): static
    {
        return new static(array_slice($this->items, 0, $limit));
    }

    public function skip(int $count): static
    {
        return new static(array_slice($this->items, $count));
    }

    public function contains(mixed $value): bool
    {
        return in_array($value, $this->items, true);
    }

    public function sum(?string $key = null): int|float
    {
        if ($key) {
            return array_sum(array_column($this->items, $key));
        }
        return array_sum($this->items);
    }

    public function avg(?string $key = null): float
    {
        $count = $this->count();
        return $count > 0 ? $this->sum($key) / $count : 0.0;
    }

    public function push(mixed $item): static
    {
        $this->items[] = $item;
        return $this;
    }

    public function merge(array $items): static
    {
        return new static(array_merge($this->items, $items));
    }

    public function keys(): static
    {
        return new static(array_keys($this->items));
    }

    public function values(): static
    {
        return new static(array_values($this->items));
    }

    public function reverse(): static
    {
        return new static(array_reverse($this->items));
    }

    public function shuffle(): static
    {
        $items = $this->items;
        shuffle($items);
        return new static($items);
    }

    public function paginate(int $perPage, int $page = 1): array
    {
        $total  = $this->count();
        $items  = array_slice($this->items, ($page - 1) * $perPage, $perPage);
        return [
            'data'          => $items,
            'total'         => $total,
            'per_page'      => $perPage,
            'current_page'  => $page,
            'last_page'     => (int) ceil($total / $perPage),
            'from'          => (($page - 1) * $perPage) + 1,
            'to'            => min($page * $perPage, $total),
        ];
    }
}

// =============================================================================
// JSON
// =============================================================================

if (!function_exists('to_json')) {
    function to_json(mixed $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}

if (!function_exists('from_json')) {
    function from_json(string $json, bool $assoc = true): mixed
    {
        return json_decode($json, $assoc);
    }
}

// =============================================================================
// DATAS
// =============================================================================

if (!function_exists('now')) {
    function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now');
    }
}

if (!function_exists('format_date')) {
    function format_date(string $date_string, string $format = 'd/m/Y H:i:s'): ?string
    {
        try {
            return (new \DateTimeImmutable($date_string))->format($format);
        } catch (\Exception) {
            return null;
        }
    }
}

if (!function_exists('human_date')) {
    /**
     * Retorna data em formato humano relativo (ex: "há 3 minutos").
     */
    function human_date(string|\DateTimeInterface $date): string
    {
        $dt   = is_string($date) ? new \DateTimeImmutable($date) : $date;
        $diff = (new \DateTimeImmutable())->diff($dt);

        if ($diff->y > 0) return "há {$diff->y} " . ($diff->y === 1 ? 'ano' : 'anos');
        if ($diff->m > 0) return "há {$diff->m} " . ($diff->m === 1 ? 'mês' : 'meses');
        if ($diff->d > 0) return "há {$diff->d} " . ($diff->d === 1 ? 'dia' : 'dias');
        if ($diff->h > 0) return "há {$diff->h} " . ($diff->h === 1 ? 'hora' : 'horas');
        if ($diff->i > 0) return "há {$diff->i} " . ($diff->i === 1 ? 'minuto' : 'minutos');
        return 'agora mesmo';
    }
}

// =============================================================================
// SEGURANÇA
// =============================================================================

if (!function_exists('csrf_token')) {
    /**
     * Retorna o token CSRF atual.
     */
    function csrf_token(): string
    {
        Session::start();
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf_token'];
    }
}

if (!function_exists('csrf_field')) {
    /**
     * Retorna o campo HTML oculto com o token CSRF.
     */
    function csrf_field(): string
    {
        return '<input type="hidden" name="_csrf_token" value="' . csrf_token() . '">';
    }
}

if (!function_exists('method_field')) {
    /**
     * Retorna campo hidden para simular métodos HTTP em forms HTML.
     */
    function method_field(string $method): string
    {
        return '<input type="hidden" name="_method" value="' . strtoupper($method) . '">';
    }
}

if (!function_exists('encrypt')) {
    /**
     * Encripta uma string com APP_KEY.
     */
    function encrypt(string $value): string
    {
        $key  = (string) env('APP_KEY', '');
        $key  = base64_decode(str_replace('base64:', '', $key)) ?: $key;
        $iv   = random_bytes(16);
        $enc  = openssl_encrypt($value, 'AES-256-CBC', $key, 0, $iv);
        return base64_encode($iv . '::' . $enc);
    }
}

if (!function_exists('decrypt')) {
    /**
     * Decripta uma string encriptada com encrypt().
     */
    function decrypt(string $encrypted): string|false
    {
        $key  = (string) env('APP_KEY', '');
        $key  = base64_decode(str_replace('base64:', '', $key)) ?: $key;
        $data = base64_decode($encrypted);
        [$iv, $enc] = explode('::', $data, 2);
        return openssl_decrypt($enc, 'AES-256-CBC', $key, 0, $iv);
    }
}

if (!function_exists('hash_make')) {
    /**
     * Cria hash seguro de uma senha.
     */
    function hash_make(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }
}

if (!function_exists('hash_check')) {
    /**
     * Verifica se senha corresponde ao hash.
     */
    function hash_check(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }
}

// =============================================================================
// DEBUG
// =============================================================================

if (!function_exists('dd')) {
    function dd(mixed ...$vars): never
    {
        foreach ($vars as $var) {
            echo '<pre style="background:#1e1e2e;color:#cdd6f4;padding:1rem;border-radius:6px;font-size:13px;overflow:auto;margin:1rem">';
            var_export($var);
            echo '</pre>';
        }
        exit;
    }
}

if (!function_exists('dump')) {
    function dump(mixed ...$vars): void
    {
        foreach ($vars as $var) {
            echo '<pre style="background:#1e1e2e;color:#cdd6f4;padding:1rem;border-radius:6px;font-size:13px;overflow:auto;margin:1rem">';
            var_export($var);
            echo '</pre>';
        }
    }
}

if (!function_exists('benchmark')) {
    /**
     * Retorna o tempo decorrido desde o início da requisição em ms.
     */
    function benchmark(): float
    {
        return round((microtime(true) - (SLENIX_START)) * 1000, 2);
    }
}

// =============================================================================
// LUNA SHARES GLOBAIS
// =============================================================================

// Disponibiliza helpers diretamente nos templates Luna
Luna::share('route', fn (string $name, array $params = []): ?string => Router::route($name, $params));
Luna::share('csrf_token', fn (): string => csrf_token());
Luna::share('csrf_field', fn (): string => csrf_field());
Luna::share('old', fn (string $key, mixed $default = ''): mixed => old($key, $default));
Luna::share('errors', fn (?string $field = null): mixed => errors($field));
Luna::share('has_error', fn (string $field): bool => has_error($field));
Luna::share('flash', fn (): FlashMessage => flash());
Luna::share('is_active', fn (string $pattern, string $a = 'active', string $i = ''): string => is_active($pattern, $a, $i));
Luna::share('asset', fn (string $path): string => asset($path));
Luna::share('url', fn (string $path = '', array $q = []): string => url($path, $q));

Luna::share('Session', [
    'has'      => fn (string $key): bool          => Session::has($key),
    'get'      => fn (string $key, mixed $d=null) => Session::get($key, $d),
    'set'      => fn (string $key, mixed $value)  => Session::set($key, $value),
    'flash'    => fn (string $key, mixed $value)  => Session::flash($key, $value),
    'getFlash' => fn (string $key, mixed $d=null) => Session::getFlash($key, $d),
    'hasFlash' => fn (string $key): bool          => Session::hasFlash($key),
    'remove'   => fn (string $key)                => Session::remove($key),
    'all'      => fn (): array                    => Session::all(),
    'destroy'  => fn ()                           => Session::destroy(),
]);