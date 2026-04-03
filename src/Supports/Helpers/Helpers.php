<?php

/*
|--------------------------------------------------------------------------
| Helpers Globais — Slenix Framework
|--------------------------------------------------------------------------
|
| Ponto de entrada único para todos os helpers do framework. Agrupa por
| categoria as funções auxiliares disponíveis globalmente na aplicação:
| views, redirecionamento, sessão, URL, segurança, strings, arrays,
| datas, ambiente, debug e utilitários gerais.
|
| Carregado automaticamente via composer.json → autoload.files.
| As variáveis globais são registadas automaticamente nos templates Luna
| ao final deste ficheiro.
|
*/

declare(strict_types=1);

use Slenix\Http\Request;
use Slenix\Http\Response;
use Slenix\Http\Routing\Router;
use Slenix\Supports\Cache\Cache;
use Slenix\Supports\Logging\Log;
use Slenix\Supports\Storage\Storage;
use Slenix\Supports\Template\Luna;
use Slenix\Supports\Security\Session;
use Slenix\Supports\Libraries\FlashMessage;
use Slenix\Supports\Validation\Validator;
use Slenix\Supports\Libraries\SessionManager;
use Slenix\Supports\Libraries\RedirectResponse;
use Slenix\Supports\Validation\ValidationException;

// =============================================================================
// CONSTANTES DO PROJETO
// =============================================================================

defined('SLENIX_START') or define('SLENIX_START', microtime(true));
defined('ROOT_PATH') or define('ROOT_PATH', dirname(__DIR__, 3));
defined('APP_PATH') or define('APP_PATH', ROOT_PATH . '/app');
defined('PUBLIC_PATH') or define('PUBLIC_PATH', ROOT_PATH . '/public');
defined('SRC_PATH') or define('SRC_PATH', ROOT_PATH . '/src');
defined('ROUTES_PATH') or define('ROUTES_PATH', ROOT_PATH . '/routes');
defined('VIEWS_PATH') or define('VIEWS_PATH', ROOT_PATH . '/views');
defined('STORAGE_PATH') or define('STORAGE_PATH', ROOT_PATH . '/storage');
defined('CONFIG_PATH') or define('CONFIG_PATH', ROOT_PATH . '/src/Config');

// =============================================================================
// VIEWS
// =============================================================================

if (!function_exists('view')) {
    /**
     * Renderiza um template Luna e envia a resposta HTML para o cliente.
     *
     * @param  string               $template Nome do template a ser renderizado.
     * @param  array<string, mixed> $data     Variáveis disponíveis no template.
     * @return void
     */
    function view(string $template, array $data = []): void
    {
        $luna = new Luna($template, $data);
        echo $luna->render();
    }
}

// =============================================================================
// REDIRECT
// =============================================================================

if (!function_exists('redirect')) {
    /**
     * Retorna um objeto RedirectResponse fluente para redirecionamento HTTP.
     *
     * Quando $url é informada, o redirecionamento é configurado imediatamente.
     * Métodos encadeáveis permitem adicionar flash data antes de redirecionar.
     *
     * Exemplos:
     * ```php
     * redirect('/home');
     * redirect()->back();
     * redirect()->route('login');
     * redirect('/home')->with('success', 'Salvo!');
     * redirect('/home')->withErrors(['email' => 'Inválido']);
     * redirect('/home')->withInput();
     * ```
     *
     * @param  string|null $url    URL de destino (opcional).
     * @param  int         $status Código HTTP do redirecionamento (padrão: 302).
     * @return RedirectResponse    Instância fluente do redirecionamento.
     */
    function redirect(?string $url = null, int $status = 302): RedirectResponse
    {
        $r = new RedirectResponse($status);
        if ($url !== null)
            $r->to($url);
        return $r;
    }
}

// =============================================================================
// FLASH
// =============================================================================

if (!function_exists('flash')) {
    /**
     * Retorna uma instância do gerenciador de mensagens flash.
     *
     * @return FlashMessage Instância para envio de mensagens flash.
     */
    function flash(): FlashMessage
    {
        return new FlashMessage();
    }
}

// =============================================================================
// SESSION
// =============================================================================

if (!function_exists('session')) {
    /**
     * Acessa ou manipula dados da sessão de forma simplificada.
     *
     * Comportamento baseado nos argumentos:
     * - `session()`           → retorna instância de SessionManager
     * - `session('key')`      → Session::get('key')
     * - `session('key', 'v')` → Session::set('key', 'v')
     * - `session(['k' => 'v'])` → set múltiplo
     *
     * @param  string|array<string,mixed>|null $key   Chave ou mapa de valores.
     * @param  mixed                           $value Valor a definir (se $key for string).
     * @return mixed                                  Valor da sessão, instância ou null.
     */
    function session(string|array|null $key = null, mixed $value = null): mixed
    {
        $manager = new SessionManager();

        if ($key === null)
            return $manager;

        if (is_array($key)) {
            foreach ($key as $k => $v)
                Session::set((string) $k, $v);
            return $manager;
        }

        if ($value !== null) {
            Session::set($key, $value);
            return $manager;
        }

        return Session::get($key);
    }
}

// =============================================================================
// OLD INPUT & ERRORS
// =============================================================================

if (!function_exists('old')) {
    /**
     * Recupera o valor antigo de um campo de formulário após redirecionamento.
     *
     * Relê o flash '_old_input' e preserva os dados para uso contínuo no template.
     *
     * @param  string $key     Nome do campo do formulário.
     * @param  mixed  $default Valor padrão se não houver input antigo.
     * @return mixed           Valor antigo do campo ou o padrão definido.
     */
    function old(string $key, mixed $default = ''): mixed
    {
        $oldInput = Session::getFlash('_old_input');
        if (is_array($oldInput) && isset($oldInput[$key])) {
            Session::flash('_old_input', $oldInput);
            return $oldInput[$key];
        }

        $individual = Session::getFlash('_old_input_' . $key);
        return $individual ?? $default;
    }
}

if (!function_exists('errors')) {
    /**
     * Recupera erros de validação armazenados na sessão.
     *
     * - `errors()`              → todos os erros de todos os bags
     * - `errors('email')`       → primeiro erro do campo
     * - `errors('email', true)` → todos os erros do campo como array
     *
     * @param  string|null $field Nome do campo (null para todos os erros).
     * @param  bool        $all   Se true, retorna todos os erros do campo.
     * @return array|string|null  Erros encontrados ou null se não houver.
     */
    function errors(?string $field = null, bool $all = false): array|string|null
    {
        $bags = Session::getFlash('_errors') ?? [];

        if (!empty($bags))
            Session::flash('_errors', $bags);

        if ($field === null) {
            $result = [];
            foreach ($bags as $bag) {
                foreach ((array) $bag as $f => $msg)
                    $result[$f] = $msg;
            }
            return $result;
        }

        foreach ($bags as $bag) {
            if (isset($bag[$field])) {
                return $all
                    ? (array) $bag[$field]
                    : (is_array($bag[$field]) ? $bag[$field][0] : $bag[$field]);
            }
        }

        return $all ? [] : null;
    }
}

if (!function_exists('has_error')) {
    /**
     * Verifica se existe algum erro de validação para o campo informado.
     *
     * @param  string $field Nome do campo a verificar.
     * @return bool          True se existir erro, false caso contrário.
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
     * Gera uma URL absoluta a partir do caminho informado.
     *
     * Combina APP_BASE_URL com o caminho e adiciona query string se fornecida.
     *
     * @param  string               $path  Caminho relativo da URL.
     * @param  array<string, mixed> $query Parâmetros da query string.
     * @return string                      URL absoluta gerada.
     */
    function url(string $path = '', array $query = []): string
    {
        $base = rtrim(env('APP_BASE_URL', ''), '/');
        $path = '/' . ltrim($path, '/');
        $url = $base . $path;

        if (!empty($query))
            $url .= '?' . http_build_query($query);

        return $url;
    }
}

if (!function_exists('asset')) {
    /**
     * Gera a URL pública para um asset estático (CSS, JS, imagens, etc).
     *
     * @param  string $path Caminho do asset relativo à raiz pública.
     * @return string       URL absoluta do asset.
     */
    function asset(string $path): string
    {
        return url($path);
    }
}

if (!function_exists('route')) {
    /**
     * Gera a URL de uma rota nomeada registada no Router.
     *
     * @param  string               $name   Nome da rota.
     * @param  array<string, mixed> $params Parâmetros da rota.
     * @return string|null                  URL gerada ou null se a rota não existir.
     */
    function route(string $name, array $params = []): ?string
    {
        return Router::route($name, $params);
    }
}

if (!function_exists('current_url')) {
    /**
     * Retorna a URL completa da requisição atual (scheme + host + URI).
     *
     * @return string URL completa da requisição atual.
     */
    function current_url(): string
    {
        $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        return "{$scheme}://{$host}{$uri}";
    }
}

if (!function_exists('request_path')) {
    /**
     * Retorna apenas o caminho (path) da URL da requisição atual, sem query string.
     *
     * @return string Caminho da URL atual (ex: '/dashboard/users').
     */
    function request_path(): string
    {
        return parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
    }
}

if (!function_exists('is_active')) {
    /**
     * Retorna uma classe CSS se o caminho atual corresponder ao padrão informado.
     *
     * Suporta correspondência exata e wildcard com asterisco (*).
     *
     * Exemplos:
     * ```php
     * is_active('/home')    // match exato
     * is_active('/blog/*')  // match por prefixo
     * ```
     *
     * @param  string $pattern  Padrão de rota a verificar (aceita wildcard '*' no final).
     * @param  string $active   Classe CSS retornada em caso de match (padrão: 'active').
     * @param  string $inactive Classe CSS retornada se não houver match (padrão: '').
     * @return string           Classe CSS correspondente ao estado da rota.
     */
    function is_active(string $pattern, string $active = 'active', string $inactive = ''): string
    {
        $path = request_path();
        $match = str_ends_with($pattern, '*')
            ? str_starts_with($path, rtrim($pattern, '*'))
            : ($path === $pattern);

        return $match ? $active : $inactive;
    }
}

if (!function_exists('query_string')) {
    /**
     * Gera uma query string mesclando os parâmetros atuais com os fornecidos.
     *
     * @param  array<string, mixed> $merge  Parâmetros a adicionar ou sobrescrever.
     * @param  string[]             $remove Chaves a remover da query string final.
     * @return string                       Query string resultante (sem '?').
     */
    function query_string(array $merge = [], array $remove = []): string
    {
        $params = array_merge($_GET, $merge);
        foreach ($remove as $key)
            unset($params[$key]);
        return http_build_query($params);
    }
}

// =============================================================================
// HTTP / ABORT
// =============================================================================

if (!function_exists('abort')) {
    /**
     * Interrompe a execução e emite uma resposta HTTP de erro.
     *
     * Renderiza a view de erro correspondente ao código HTTP se ela existir
     * em `src/Core/Exceptions/errors/{code}.php`. Para requisições JSON,
     * retorna um objeto JSON com a mensagem de erro. Encerra o script.
     *
     * @param  int    $code    Código de status HTTP do erro (padrão: 500).
     * @param  string $message Mensagem de erro personalizada (opcional).
     * @return never
     */
    function abort(int $code = 500, string $message = ''): never
    {
        $texts = [
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            408 => 'Request Timeout',
            409 => 'Conflict',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
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
     * Interrompe a execução com erro HTTP se a condição for verdadeira.
     *
     * @param  bool   $condition Condição a avaliar.
     * @param  int    $code      Código HTTP do erro (padrão: 500).
     * @param  string $message   Mensagem de erro personalizada.
     * @return void
     */
    function abort_if(bool $condition, int $code = 500, string $message = ''): void
    {
        if ($condition)
            abort($code, $message);
    }
}

if (!function_exists('abort_unless')) {
    /**
     * Interrompe a execução com erro HTTP se a condição for falsa.
     *
     * @param  bool   $condition Condição a avaliar.
     * @param  int    $code      Código HTTP do erro (padrão: 500).
     * @param  string $message   Mensagem de erro personalizada.
     * @return void
     */
    function abort_unless(bool $condition, int $code = 500, string $message = ''): void
    {
        if (!$condition)
            abort($code, $message);
    }
}

// =============================================================================
// RESPOSTA
// =============================================================================

if (!function_exists('response')) {
    /**
     * Cria e retorna uma instância de Response HTTP.
     *
     * @param  mixed    $content Conteúdo da resposta (opcional).
     * @param  int      $status  Código de status HTTP (padrão: 200).
     * @return Response          Instância da resposta HTTP.
     */
    function response(mixed $content = null, int $status = 200): Response
    {
        $r = new Response();
        $r->status($status);
        if ($content !== null)
            $r->setContent($content);
        return $r;
    }
}

// =============================================================================
// REQUEST
// =============================================================================

if (!function_exists('request')) {
    /**
     * Cria e retorna uma instância de Request HTTP.
     * @param array $params
     * @param array $server
     * @param array $query
     * @param array $cookies
     * @param array $files
     * @return Request
     */
    function request(array $params = [], array $server = [], array $query = [], array $cookies = [], array $files = []): Request
    {
        return new Request($params, $server, $query, $cookies, $files);
    }
}

// =============================================================================
// JSON
// =============================================================================

if (!function_exists('to_json')) {
    /**
     * Converte um valor para JSON com opções de formatação.
     *
     * @param  mixed  $data   Dado a ser serializado.
     * @param  bool   $pretty Se true, formata com indentação (padrão: false).
     * @return string         String JSON resultante.
     */
    function to_json(mixed $data, bool $pretty = false): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if ($pretty)
            $flags |= JSON_PRETTY_PRINT;
        return json_encode($data, $flags);
    }
}

if (!function_exists('from_json')) {
    /**
     * Decodifica uma string JSON para array ou objeto.
     *
     * @param  string $json  String JSON a ser decodificada.
     * @param  bool   $assoc Se true, retorna array associativo (padrão: true).
     * @return mixed         Dado decodificado.
     */
    function from_json(string $json, bool $assoc = true): mixed
    {
        return json_decode($json, $assoc);
    }
}

if (!function_exists('is_json')) {
    /**
     * Verifica se uma string é um JSON válido.
     *
     * @param  string $string String a ser verificada.
     * @return bool           True se for JSON válido, false caso contrário.
     */
    function is_json(string $string): bool
    {
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }
}

// =============================================================================
// NÚMEROS
// =============================================================================

if (!function_exists('currency')) {
    /**
     * Formata um número como valor monetário com símbolo e separadores.
     *
     * Exemplo: `currency(1234.5, 'AOA')` → `'AOA 1.234,50'`
     *
     * @param  float  $value     Valor numérico a formatar.
     * @param  string $symbol    Símbolo da moeda (padrão: 'R$').
     * @param  int    $decimals  Casas decimais (padrão: 2).
     * @param  string $thousands Separador de milhar (padrão: '.').
     * @param  string $decimal   Separador decimal (padrão: ',').
     * @return string            Valor formatado com símbolo e separadores.
     */
    function currency(
        float $value,
        string $symbol = 'R$',
        int $decimals = 2,
        string $thousands = '.',
        string $decimal = ','
    ): string {
        return $symbol . ' ' . number_format($value, $decimals, $decimal, $thousands);
    }
}

if (!function_exists('percent')) {
    /**
     * Formata um número como percentagem com separador decimal em vírgula.
     *
     * @param  float $value    Valor a formatar (ex: 98.5 → '98,5%').
     * @param  int   $decimals Casas decimais (padrão: 1).
     * @return string          Valor formatado com símbolo de percentagem.
     */
    function percent(float $value, int $decimals = 1): string
    {
        return number_format($value, $decimals, ',', '.') . '%';
    }
}

if (!function_exists('clamp')) {
    /**
     * Restringe um valor numérico ao intervalo definido entre min e max.
     *
     * @param  float|int $value Valor a ser limitado.
     * @param  float|int $min   Limite mínimo.
     * @param  float|int $max   Limite máximo.
     * @return float|int        Valor limitado ao intervalo [min, max].
     */
    function clamp(float|int $value, float|int $min, float|int $max): float|int
    {
        return max($min, min($max, $value));
    }
}

if (!function_exists('percentage_of')) {
    /**
     * Calcula a percentagem de um valor em relação a um total.
     *
     * Retorna 0.0 se o total for zero para evitar divisão por zero.
     *
     * @param  float|int $value    Valor parcial.
     * @param  float|int $total    Valor total de referência.
     * @param  int       $decimals Casas decimais do resultado (padrão: 2).
     * @return float               Percentagem calculada.
     */
    function percentage_of(float|int $value, float|int $total, int $decimals = 2): float
    {
        if ($total == 0)
            return 0.0;
        return round(($value / $total) * 100, $decimals);
    }
}

if (!function_exists('ordinal')) {
    /**
     * Retorna o número com o sufixo ordinal em inglês.
     *
     * Exemplos: `ordinal(1)` → `'1st'`, `ordinal(12)` → `'12th'`
     *
     * @param  int    $number Número a ser formatado.
     * @return string         Número com sufixo ordinal em inglês.
     */
    function ordinal(int $number): string
    {
        $abs = abs($number);
        $mod100 = $abs % 100;
        $mod10 = $abs % 10;

        if ($mod100 >= 11 && $mod100 <= 13)
            return $number . 'th';

        return match ($mod10) {
            1 => $number . 'st',
            2 => $number . 'nd',
            3 => $number . 'rd',
            default => $number . 'th',
        };
    }
}

if (!function_exists('roman')) {
    /**
     * Converte um número inteiro para numeral romano.
     *
     * Exemplo: `roman(2024)` → `'MMXXIV'`
     *
     * @param  int    $number Número inteiro positivo a converter.
     * @return string         Representação em numeral romano.
     */
    function roman(int $number): string
    {
        $map = [
            1000 => 'M',
            900 => 'CM',
            500 => 'D',
            400 => 'CD',
            100 => 'C',
            90 => 'XC',
            50 => 'L',
            40 => 'XL',
            10 => 'X',
            9 => 'IX',
            5 => 'V',
            4 => 'IV',
            1 => 'I',
        ];

        $result = '';
        foreach ($map as $value => $numeral) {
            while ($number >= $value) {
                $result .= $numeral;
                $number -= $value;
            }
        }
        return $result;
    }
}

// =============================================================================
// UTILITÁRIOS FUNCIONAIS
// =============================================================================

if (!function_exists('value')) {
    /**
     * Retorna o valor passado. Se for callable, executa-o e retorna o resultado.
     *
     * @param  mixed $value Valor ou callable a ser executado.
     * @param  mixed ...$args Argumentos passados ao callable.
     * @return mixed          Valor original ou resultado do callable.
     */
    function value(mixed $value, mixed ...$args): mixed
    {
        return is_callable($value) ? $value(...$args) : $value;
    }
}

if (!function_exists('tap')) {
    /**
     * Executa um callback com o valor e retorna o valor original (side-effect seguro).
     *
     * @param  mixed    $value    Valor a ser passado ao callback.
     * @param  callable $callback Função a ser executada com o valor.
     * @return mixed              O valor original, sem alterações.
     */
    function tap(mixed $value, callable $callback): mixed
    {
        $callback($value);
        return $value;
    }
}

// =============================================================================
// PASSWORDS
// =============================================================================

if (!function_exists('hash_make')) {
    /**
     * Cria hash seguro de uma senha.
     * @example hash_make('minha_senha') → '$2y$12$...'
     */
    function hash_make(string $password, int $cost = 12): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => $cost]);
    }
}

if (!function_exists('hash_check')) {
    /**
     * Verifica se uma senha corresponde ao hash.
     * @example hash_check('minha_senha', $user->password)
     */
    function hash_check(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }
}

// =============================================================================
// AVATAR
// =============================================================================

if (!function_exists('avatar')) {
    /**
     * Gera um avatar SVG inline com as iniciais do nome.
     *
     * @example avatar('Cláudio Victor')
     * @example avatar('Cláudio Victor', 48, '#6366f1', '#fff')
     */
    function avatar(
        string $name,
        int $size = 40,
        string $bg = '#4f46e5',
        string $color = '#ffffff'
    ): string {
        // Extrai iniciais (máx. 2)
        $words = preg_split('/\s+/', trim($name));
        $initials = strtoupper(substr($words[0], 0, 1));
        if (count($words) > 1) {
            $initials .= strtoupper(substr(end($words), 0, 1));
        }

        $font = (int) round($size * 0.38);

        return <<<SVG
        <svg xmlns="http://www.w3.org/2000/svg" width="{$size}" height="{$size}" viewBox="0 0 {$size} {$size}">
        <rect width="{$size}" height="{$size}" rx="{$size}" fill="{$bg}"/>
        <text x="50%" y="50%" dominant-baseline="central" text-anchor="middle"
                font-family="system-ui,sans-serif" font-size="{$font}" font-weight="600" fill="{$color}">{$initials}</text>
        </svg>
        SVG;
    }
}

if (!function_exists('with')) {
    /**
     * Executa um callback com o valor e retorna o resultado do callback.
     *
     * @param  mixed    $value    Valor a ser passado ao callback.
     * @param  callable $callback Função a ser executada com o valor.
     * @return mixed              Resultado retornado pelo callback.
     */
    function with(mixed $value, callable $callback): mixed
    {
        return $callback($value);
    }
}

if (!function_exists('when')) {
    /**
     * Executa um callback apenas se a condição for verdadeira.
     *
     * Opcionalmente executa um callback padrão se a condição for falsa.
     *
     * @param  bool          $condition Condição a avaliar.
     * @param  callable      $callback  Executado se a condição for verdadeira.
     * @param  callable|null $default   Executado se a condição for falsa (opcional).
     * @return mixed                    Resultado do callback correspondente ou null.
     */
    function when(bool $condition, callable $callback, ?callable $default = null): mixed
    {
        if ($condition)
            return $callback();
        return $default ? $default() : null;
    }
}

if (!function_exists('optional')) {
    /**
     * Retorna o valor ou um objeto nulo seguro para evitar erros de null pointer.
     *
     * O objeto nulo retornado aceita qualquer acesso de propriedade ou chamada
     * de método sem lançar exceção, retornando sempre null.
     *
     * @param  mixed $value Valor a ser avaliado.
     * @return mixed        O valor original ou um objeto nulo seguro.
     */
    function optional(mixed $value): mixed
    {
        return $value ?? new class {
            public function __get(string $name): null
            {
                return null;
            }
            public function __call(string $name, array $args): null
            {
                return null;
            }
        };
    }
}

if (!function_exists('retry')) {
    /**
     * Executa um callable com múltiplas tentativas antes de lançar a exceção.
     *
     * @param  int      $times   Número máximo de tentativas.
     * @param  callable $callback Função a ser executada. Recebe o número da tentativa.
     * @param  int      $sleepMs Tempo de espera em milissegundos entre tentativas (padrão: 0).
     * @return mixed             Resultado do callable em caso de sucesso.
     *
     * @throws \Throwable Relança a última exceção após esgotar todas as tentativas.
     */
    function retry(int $times, callable $callback, int $sleepMs = 0): mixed
    {
        $attempt = 0;
        while (true) {
            try {
                return $callback($attempt);
            } catch (\Throwable $e) {
                $attempt++;
                if ($attempt >= $times)
                    throw $e;
                if ($sleepMs > 0)
                    usleep($sleepMs * 1000);
            }
        }
    }
}

if (!function_exists('memoize')) {
    /**
     * Memoriza e reutiliza o resultado de um callable por chave estática.
     *
     * O resultado é armazenado em memória durante o ciclo de vida do processo.
     * Chamadas subsequentes com a mesma chave retornam o valor em cache.
     *
     * @param  string   $key      Chave única para identificar o valor em cache.
     * @param  callable $callback Função cujo resultado será memorizado.
     * @return mixed              Resultado do callable (cacheado após a primeira execução).
     */
    function memoize(string $key, callable $callback): mixed
    {
        static $cache = [];
        if (!array_key_exists($key, $cache)) {
            $cache[$key] = $callback();
        }
        return $cache[$key];
    }
}

if (!function_exists('pipe')) {
    /**
     * Passa um valor por uma cadeia de callables em sequência.
     *
     * Cada callable recebe o resultado do anterior como argumento.
     *
     * Exemplo: `pipe('hello', 'strtoupper', 'trim')` → `'HELLO'`
     *
     * @param  mixed    $value  Valor inicial da cadeia.
     * @param  callable ...$fns Funções a aplicar em sequência.
     * @return mixed            Resultado final após todas as transformações.
     */
    function pipe(mixed $value, callable ...$fns): mixed
    {
        return array_reduce($fns, fn($carry, $fn) => $fn($carry), $value);
    }
}

if (!function_exists('class_basename')) {
    /**
     * Retorna o nome curto de uma classe sem o namespace.
     *
     * Exemplo: `class_basename('App\Models\User')` → `'User'`
     *
     * @param  string|object $class Nome completo da classe ou instância.
     * @return string                Nome simples da classe.
     */
    function class_basename(string|object $class): string
    {
        $class = is_object($class) ? get_class($class) : $class;
        return basename(str_replace('\\', '/', $class));
    }
}

if (!function_exists('data_get')) {
    /**
     * Obtém um valor de estruturas aninhadas usando dot-notation e wildcard.
     *
     * Exemplo: `data_get($users, '*.name')` → `['Cláudio', 'Victor']`
     *
     * @param  mixed          $target  Array ou objeto alvo.
     * @param  string|int|null $key    Chave em dot-notation ou índice (null retorna o alvo).
     * @param  mixed          $default Valor padrão se a chave não for encontrada.
     * @return mixed                   Valor encontrado ou o padrão definido.
     */
    function data_get(mixed $target, string|int|null $key, mixed $default = null): mixed
    {
        if ($key === null)
            return $target;

        $keys = is_string($key) ? explode('.', $key) : [$key];

        foreach ($keys as $segment) {
            if ($segment === '*') {
                if (!is_array($target))
                    return $default;
                $result = [];
                foreach ($target as $item) {
                    $result[] = is_array($item) ? ($item[implode('.', array_splice($keys, 1))] ?? $default) : $default;
                }
                return $result;
            }

            if (is_array($target) && array_key_exists($segment, $target)) {
                $target = $target[$segment];
            } elseif (is_object($target) && property_exists($target, $segment)) {
                $target = $target->$segment;
            } else {
                return $default;
            }
        }

        return $target;
    }
}

// =============================================================================
// STRINGS
// =============================================================================

if (!function_exists('sanitize')) {
    /**
     * Sanitiza uma string removendo espaços e escapando caracteres HTML especiais.
     *
     * @param  string $string String a ser sanitizada.
     * @return string         String sanitizada e segura para exibição em HTML.
     */
    function sanitize(string $string): string
    {
        return trim(htmlspecialchars($string, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }
}

if (!function_exists('validate_name')) {
    /**
     * Verifica se a string contém apenas letras (incluindo acentuadas) e espaços.
     *
     * @param  string $string String a ser validada.
     * @return bool           True se for um nome válido, false caso contrário.
     */
    function validate_name(string $string): bool
    {
        return (bool) preg_match('/^[A-Za-zÀ-ÖØ-öø-ÿ\s]+$/u', $string);
    }
}

if (!function_exists('camel_case')) {
    /**
     * Converte uma string para camelCase.
     *
     * Exemplo: `camel_case('hello_world')` → `'helloWorld'`
     *
     * @param  string $string String a converter.
     * @return string         String em camelCase.
     */
    function camel_case(string $string): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $string))));
    }
}

if (!function_exists('snake_case')) {
    /**
     * Converte uma string para snake_case.
     *
     * Exemplo: `snake_case('HelloWorld')` → `'hello_world'`
     *
     * @param  string $string    String a converter.
     * @param  string $delimiter Separador a usar (padrão: '_').
     * @return string            String em snake_case.
     */
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
    /**
     * Converte uma string para PascalCase.
     *
     * Exemplo: `pascal_case('hello_world')` → `'HelloWorld'`
     *
     * @param  string $string String a converter.
     * @return string         String em PascalCase.
     */
    function pascal_case(string $string): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $string)));
    }
}

if (!function_exists('kebab_case')) {
    /**
     * Converte uma string para kebab-case.
     *
     * Exemplo: `kebab_case('HelloWorld')` → `'hello-world'`
     *
     * @param  string $string String a converter.
     * @return string         String em kebab-case.
     */
    function kebab_case(string $string): string
    {
        return snake_case($string, '-');
    }
}

if (!function_exists('str_default')) {
    /**
     * Retorna o valor padrão se a string estiver vazia ou nula.
     *
     * @param  string|null $string  String a avaliar.
     * @param  string      $default Valor de retorno se a string for vazia.
     * @return string               String original ou o valor padrão.
     */
    function str_default(?string $string, string $default): string
    {
        return empty($string) ? $default : $string;
    }
}

if (!function_exists('limit')) {
    /**
     * Limita o texto a um número máximo de caracteres.
     *
     * @param  string $text  Texto original.
     * @param  int    $limit Número máximo de caracteres.
     * @param  string $end   Sufixo adicionado ao truncar (padrão: '...').
     * @return string        Texto truncado ou original se dentro do limite.
     */
    function limit(string $text, int $limit, string $end = '...'): string
    {
        return mb_strlen($text) > $limit
            ? mb_substr($text, 0, $limit, 'UTF-8') . $end
            : $text;
    }
}

if (!function_exists('limit_words')) {
    /**
     * Limita o texto a um número máximo de palavras.
     *
     * @param  string $text  Texto original.
     * @param  int    $words Número máximo de palavras.
     * @param  string $end   Sufixo adicionado ao truncar (padrão: '...').
     * @return string        Texto truncado ou original se dentro do limite.
     */
    function limit_words(string $text, int $words, string $end = '...'): string
    {
        $wordArray = explode(' ', trim($text));
        if (count($wordArray) <= $words)
            return $text;
        return implode(' ', array_slice($wordArray, 0, $words)) . $end;
    }
}

if (!function_exists('str_slug')) {
    /**
     * Converte uma string para um slug URL amigável.
     *
     * Remove acentos, substitui espaços e caracteres especiais pelo separador.
     *
     * Exemplo: `str_slug('Olá Mundo!')` → `'ola-mundo'`
     *
     * @param  string $text      Texto a converter.
     * @param  string $separator Separador entre palavras (padrão: '-').
     * @return string            Slug gerado em minúsculas.
     */
    function str_slug(string $text, string $separator = '-'): string
    {
        $text = iconv('UTF-8', 'ASCII//TRANSLIT', $text) ?: $text;
        $text = (string) preg_replace('/[^a-zA-Z0-9]+/', $separator, $text);
        return strtolower(trim($text, $separator));
    }
}

if (!function_exists('str_contains_any')) {
    /**
     * Verifica se a string contém pelo menos um dos valores do array.
     *
     * @param  string   $haystack String onde procurar.
     * @param  string[] $needles  Valores a procurar.
     * @return bool               True se algum valor for encontrado.
     */
    function str_contains_any(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle))
                return true;
        }
        return false;
    }
}

if (!function_exists('mask')) {
    /**
     * Mascara parte de uma string com um caractere de substituição.
     *
     * Exemplo: `mask('joao@email.com', '*', 2, 8)` → `'jo********mail.com'`
     *
     * @param  string   $string String original.
     * @param  string   $char   Caractere de máscara (padrão: '*').
     * @param  int      $start  Posição inicial da máscara (padrão: 0).
     * @param  int|null $length Comprimento da máscara (null = até o fim).
     * @return string           String com a porção mascarada.
     */
    function mask(string $string, string $char = '*', int $start = 0, ?int $length = null): string
    {
        $len = mb_strlen($string);
        $length = $length ?? $len - $start;
        $masked = str_repeat($char, min($length, $len - $start));
        return mb_substr($string, 0, $start) . $masked . mb_substr($string, $start + $length);
    }
}

if (!function_exists('str_between')) {
    /**
     * Extrai a substring entre dois delimitadores.
     *
     * Exemplo: `str_between('Hello [World]', '[', ']')` → `'World'`
     *
     * @param  string $string String onde procurar.
     * @param  string $start  Delimitador de início.
     * @param  string $end    Delimitador de fim.
     * @return string         Substring extraída ou string vazia se não encontrada.
     */
    function str_between(string $string, string $start, string $end): string
    {
        $startPos = strpos($string, $start);
        if ($startPos === false)
            return '';
        $startPos += strlen($start);
        $endPos = strpos($string, $end, $startPos);
        if ($endPos === false)
            return '';
        return substr($string, $startPos, $endPos - $startPos);
    }
}

if (!function_exists('str_after')) {
    /**
     * Retorna tudo após a primeira ocorrência da substring buscada.
     *
     * Retorna a string original se a substring não for encontrada.
     *
     * @param  string $string String original.
     * @param  string $search Substring de referência.
     * @return string         Porção após a primeira ocorrência.
     */
    function str_after(string $string, string $search): string
    {
        $pos = strpos($string, $search);
        return $pos !== false ? substr($string, $pos + strlen($search)) : $string;
    }
}

if (!function_exists('str_before')) {
    /**
     * Retorna tudo antes da primeira ocorrência da substring buscada.
     *
     * Retorna a string original se a substring não for encontrada.
     *
     * @param  string $string String original.
     * @param  string $search Substring de referência.
     * @return string         Porção antes da primeira ocorrência.
     */
    function str_before(string $string, string $search): string
    {
        $pos = strpos($string, $search);
        return $pos !== false ? substr($string, 0, $pos) : $string;
    }
}

if (!function_exists('str_pad_left')) {
    /**
     * Preenche a string à esquerda até atingir o comprimento desejado.
     *
     * @param  string $string String original.
     * @param  int    $length Comprimento final desejado.
     * @param  string $pad    Caractere de preenchimento (padrão: ' ').
     * @return string         String preenchida à esquerda.
     */
    function str_pad_left(string $string, int $length, string $pad = ' '): string
    {
        return str_pad($string, $length, $pad, STR_PAD_LEFT);
    }
}

if (!function_exists('str_pad_right')) {
    /**
     * Preenche a string à direita até atingir o comprimento desejado.
     *
     * @param  string $string String original.
     * @param  int    $length Comprimento final desejado.
     * @param  string $pad    Caractere de preenchimento (padrão: ' ').
     * @return string         String preenchida à direita.
     */
    function str_pad_right(string $string, int $length, string $pad = ' '): string
    {
        return str_pad($string, $length, $pad, STR_PAD_RIGHT);
    }
}

if (!function_exists('starts_with')) {
    /**
     * Verifica se a string começa com um dos valores fornecidos.
     *
     * @param  string          $haystack String a verificar.
     * @param  string|string[] $needles  Valor ou array de valores.
     * @return bool                      True se começar com algum dos valores.
     */
    function starts_with(string $haystack, string|array $needles): bool
    {
        foreach ((array) $needles as $needle) {
            if (str_starts_with($haystack, $needle))
                return true;
        }
        return false;
    }
}

if (!function_exists('ends_with')) {
    /**
     * Verifica se a string termina com um dos valores fornecidos.
     *
     * @param  string          $haystack String a verificar.
     * @param  string|string[] $needles  Valor ou array de valores.
     * @return bool                      True se terminar com algum dos valores.
     */
    function ends_with(string $haystack, string|array $needles): bool
    {
        foreach ((array) $needles as $needle) {
            if (str_ends_with($haystack, $needle))
                return true;
        }
        return false;
    }
}

if (!function_exists('str_random')) {
    /**
     * Gera uma string aleatória criptograficamente segura em hexadecimal.
     *
     * @param  int    $length Comprimento da string gerada (padrão: 16).
     * @return string         String aleatória hexadecimal.
     */
    function str_random(int $length = 16): string
    {
        return substr(bin2hex(random_bytes((int) ceil($length / 2))), 0, $length);
    }
}

if (!function_exists('str_uuid')) {
    /**
     * Gera um UUID v4 aleatório e criptograficamente seguro.
     *
     * Exemplo: `'550e8400-e29b-41d4-a716-446655440000'`
     *
     * @return string UUID v4 no formato padrão (8-4-4-4-12).
     */
    function str_uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

if (!function_exists('pluralize')) {
    /**
     * Retorna a forma singular ou plural de uma palavra com base na contagem.
     *
     * @param  int    $count    Quantidade de itens.
     * @param  string $singular Forma singular da palavra.
     * @param  string $plural   Forma plural da palavra.
     * @return string           Palavra na forma correta para a contagem.
     */
    function pluralize(int $count, string $singular, string $plural): string
    {
        return $count === 1 ? $singular : $plural;
    }
}

if (!function_exists('excerpt')) {
    /**
     * Gera um resumo limpo de HTML, removendo todas as tags e limitando caracteres.
     *
     * @param  string $html  Conteúdo HTML de entrada.
     * @param  int    $limit Número máximo de caracteres (padrão: 160).
     * @param  string $end   Sufixo adicionado ao truncar (padrão: '...').
     * @return string        Resumo em texto puro.
     */
    function excerpt(string $html, int $limit = 160, string $end = '...'): string
    {
        return limit(strip_tags($html), $limit, $end);
    }
}

if (!function_exists('nl2p')) {
    /**
     * Converte quebras de linha duplas em parágrafos HTML.
     *
     * Quebras simples dentro de um parágrafo são convertidas em `<br>`.
     * O conteúdo é escapado contra XSS antes de ser envolvido.
     *
     * @param  string $text Texto simples com quebras de linha.
     * @return string       HTML com parágrafos e quebras de linha.
     */
    function nl2p(string $text): string
    {
        $paragraphs = array_filter(array_map('trim', explode("\n\n", $text)));
        return implode('', array_map(
            fn($p) => '<p>' . nl2br(htmlspecialchars($p, ENT_QUOTES, 'UTF-8')) . '</p>',
            $paragraphs
        ));
    }
}

if (!function_exists('initials')) {
    /**
     * Extrai as iniciais de um nome completo.
     *
     * Exemplo: `initials('Cláudio Victor')` → `'CV'`
     *
     * @param  string $name  Nome completo.
     * @param  int    $limit Número máximo de iniciais a retornar (padrão: 2).
     * @return string        Iniciais em maiúsculas.
     */
    function initials(string $name, int $limit = 2): string
    {
        $words = array_filter(explode(' ', trim($name)));
        $initials = array_map(fn($w) => mb_strtoupper(mb_substr($w, 0, 1)), $words);
        return implode('', array_slice($initials, 0, $limit));
    }
}

if (!function_exists('format_bytes')) {
    /**
     * Formata um valor em bytes para uma unidade legível por humanos.
     *
     * Exemplo: `format_bytes(1536)` → `'1.50 KB'`
     *
     * @param  int    $bytes     Número de bytes a formatar.
     * @param  int    $precision Casas decimais no resultado (padrão: 2).
     * @return string            Valor formatado com unidade (B, KB, MB, GB, TB, PB).
     */
    function format_bytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $bytes = max(0, $bytes);
        $pow = $bytes > 0 ? (int) floor(log($bytes) / log(1024)) : 0;
        $pow = min($pow, count($units) - 1);
        return round($bytes / (1024 ** $pow), $precision) . ' ' . $units[$pow];
    }
}

// =============================================================================
// SEGURANÇA
// =============================================================================

if (!function_exists('csrf_token')) {
    /**
     * Retorna o token CSRF da sessão atual, gerando-o se necessário.
     *
     * @return string Token CSRF hexadecimal de 64 caracteres.
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
     * Retorna um campo HTML oculto com o token CSRF para uso em formulários.
     *
     * @return string Tag `<input type="hidden">` com o token CSRF.
     */
    function csrf_field(): string
    {
        return '<input type="hidden" name="_csrf_token" value="' . csrf_token() . '">';
    }
}

if (!function_exists('csrf_meta')) {
    /**
     * Retorna a meta tag para uso em Ajax (colocar no <head>).
     *
     * @return string Tag `<input type="hidden">` com o token CSRF.
     */
    function csrf_meta(): string
    {
        return '<meta name="csrf-token" content="' . csrf_token() . '">';
    }
}

if (!function_exists('csrf_verify')) {
    /**
     * Verifica se o token CSRF da requisição é válido.
     *
     * Aceita o token via POST (`_csrf_token`) ou cabeçalho HTTP (`X-CSRF-Token`).
     * A comparação é feita com `hash_equals` para resistência a timing attacks.
     *
     * @param  string|null $token Token a verificar (null lê automaticamente do request).
     * @return bool               True se o token for válido, false caso contrário.
     */
    function csrf_verify(?string $token = null): bool
    {
        $token ??= $_POST['_csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if (!$token)
            return false;
        Session::start();
        return hash_equals($_SESSION['_csrf_token'] ?? '', $token);
    }
}

if (!function_exists('method_field')) {
    /**
     * Retorna um campo oculto para simular métodos HTTP em formulários HTML.
     *
     * Necessário para métodos PUT, PATCH e DELETE em formulários HTML padrão.
     *
     * @param  string $method Método HTTP a simular (PUT, PATCH, DELETE, etc).
     * @return string         Tag `<input type="hidden">` com o método.
     */
    function method_field(string $method): string
    {
        return '<input type="hidden" name="_method" value="' . strtoupper($method) . '">';
    }
}

if (!function_exists('encrypt')) {
    /**
     * Encripta uma string usando AES-256-GCM com a APP_KEY da aplicação.
     *
     * O resultado inclui o IV e a tag de autenticação concatenados em base64.
     * Lança RuntimeException se a APP_KEY for inválida ou muito curta.
     *
     * @param  string $value String a ser encriptada.
     * @return string        Dados encriptados codificados em base64.
     *
     * @throws \RuntimeException Se APP_KEY for inválida ou muito curta.
     */
    function encrypt(string $value): string
    {
        $key = (string) env('APP_KEY', '');
        $key = base64_decode(str_replace('base64:', '', $key)) ?: $key;

        if (strlen($key) < 16) {
            throw new \RuntimeException('APP_KEY inválida ou muito curta para encriptação.');
        }

        $iv = random_bytes(12);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $value,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        return base64_encode($iv . '::' . $tag . '::' . $ciphertext);
    }
}

if (!function_exists('decrypt')) {
    /**
     * Decripta uma string previamente encriptada com encrypt().
     *
     * Retorna false se o dado for inválido ou corrompido.
     *
     * @param  string $encrypted Dado encriptado em base64.
     * @return string|false      String original ou false em caso de falha.
     */
    function decrypt(string $encrypted): string|false
    {
        $key = (string) env('APP_KEY', '');
        $key = base64_decode(str_replace('base64:', '', $key)) ?: $key;

        $data = base64_decode($encrypted);
        if (!$data)
            return false;

        $parts = explode('::', $data, 3);
        if (count($parts) !== 3)
            return false;

        [$iv, $tag, $ciphertext] = $parts;

        return openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
    }
}

if (!function_exists('hash_make')) {
    /**
     * Cria um hash seguro de uma senha usando bcrypt.
     *
     * @param  string $password Senha em texto puro.
     * @param  int    $cost     Custo do bcrypt (padrão: 12).
     * @return string           Hash bcrypt da senha.
     */
    function hash_make(string $password, int $cost = 12): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => $cost]);
    }
}

if (!function_exists('hash_check')) {
    /**
     * Verifica se uma senha corresponde a um hash bcrypt.
     *
     * @param  string $password Senha em texto puro.
     * @param  string $hash     Hash bcrypt armazenado.
     * @return bool             True se a senha for válida, false caso contrário.
     */
    function hash_check(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }
}

if (!function_exists('hash_needs_rehash')) {
    /**
     * Verifica se um hash bcrypt precisa ser atualizado (custo ou algoritmo).
     *
     * @param  string $hash Hash atual a verificar.
     * @param  int    $cost Custo desejado (padrão: 12).
     * @return bool         True se o hash precisar ser regenerado.
     */
    function hash_needs_rehash(string $hash, int $cost = 12): bool
    {
        return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => $cost]);
    }
}

if (!function_exists('secure_compare')) {
    /**
     * Compara duas strings de forma resistente a timing attacks.
     *
     * @param  string $a Primeira string.
     * @param  string $b Segunda string.
     * @return bool      True se as strings forem idênticas.
     */
    function secure_compare(string $a, string $b): bool
    {
        return hash_equals($a, $b);
    }
}

if (!function_exists('generate_token')) {
    /**
     * Gera um token seguro criptograficamente em formato hexadecimal.
     *
     * @param  int    $bytes Número de bytes aleatórios (padrão: 32 → 64 chars hex).
     * @return string        Token hexadecimal.
     */
    function generate_token(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }
}

if (!function_exists('mask_email')) {
    /**
     * Mascara parcialmente um endereço de e-mail para exibição segura.
     *
     * Exemplo: `mask_email('claudio@slenix.com')` → `'cl****@slenix.com'`
     *
     * @param  string $email Endereço de e-mail a mascarar.
     * @return string        E-mail com parte local mascarada.
     */
    function mask_email(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2);
        $visible = max(2, (int) floor(strlen($local) / 3));
        $masked = substr($local, 0, $visible) . str_repeat('*', strlen($local) - $visible);
        return "{$masked}@{$domain}";
    }
}

if (!function_exists('mask_phone')) {
    /**
     * Mascara parcialmente um número de telefone para exibição segura.
     *
     * Exemplo: `mask_phone('244912345678')` → `'244*****5678'`
     *
     * @param  string $phone Número de telefone (com ou sem formatação).
     * @return string        Número com dígitos centrais mascarados.
     */
    function mask_phone(string $phone): string
    {
        $clean = preg_replace('/\D/', '', $phone);
        $len = strlen($clean);
        $start = max(3, (int) floor($len / 4));
        $end = 4;
        $masked = substr($clean, 0, $start)
            . str_repeat('*', $len - $start - $end)
            . substr($clean, -$end);
        return $masked;
    }
}

if (!function_exists('is_safe_url')) {
    /**
     * Verifica se uma URL é segura para redirecionamento (previne open redirect).
     *
     * URLs relativas são sempre consideradas seguras. URLs absolutas são
     * verificadas contra o host permitido.
     *
     * @param  string      $url         URL a verificar.
     * @param  string|null $allowedHost Host permitido (padrão: HTTP_HOST atual).
     * @return bool                     True se a URL for segura para redirecionamento.
     */
    function is_safe_url(string $url, ?string $allowedHost = null): bool
    {
        $parsed = parse_url($url);
        if (!isset($parsed['host']))
            return true;
        $allowed = $allowedHost ?? ($_SERVER['HTTP_HOST'] ?? '');
        return $parsed['host'] === $allowed;
    }
}

if (!function_exists('purify_html')) {
    /**
     * Remove tags HTML potencialmente perigosas para mitigar ataques XSS.
     *
     * Mantém apenas as tags explicitamente permitidas na lista branca.
     * Para proteção avançada, considere usar uma biblioteca dedicada como HTMLPurifier.
     *
     * @param  string   $html         HTML de entrada a ser filtrado.
     * @param  string[] $allowedTags  Tags HTML permitidas no resultado.
     * @return string                 HTML filtrado com apenas as tags permitidas.
     */
    function purify_html(
        string $html,
        array $allowedTags = ['p', 'b', 'i', 'u', 'strong', 'em', 'br', 'ul', 'ol', 'li', 'a']
    ): string {
        return strip_tags($html, $allowedTags);
    }
}

// =============================================================================
// AMBIENTE
// =============================================================================

if (!function_exists('env')) {
    /**
     * Obtém o valor de uma variável de ambiente com suporte a valor padrão.
     *
     * Converte automaticamente strings como "true", "false" e "null"
     * para seus tipos PHP nativos correspondentes.
     *
     * @param  string $key     Nome da variável de ambiente.
     * @param  mixed  $default Valor padrão se a variável não existir.
     * @return mixed           Valor da variável de ambiente ou o padrão.
     */
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === null)
            return $default;

        return match (strtolower((string) $value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $value,
        };
    }
}

if (!function_exists('config')) {
    /**
     * Lê configurações com notação de ponto, mapeada para variáveis de ambiente.
     *
     * Exemplo: `config('app.debug')` → `env('APP_DEBUG')`
     *
     * @param  string $key     Chave em dot-notation.
     * @param  mixed  $default Valor padrão se não encontrada.
     * @return mixed           Valor da variável de ambiente correspondente.
     */
    function config(string $key, mixed $default = null): mixed
    {
        return env(strtoupper(str_replace('.', '_', $key)), $default);
    }
}

if (!function_exists('app_env')) {
    /**
     * Verifica se o ambiente atual da aplicação é um dos valores fornecidos.
     *
     * @param  string ...$environments Ambientes a verificar (ex: 'production', 'local').
     * @return bool                    True se APP_ENV coincidir com algum dos valores.
     */
    function app_env(string ...$environments): bool
    {
        $current = env('APP_ENV', 'local');
        return in_array($current, $environments, true);
    }
}

if (!function_exists('is_debug')) {
    /**
     * Verifica se o modo de debug está ativo (APP_DEBUG=true).
     *
     * @return bool True se o debug estiver ativo.
     */
    function is_debug(): bool
    {
        return (bool) env('APP_DEBUG', false);
    }
}

if (!function_exists('is_production')) {
    /**
     * Verifica se o ambiente atual é produção.
     *
     * @return bool True se APP_ENV for 'production' ou 'prod'.
     */
    function is_production(): bool
    {
        return app_env('production', 'prod');
    }
}

if (!function_exists('is_local')) {
    /**
     * Verifica se o ambiente atual é local/desenvolvimento.
     *
     * @return bool True se APP_ENV for 'local', 'development' ou 'dev'.
     */
    function is_local(): bool
    {
        return app_env('local', 'development', 'dev');
    }
}

// =============================================================================
// CAMINHOS
// =============================================================================

if (!function_exists('base_path')) {
    /**
     * Retorna o caminho absoluto da raiz do projeto.
     *
     * @param  string $path Caminho relativo a concatenar (opcional).
     * @return string       Caminho absoluto na raiz do projeto.
     */
    function base_path(string $path = ''): string
    {
        return ROOT_PATH . ($path ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : '');
    }
}

if (!function_exists('app_path')) {
    /**
     * Retorna o caminho absoluto do diretório `app/`.
     *
     * @param  string $path Caminho relativo a concatenar (opcional).
     * @return string       Caminho absoluto no diretório app.
     */
    function app_path(string $path = ''): string
    {
        return APP_PATH . ($path ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : '');
    }
}

if (!function_exists('public_path')) {
    /**
     * Retorna o caminho absoluto do diretório `public/`.
     *
     * @param  string $path Caminho relativo a concatenar (opcional).
     * @return string       Caminho absoluto no diretório público.
     */
    function public_path(string $path = ''): string
    {
        return PUBLIC_PATH . ($path ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : '');
    }
}

if (!function_exists('storage_path')) {
    /**
     * Retorna o caminho absoluto do diretório `storage/`.
     *
     * @param  string $path Caminho relativo a concatenar (opcional).
     * @return string       Caminho absoluto no diretório de armazenamento.
     */
    function storage_path(string $path = ''): string
    {
        return STORAGE_PATH . ($path ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : '');
    }
}

if (!function_exists('views_path')) {
    /**
     * Retorna o caminho absoluto do diretório `views/`.
     *
     * @param  string $path Caminho relativo a concatenar (opcional).
     * @return string       Caminho absoluto no diretório de views.
     */
    function views_path(string $path = ''): string
    {
        return VIEWS_PATH . ($path ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : '');
    }
}

if (!function_exists('src_path')) {
    /**
     * Retorna o caminho absoluto do diretório `src/`.
     *
     * @param  string $path Caminho relativo a concatenar (opcional).
     * @return string       Caminho absoluto no diretório src.
     */
    function src_path(string $path = ''): string
    {
        return SRC_PATH . ($path ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : '');
    }
}

// =============================================================================
// DEBUG
// =============================================================================

if (!function_exists('dd')) {
    /**
     * Despeja e encerra — exibe os valores com formatação e para a execução.
     *
     * @param  mixed ...$vars Valores a inspecionar.
     * @return never
     */
    function dd(mixed ...$vars): never
    {
        foreach ($vars as $i => $var) {
            echo _slenix_dump_render($var, $i, count($vars));
        }
        exit;
    }
}

if (!function_exists('dump')) {
    /**
     * Exibe os valores com formatação sem encerrar a execução.
     *
     * @param  mixed ...$vars Valores a inspecionar.
     * @return void
     */
    function dump(mixed ...$vars): void
    {
        foreach ($vars as $i => $var) {
            echo _slenix_dump_render($var, $i, count($vars));
        }
    }
}

if (!function_exists('_slenix_dump_render')) {
    function _slenix_dump_render(mixed $var, int $index, int $total): string
    {
        $type    = gettype($var);
        $isLast  = $index === $total - 1;

        $typeColor = match ($type) {
            'string'           => '#a78bfa', // lilás
            'integer', 'double'=> '#34d399', // verde
            'boolean'          => '#fbbf24', // âmbar
            'NULL'             => '#6b7280', // cinza
            'array'            => '#38bdf8', // azul
            'object'           => '#f472b6', // rosa
            default            => '#cdd6f4',
        };

        ob_start();
        var_export($var);
        $raw = ob_get_clean();

        // 1. Highlight com marcadores ANTES de qualquer escape
        $raw = preg_replace("/\b(true|false|null|NULL)\b/",
            '§BOOL§$1§/BOOL§', $raw);
        $raw = preg_replace("/'((?:[^'\\\\]|\\\\.)*)'/",
            "§STR§'$1'§/STR§", $raw);
        $raw = preg_replace('/(?<![\'a-zA-Z_])\b(\d+\.?\d*)\b(?![\'a-zA-Z_])/',
            '§NUM§$1§/NUM§', $raw);
        $raw = preg_replace('/\b(array)\s*\(/i',
            '§ARR§array§/ARR§(', $raw);

        // 2. Escapa HTML (não afeta os marcadores § pois não são chars especiais)
        $raw = htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // 3. Converte marcadores em spans
        $raw = preg_replace('/§BOOL§(.*?)§\/BOOL§/',
            '<span style="color:#fbbf24;font-weight:600">$1</span>', $raw);
        $raw = preg_replace('/§STR§(.*?)§\/STR§/',
            '<span style="color:#a78bfa">$1</span>', $raw);
        $raw = preg_replace('/§NUM§(.*?)§\/NUM§/',
            '<span style="color:#34d399">$1</span>', $raw);
        $raw = preg_replace('/§ARR§(.*?)§\/ARR§/',
            '<span style="color:#38bdf8;font-weight:600">$1</span>', $raw);

        $mb     = $isLast ? '1rem' : '0.5rem';
        $border = '1px solid rgba(255,255,255,0.08)';

        return <<<HTML
<div style="background:#0a0a0a;border:{$border};border-left:3px solid {$typeColor};border-radius:8px;font-family:'JetBrains Mono','Fira Code',monospace;font-size:13px;overflow:auto;margin:0.4rem 1rem {$mb};box-shadow:0 4px 24px rgba(0,0,0,0.5);">
  <div style="display:flex;align-items:center;gap:0.5rem;padding:0.45rem 0.85rem;background:rgba(255,255,255,0.03);border-bottom:{$border};">
    <span style="background:{$typeColor};color:#000;font-size:10px;font-weight:700;padding:1px 8px;border-radius:99px;letter-spacing:0.5px;text-transform:uppercase;">{$type}</span>
    <span style="color:#4b5563;font-size:11px;margin-left:auto;">Slenix</span>
  </div>
  <pre style="margin:0;padding:0.85rem;color:#e2e8f0;line-height:1.7;">{$raw}</pre>
</div>
HTML;
    }
}

if (!function_exists('dj')) {
    /**
     * Exibe os valores como JSON formatado e encerra a execução.
     *
     * Útil para inspecionar arrays e objetos grandes via browser ou cliente HTTP.
     *
     * @param  mixed ...$vars Valores a serializar em JSON.
     * @return never
     */
    function dj(mixed ...$vars): never
    {
        header('Content-Type: application/json; charset=UTF-8');
        $out = count($vars) === 1 ? $vars[0] : $vars;
        echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('benchmark')) {
    /**
     * Retorna o tempo decorrido desde o início da requisição em milissegundos.
     *
     * @return float Tempo decorrido em ms com precisão de 2 casas decimais.
     */
    function benchmark(): float
    {
        return round((microtime(true) - SLENIX_START) * 1000, 2);
    }
}

if (!function_exists('memory_usage')) {
    /**
     * Retorna o uso de memória atual do processo em formato legível.
     *
     * @param  bool   $peak Se true, retorna o pico de uso de memória (padrão: false).
     * @return string       Uso de memória formatado (ex: '4.50 MB').
     */
    function memory_usage(bool $peak = false): string
    {
        $bytes = $peak ? memory_get_peak_usage(true) : memory_get_usage(true);

        if ($bytes < 1024)
            return $bytes . ' B';
        if ($bytes < 1048576)
            return round($bytes / 1024, 2) . ' KB';
        return round($bytes / 1048576, 2) . ' MB';
    }
}

if (!function_exists('log_debug')) {
    /**
     * Escreve uma mensagem no ficheiro de log de debug.
     *
     * Cria o diretório de logs automaticamente se não existir.
     * Usa bloqueio de ficheiro (LOCK_EX) para evitar condições de corrida.
     *
     * @param  mixed  $message Mensagem ou dado a registar (arrays são serializados como JSON).
     * @param  string $channel Nome do canal / ficheiro de log (padrão: 'debug').
     * @return void
     */
    function log_debug(mixed $message, string $channel = 'debug'): void
    {
        if (!defined('STORAGE_PATH'))
            return;

        $logDir = STORAGE_PATH . '/logs';
        $logFile = "{$logDir}/{$channel}.log";

        if (!is_dir($logDir))
            mkdir($logDir, 0755, true);

        $timestamp = date('Y-m-d H:i:s');
        $content = is_string($message) ? $message : json_encode($message, JSON_UNESCAPED_UNICODE);
        $line = "[{$timestamp}] {$content}" . PHP_EOL;

        file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }
}

if (!function_exists('log_error')) {
    /**
     * Regista um erro ou exceção no log de erros.
     *
     * Aceita tanto uma string de mensagem quanto uma instância de Throwable,
     * extraindo automaticamente código, mensagem, ficheiro e linha.
     *
     * @param  string|\Throwable $error   Mensagem de erro ou exceção capturada.
     * @param  string            $channel Canal de log de destino (padrão: 'error').
     * @return void
     */
    function log_error(string|\Throwable $error, string $channel = 'error'): void
    {
        $message = $error instanceof \Throwable
            ? "[{$error->getCode()}] {$error->getMessage()} in {$error->getFile()}:{$error->getLine()}"
            : $error;

        log_debug($message, $channel);
    }
}

if (!function_exists('trace')) {
    /**
     * Retorna o backtrace de chamadas formatado como string.
     *
     * @param  int    $limit Número máximo de frames a incluir (padrão: 5).
     * @return string        Backtrace formatado com classe, método, ficheiro e linha.
     */
    function trace(int $limit = 5): string
    {
        $traces = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $limit + 1);
        array_shift($traces);
        $output = '';
        foreach ($traces as $i => $frame) {
            $file = $frame['file'] ?? 'unknown';
            $line = $frame['line'] ?? 0;
            $function = $frame['function'] ?? '';
            $class = isset($frame['class']) ? $frame['class'] . '::' : '';
            $output .= "#{$i} {$class}{$function} [{$file}:{$line}]\n";
        }
        return $output;
    }
}

// =============================================================================
// DATAS
// =============================================================================

if (!function_exists('now')) {
    /**
     * Retorna a data e hora atuais como DateTimeImmutable.
     *
     * @param  \DateTimeZone|null $timezone Timezone desejada (opcional).
     * @return \DateTimeImmutable           Data e hora atuais.
     */
    function now(?\DateTimeZone $timezone = null): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', $timezone);
    }
}

if (!function_exists('today')) {
    /**
     * Retorna a data de hoje (meia-noite) como DateTimeImmutable.
     *
     * @param  \DateTimeZone|null $timezone Timezone desejada (opcional).
     * @return \DateTimeImmutable           Data de hoje à meia-noite.
     */
    function today(?\DateTimeZone $timezone = null): \DateTimeImmutable
    {
        return new \DateTimeImmutable('today', $timezone);
    }
}

if (!function_exists('format_date')) {
    /**
     * Formata uma string de data no formato especificado.
     *
     * @param  string $date_string String de data a formatar.
     * @param  string $format      Formato de saída (padrão: 'd/m/Y H:i:s').
     * @return string|null         Data formatada ou null se a string for inválida.
     */
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
     * Retorna a data em formato relativo legível por humanos.
     *
     * Exemplo: `human_date('2024-01-01')` → `'há 3 meses'`
     *
     * @param  string|\DateTimeInterface $date   Data a comparar com o momento atual.
     * @param  string                    $locale Locale para o formato (padrão: 'pt').
     * @return string                            Texto relativo em português.
     */
    function human_date(string|\DateTimeInterface $date, string $locale = 'pt'): string
    {
        $dt = is_string($date) ? new \DateTimeImmutable($date) : $date;
        $diff = (new \DateTimeImmutable())->diff($dt);
        $past = $diff->invert === 1;

        if ($diff->y > 0)
            $str = "{$diff->y} " . ($diff->y === 1 ? 'ano' : 'anos');
        elseif ($diff->m > 0)
            $str = "{$diff->m} " . ($diff->m === 1 ? 'mês' : 'meses');
        elseif ($diff->d > 0)
            $str = "{$diff->d} " . ($diff->d === 1 ? 'dia' : 'dias');
        elseif ($diff->h > 0)
            $str = "{$diff->h} " . ($diff->h === 1 ? 'hora' : 'horas');
        elseif ($diff->i > 0)
            $str = "{$diff->i} " . ($diff->i === 1 ? 'minuto' : 'minutos');
        else
            return 'agora mesmo';

        return $past ? "há {$str}" : "em {$str}";
    }
}

if (!function_exists('diff_in_days')) {
    /**
     * Calcula a diferença em dias entre duas datas.
     *
     * @param  string|\DateTimeInterface $from Data de início.
     * @param  string|\DateTimeInterface $to   Data de fim.
     * @return int                             Número de dias de diferença.
     */
    function diff_in_days(string|\DateTimeInterface $from, string|\DateTimeInterface $to): int
    {
        $from = is_string($from) ? new \DateTimeImmutable($from) : $from;
        $to = is_string($to) ? new \DateTimeImmutable($to) : $to;
        return (int) $from->diff($to)->days;
    }
}

if (!function_exists('add_days')) {
    /**
     * Adiciona um número de dias a uma data e retorna a nova data.
     *
     * @param  \DateTimeInterface $date Data de referência.
     * @param  int                $days Número de dias a adicionar.
     * @return \DateTimeImmutable       Nova data com os dias adicionados.
     */
    function add_days(\DateTimeInterface $date, int $days): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($date)->modify("{$days} days");
    }
}

if (!function_exists('is_past')) {
    /**
     * Verifica se uma data está no passado em relação ao momento atual.
     *
     * @param  string|\DateTimeInterface $date Data a verificar.
     * @return bool                           True se a data for anterior ao momento atual.
     */
    function is_past(string|\DateTimeInterface $date): bool
    {
        $dt = is_string($date) ? new \DateTimeImmutable($date) : $date;
        return $dt < new \DateTimeImmutable();
    }
}

if (!function_exists('is_future')) {
    /**
     * Verifica se uma data está no futuro em relação ao momento atual.
     *
     * @param  string|\DateTimeInterface $date Data a verificar.
     * @return bool                           True se a data for posterior ao momento atual.
     */
    function is_future(string|\DateTimeInterface $date): bool
    {
        $dt = is_string($date) ? new \DateTimeImmutable($date) : $date;
        return $dt > new \DateTimeImmutable();
    }
}

if (!function_exists('is_today')) {
    /**
     * Verifica se uma data corresponde ao dia de hoje.
     *
     * @param  string|\DateTimeInterface $date Data a verificar.
     * @return bool                           True se a data for hoje.
     */
    function is_today(string|\DateTimeInterface $date): bool
    {
        $dt = is_string($date) ? new \DateTimeImmutable($date) : $date;
        return $dt->format('Y-m-d') === (new \DateTimeImmutable())->format('Y-m-d');
    }
}

if (!function_exists('timestamp')) {
    /**
     * Retorna o timestamp Unix de uma string de data.
     *
     * @param  string $date String de data (padrão: 'now').
     * @return int          Timestamp Unix correspondente.
     */
    function timestamp(string $date = 'now'): int
    {
        return (new \DateTimeImmutable($date))->getTimestamp();
    }
}

if (!function_exists('date_range')) {
    /**
     * Gera um array de datas entre dois períodos com intervalo configurável.
     *
     * @param  string|\DateTimeInterface $start  Data de início.
     * @param  string|\DateTimeInterface $end    Data de fim (inclusiva).
     * @param  string                    $step   Intervalo entre datas (padrão: '+1 day').
     * @param  string                    $format Formato de saída das datas (padrão: 'Y-m-d').
     * @return string[]                          Array de datas no formato especificado.
     */
    function date_range(
        string|\DateTimeInterface $start,
        string|\DateTimeInterface $end,
        string $step = '+1 day',
        string $format = 'Y-m-d'
    ): array {
        $start = is_string($start) ? new \DateTimeImmutable($start) : \DateTimeImmutable::createFromInterface($start);
        $end = is_string($end) ? new \DateTimeImmutable($end) : \DateTimeImmutable::createFromInterface($end);
        $current = $start;
        $dates = [];

        while ($current <= $end) {
            $dates[] = $current->format($format);
            $current = $current->modify($step);
        }

        return $dates;
    }
}

if (!function_exists('business_days')) {
    /**
     * Calcula o número de dias úteis entre duas datas, excluindo fins de semana.
     *
     * Não leva feriados nacionais em consideração — apenas sábado e domingo.
     *
     * @param  string|\DateTimeInterface $from Data de início.
     * @param  string|\DateTimeInterface $to   Data de fim (inclusiva).
     * @return int                             Número de dias úteis no intervalo.
     */
    function business_days(string|\DateTimeInterface $from, string|\DateTimeInterface $to): int
    {
        $from = is_string($from) ? new \DateTimeImmutable($from) : \DateTimeImmutable::createFromInterface($from);
        $to = is_string($to) ? new \DateTimeImmutable($to) : \DateTimeImmutable::createFromInterface($to);
        $days = 0;
        $current = $from;

        while ($current <= $to) {
            if ((int) $current->format('N') < 6)
                $days++;
            $current = $current->modify('+1 day');
        }

        return $days;
    }
}

// =============================================================================
// ARRAYS
// =============================================================================

if (!function_exists('is_empty')) {
    /**
     * Verifica se um valor é considerado vazio (null, string vazia ou array vazio).
     *
     * @param  mixed $value Valor a verificar.
     * @return bool         True se o valor for null, '' ou [].
     */
    function is_empty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }
}

if (!function_exists('array_get')) {
    /**
     * Obtém um valor de um array com suporte a dot-notation.
     *
     * Exemplo: `array_get($user, 'address.city', 'N/A')`
     *
     * @param  array      $array   Array de origem.
     * @param  string|int $key     Chave ou chave em dot-notation.
     * @param  mixed      $default Valor padrão se não encontrado.
     * @return mixed               Valor encontrado ou padrão.
     */
    function array_get(array $array, string|int $key, mixed $default = null): mixed
    {
        if (isset($array[$key]))
            return $array[$key];

        if (is_string($key) && str_contains($key, '.')) {
            $current = $array;
            foreach (explode('.', $key) as $k) {
                if (!is_array($current) || !array_key_exists($k, $current))
                    return $default;
                $current = $current[$k];
            }
            return $current;
        }

        return $default;
    }
}

if (!function_exists('array_set')) {
    /**
     * Define um valor em um array usando dot-notation, criando as chaves necessárias.
     *
     * @param  array  $array Array de destino (passado por referência).
     * @param  string $key   Chave em dot-notation.
     * @param  mixed  $value Valor a definir.
     * @return void
     */
    function array_set(array &$array, string $key, mixed $value): void
    {
        $keys = explode('.', $key);
        $current = &$array;
        foreach ($keys as $k) {
            if (!isset($current[$k]) || !is_array($current[$k]))
                $current[$k] = [];
            $current = &$current[$k];
        }
        $current = $value;
    }
}

if (!function_exists('array_forget')) {
    /**
     * Remove uma chave de um array usando dot-notation.
     *
     * @param  array  $array Array de destino (passado por referência).
     * @param  string $key   Chave em dot-notation a remover.
     * @return void
     */
    function array_forget(array &$array, string $key): void
    {
        $keys = explode('.', $key);
        $current = &$array;
        while (count($keys) > 1) {
            $k = array_shift($keys);
            if (!isset($current[$k]) || !is_array($current[$k]))
                return;
            $current = &$current[$k];
        }
        unset($current[array_shift($keys)]);
    }
}

if (!function_exists('array_only')) {
    /**
     * Retorna apenas as chaves especificadas de um array.
     *
     * @param  array    $array Array de origem.
     * @param  string[] $keys  Chaves a manter.
     * @return array           Array filtrado com apenas as chaves especificadas.
     */
    function array_only(array $array, array $keys): array
    {
        return array_intersect_key($array, array_flip($keys));
    }
}

if (!function_exists('array_except')) {
    /**
     * Retorna o array sem as chaves especificadas.
     *
     * @param  array    $array Array de origem.
     * @param  string[] $keys  Chaves a remover.
     * @return array           Array sem as chaves especificadas.
     */
    function array_except(array $array, array $keys): array
    {
        return array_diff_key($array, array_flip($keys));
    }
}

if (!function_exists('array_flatten')) {
    /**
     * Achata um array multidimensional em um array unidimensional.
     *
     * @param  array $array Array multidimensional de entrada.
     * @return array        Array com todos os valores em um único nível.
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
     * Garante que o valor seja retornado como array.
     *
     * @param  mixed $value Valor a ser encapsulado.
     * @return array        Array com o valor, ou o próprio valor se já for array.
     */
    function array_wrap(mixed $value): array
    {
        if (is_null($value))
            return [];
        return is_array($value) ? $value : [$value];
    }
}

if (!function_exists('array_pluck')) {
    /**
     * Extrai uma coluna de um array de arrays ou objetos.
     *
     * @param  array       $array   Array de origem.
     * @param  string      $key     Coluna a extrair.
     * @param  string|null $indexBy Coluna a usar como índice do resultado.
     * @return array                Array com os valores da coluna extraída.
     */
    function array_pluck(array $array, string $key, ?string $indexBy = null): array
    {
        $result = [];
        foreach ($array as $item) {
            $value = is_array($item) ? ($item[$key] ?? null) : ($item->$key ?? null);
            if ($indexBy !== null) {
                $index = is_array($item) ? ($item[$indexBy] ?? null) : ($item->$indexBy ?? null);
                $result[$index] = $value;
            } else {
                $result[] = $value;
            }
        }
        return $result;
    }
}

if (!function_exists('array_group_by')) {
    /**
     * Agrupa um array de arrays ou objetos pelo valor de uma chave.
     *
     * @param  array  $array Array de origem.
     * @param  string $key   Chave a usar como critério de agrupamento.
     * @return array         Array agrupado, indexado pelos valores únicos da chave.
     */
    function array_group_by(array $array, string $key): array
    {
        $result = [];
        foreach ($array as $item) {
            $group = is_array($item) ? ($item[$key] ?? null) : ($item->$key ?? null);
            $result[$group][] = $item;
        }
        return $result;
    }
}

if (!function_exists('array_key_first_value')) {
    /**
     * Retorna o valor associado à primeira chave do array.
     *
     * @param  array $array Array de origem.
     * @return mixed        Valor da primeira chave ou null se o array estiver vazio.
     */
    function array_key_first_value(array $array): mixed
    {
        $key = array_key_first($array);
        return $key !== null ? $array[$key] : null;
    }
}

if (!function_exists('array_sum_column')) {
    /**
     * Soma os valores numéricos de uma coluna em um array de arrays.
     *
     * @param  array  $array Array de origem.
     * @param  string $key   Nome da coluna a somar.
     * @return int|float     Soma dos valores da coluna.
     */
    function array_sum_column(array $array, string $key): int|float
    {
        return array_sum(array_column($array, $key));
    }
}

if (!function_exists('array_unique_by')) {
    /**
     * Remove itens duplicados de um array de arrays com base em uma chave.
     *
     * @param  array  $array Array de origem.
     * @param  string $key   Chave a usar para identificar duplicatas.
     * @return array         Array sem itens duplicados pela chave especificada.
     */
    function array_unique_by(array $array, string $key): array
    {
        $seen = [];
        $result = [];
        foreach ($array as $item) {
            $v = is_array($item) ? ($item[$key] ?? null) : ($item->$key ?? null);
            if (!in_array($v, $seen, true)) {
                $seen[] = $v;
                $result[] = $item;
            }
        }
        return $result;
    }
}

if (!function_exists('array_paginate')) {
    /**
     * Pagina um array retornando os dados e metadados da paginação.
     *
     * @param  array $array   Array a paginar.
     * @param  int   $perPage Itens por página.
     * @param  int   $page    Página atual (padrão: 1).
     * @return array{
     *     data: array,
     *     total: int,
     *     per_page: int,
     *     current_page: int,
     *     last_page: int,
     *     from: int,
     *     to: int,
     *     has_more: bool
     * } Resultado paginado com metadados.
     */
    function array_paginate(array $array, int $perPage, int $page = 1): array
    {
        $total = count($array);
        $items = array_slice($array, ($page - 1) * $perPage, $perPage);
        return [
            'data' => $items,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => (int) ceil($total / $perPage),
            'from' => (($page - 1) * $perPage) + 1,
            'to' => min($page * $perPage, $total),
            'has_more' => $page < (int) ceil($total / $perPage),
        ];
    }
}

if (!function_exists('array_map_keys')) {
    /**
     * Aplica um callback às chaves de um array, mantendo os valores.
     *
     * @param  array    $array    Array de origem.
     * @param  callable $callback Função a aplicar em cada chave.
     * @return array              Array com as chaves transformadas.
     */
    function array_map_keys(array $array, callable $callback): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            $result[$callback($key)] = $value;
        }
        return $result;
    }
}

if (!function_exists('collect')) {
    /**
     * Cria uma nova instância de Collection a partir de um array.
     *
     * @param  array $items Itens iniciais da coleção.
     * @return Collection   Nova instância de Collection.
     */
    function collect(array $items = []): Collection
    {
        return new Collection($items);
    }
}

// =============================================================================
// COLLECTION
// =============================================================================

/**
 * Coleção fluente e encadeável para manipulação de arrays.
 *
 * Implementa uma API expressiva e imutável para transformar, filtrar, ordenar
 * e agregar dados. Cada operação retorna uma nova instância, preservando o
 * estado original. Inspirada na Collection do Laravel.
 *
 * Exemplo de uso:
 * ```php
 * collect($users)
 *     ->where('active', true)
 *     ->sortBy('name')
 *     ->pluck('email')
 *     ->take(10)
 *     ->toArray();
 * ```
 *
 * @package Slenix\Supports\Helpers
 */
class Collection
{
    // =========================================================================
    // PROPRIEDADES
    // =========================================================================

    /**
     * Itens armazenados na coleção.
     *
     * @var array<int|string, mixed>
     */
    private array $items;

    // =========================================================================
    // CONSTRUTOR E FACTORY
    // =========================================================================

    /**
     * Inicializa a coleção com um array de itens.
     *
     * @param array<int|string, mixed> $items Itens iniciais da coleção.
     */
    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    /**
     * Cria uma nova instância de Collection de forma estática.
     *
     * @param  array $items Itens iniciais.
     * @return static       Nova instância de Collection.
     */
    public static function make(array $items = []): self
    {
        return new self($items);
    }

    // =========================================================================
    // ACESSO
    // =========================================================================

    /**
     * Retorna todos os itens da coleção como array.
     *
     * @return array<int|string, mixed>
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * Retorna o número total de itens na coleção.
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Verifica se a coleção está vazia.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return empty($this->items);
    }

    /**
     * Verifica se a coleção não está vazia.
     *
     * @return bool
     */
    public function isNotEmpty(): bool
    {
        return !empty($this->items);
    }

    /**
     * Retorna o primeiro item da coleção.
     *
     * @param  mixed $default Valor padrão se a coleção estiver vazia.
     * @return mixed
     */
    public function first(mixed $default = null): mixed
    {
        return $this->items[array_key_first($this->items) ?? 0] ?? $default;
    }

    /**
     * Retorna o último item da coleção.
     *
     * @param  mixed $default Valor padrão se a coleção estiver vazia.
     * @return mixed
     */
    public function last(mixed $default = null): mixed
    {
        return !empty($this->items) ? end($this->items) : $default;
    }

    /**
     * Retorna o item associado à chave informada.
     *
     * @param  int|string $key     Chave do item.
     * @param  mixed      $default Valor padrão se a chave não existir.
     * @return mixed
     */
    public function get(int|string $key, mixed $default = null): mixed
    {
        return $this->items[$key] ?? $default;
    }

    /**
     * Verifica se a chave informada existe na coleção.
     *
     * @param  int|string $key Chave a verificar.
     * @return bool
     */
    public function has(int|string $key): bool
    {
        return array_key_exists($key, $this->items);
    }

    /**
     * Retorna os itens como array.
     *
     * @return array<int|string, mixed>
     */
    public function toArray(): array
    {
        return $this->items;
    }

    /**
     * Serializa os itens da coleção para JSON.
     *
     * @param  int    $flags Flags de json_encode (padrão: JSON_UNESCAPED_UNICODE).
     * @return string        JSON resultante.
     */
    public function toJson(int $flags = JSON_UNESCAPED_UNICODE): string
    {
        return json_encode($this->items, $flags);
    }

    // =========================================================================
    // TRANSFORMAÇÃO
    // =========================================================================

    /**
     * Aplica um callback a cada item e retorna uma nova coleção com os resultados.
     *
     * @param  callable $callback Função de transformação.
     * @return static             Nova coleção com os itens transformados.
     */
    public function map(callable $callback): static
    {
        return new static(array_map($callback, $this->items));
    }

    /**
     * Transforma a coleção mapeando itens para pares chave/valor.
     *
     * O callback deve retornar um array associativo `[chave => valor]`.
     *
     * @param  callable $callback Função que retorna `[chave => valor]`.
     * @return static             Nova coleção com chaves personalizadas.
     */
    public function mapWithKeys(callable $callback): static
    {
        $result = [];
        foreach ($this->items as $key => $item) {
            $pair = $callback($item, $key);
            if (is_array($pair)) {
                foreach ($pair as $k => $v)
                    $result[$k] = $v;
            }
        }
        return new static($result);
    }

    /**
     * Filtra os itens da coleção usando um callback booleano.
     *
     * Sem callback, remove valores "falsy" (false, null, '', 0, []).
     *
     * @param  callable|null $callback Função de filtragem (opcional).
     * @return static                  Nova coleção com os itens filtrados.
     */
    public function filter(?callable $callback = null): static
    {
        return new static(array_values(
            $callback ? array_filter($this->items, $callback) : array_filter($this->items)
        ));
    }

    /**
     * Retorna uma nova coleção com os itens que NÃO satisfazem o callback.
     *
     * @param  callable $callback Função de rejeição.
     * @return static             Nova coleção com itens rejeitados removidos.
     */
    public function reject(callable $callback): static
    {
        return $this->filter(fn($item) => !$callback($item));
    }

    /**
     * Executa um callback para cada item sem modificar a coleção.
     *
     * Retornar false dentro do callback interrompe a iteração.
     *
     * @param  callable $callback Função a executar por item.
     * @return static             A própria instância (fluente).
     */
    public function each(callable $callback): static
    {
        foreach ($this->items as $key => $item) {
            if ($callback($item, $key) === false)
                break;
        }
        return $this;
    }

    /**
     * Extrai uma coluna dos itens da coleção.
     *
     * @param  string      $key     Nome da coluna a extrair.
     * @param  string|null $indexBy Coluna a usar como índice (opcional).
     * @return static               Nova coleção com os valores extraídos.
     */
    public function pluck(string $key, ?string $indexBy = null): static
    {
        return new static(array_pluck($this->items, $key, $indexBy));
    }

    /**
     * Agrupa os itens pelo valor de uma coluna.
     *
     * @param  string $key Coluna de agrupamento.
     * @return static      Nova coleção com itens agrupados por chave.
     */
    public function groupBy(string $key): static
    {
        return new static(array_group_by($this->items, $key));
    }

    /**
     * Remove itens duplicados da coleção.
     *
     * @param  string|null $key Chave para deduplicação por coluna (opcional).
     * @return static           Nova coleção sem duplicatas.
     */
    public function unique(?string $key = null): static
    {
        return $key
            ? new static(array_unique_by($this->items, $key))
            : new static(array_values(array_unique($this->items)));
    }

    /**
     * Achata a coleção de arrays aninhados em um único nível.
     *
     * @return static Nova coleção com todos os valores em um único nível.
     */
    public function flatten(): static
    {
        return new static(array_flatten($this->items));
    }

    /**
     * Divide a coleção em grupos de tamanho fixo.
     *
     * @param  int    $size Tamanho de cada grupo.
     * @return static       Nova coleção de arrays agrupados.
     */
    public function chunk(int $size): static
    {
        return new static(array_chunk($this->items, $size));
    }

    /**
     * Retorna os primeiros N itens da coleção. Se negativo, retorna os últimos N.
     *
     * @param  int    $limit Número de itens a retornar.
     * @return static        Nova coleção com os itens limitados.
     */
    public function take(int $limit): static
    {
        return $limit >= 0
            ? new static(array_slice($this->items, 0, $limit))
            : new static(array_slice($this->items, $limit));
    }

    /**
     * Ignora os primeiros N itens e retorna o restante.
     *
     * @param  int    $count Número de itens a ignorar.
     * @return static        Nova coleção sem os primeiros N itens.
     */
    public function skip(int $count): static
    {
        return new static(array_slice($this->items, $count));
    }

    /**
     * Retorna uma fatia da coleção a partir de um offset.
     *
     * @param  int      $offset  Posição inicial.
     * @param  int|null $length  Número de itens a incluir (null = até o fim).
     * @return static            Nova coleção com a fatia resultante.
     */
    public function slice(int $offset, ?int $length = null): static
    {
        return new static(array_slice($this->items, $offset, $length));
    }

    // =========================================================================
    // BUSCA
    // =========================================================================

    /**
     * Filtra os itens pelo valor de uma coluna com suporte a operadores de comparação.
     *
     * Operadores suportados: `=`, `==`, `===`, `!=`, `!==`, `>`, `>=`, `<`, `<=`
     *
     * @param  string $key      Coluna a comparar.
     * @param  mixed  $value    Valor de comparação.
     * @param  string $operator Operador de comparação (padrão: '=').
     * @return static           Nova coleção com os itens que satisfazem a condição.
     */
    public function where(string $key, mixed $value, string $operator = '='): static
    {
        return $this->filter(function ($item) use ($key, $value, $operator) {
            $v = is_array($item) ? ($item[$key] ?? null) : ($item->$key ?? null);
            return match ($operator) {
                '=', '==' => $v == $value,
                '===' => $v === $value,
                '!=' => $v != $value,
                '!==' => $v !== $value,
                '>' => $v > $value,
                '>=' => $v >= $value,
                '<' => $v < $value,
                '<=' => $v <= $value,
                default => $v == $value,
            };
        });
    }

    /**
     * Filtra os itens cujo valor de coluna está dentro do array fornecido.
     *
     * @param  string  $key    Coluna a verificar.
     * @param  mixed[] $values Valores permitidos.
     * @return static          Nova coleção com os itens filtrados.
     */
    public function whereIn(string $key, array $values): static
    {
        return $this->filter(fn($item) => in_array(
            is_array($item) ? ($item[$key] ?? null) : ($item->$key ?? null),
            $values,
            true
        ));
    }

    /**
     * Filtra os itens cujo valor de coluna NÃO está dentro do array fornecido.
     *
     * @param  string  $key    Coluna a verificar.
     * @param  mixed[] $values Valores excluídos.
     * @return static          Nova coleção com os itens filtrados.
     */
    public function whereNotIn(string $key, array $values): static
    {
        return $this->filter(fn($item) => !in_array(
            is_array($item) ? ($item[$key] ?? null) : ($item->$key ?? null),
            $values,
            true
        ));
    }

    /**
     * Verifica se a coleção contém um determinado valor ou valor em coluna.
     *
     * @param  mixed       $value Valor a procurar.
     * @param  string|null $key   Coluna onde procurar (opcional).
     * @return bool               True se o valor for encontrado.
     */
    public function contains(mixed $value, ?string $key = null): bool
    {
        if ($key) {
            foreach ($this->items as $item) {
                if ((is_array($item) ? ($item[$key] ?? null) : ($item->$key ?? null)) === $value) {
                    return true;
                }
            }
            return false;
        }
        return in_array($value, $this->items, true);
    }

    /**
     * Procura o índice/chave de um valor na coleção.
     *
     * @param  mixed             $value Valor a procurar.
     * @return int|string|false         Chave encontrada ou false se não existir.
     */
    public function search(mixed $value): int|string|false
    {
        return array_search($value, $this->items, true);
    }

    // =========================================================================
    // ORDENAÇÃO
    // =========================================================================

    /**
     * Ordena os itens pelo valor de uma coluna.
     *
     * @param  string $key       Coluna a usar como critério de ordenação.
     * @param  string $direction Direção: 'asc' ou 'desc' (padrão: 'asc').
     * @return static            Nova coleção com os itens ordenados.
     */
    public function sortBy(string $key, string $direction = 'asc'): static
    {
        $items = $this->items;
        usort($items, function ($a, $b) use ($key, $direction) {
            $va = is_array($a) ? ($a[$key] ?? null) : ($a->$key ?? null);
            $vb = is_array($b) ? ($b[$key] ?? null) : ($b->$key ?? null);
            return $direction === 'asc' ? $va <=> $vb : $vb <=> $va;
        });
        return new static($items);
    }

    /**
     * Ordena os itens pelo valor de uma coluna em ordem decrescente.
     *
     * @param  string $key Coluna de ordenação.
     * @return static      Nova coleção ordenada de forma decrescente.
     */
    public function sortByDesc(string $key): static
    {
        return $this->sortBy($key, 'desc');
    }

    /**
     * Ordena os itens com callback personalizado ou ordenação padrão.
     *
     * @param  callable|null $callback Comparador personalizado (opcional).
     * @return static                  Nova coleção ordenada.
     */
    public function sort(?callable $callback = null): static
    {
        $items = $this->items;
        $callback ? usort($items, $callback) : sort($items);
        return new static($items);
    }

    /**
     * Inverte a ordem dos itens da coleção.
     *
     * @return static Nova coleção com a ordem invertida.
     */
    public function reverse(): static
    {
        return new static(array_reverse($this->items));
    }

    /**
     * Embaralha os itens da coleção de forma aleatória.
     *
     * @return static Nova coleção com os itens em ordem aleatória.
     */
    public function shuffle(): static
    {
        $items = $this->items;
        shuffle($items);
        return new static($items);
    }

    // =========================================================================
    // AGREGAÇÃO
    // =========================================================================

    /**
     * Retorna a soma dos valores da coleção ou de uma coluna específica.
     *
     * @param  string|null $key Nome da coluna (opcional).
     * @return int|float        Soma dos valores.
     */
    public function sum(?string $key = null): int|float
    {
        return $key ? array_sum(array_column($this->items, $key)) : array_sum($this->items);
    }

    /**
     * Retorna a média dos valores da coleção ou de uma coluna específica.
     *
     * @param  string|null $key Nome da coluna (opcional).
     * @return float            Média aritmética dos valores.
     */
    public function avg(?string $key = null): float
    {
        $count = $this->count();
        return $count > 0 ? $this->sum($key) / $count : 0.0;
    }

    /**
     * Retorna o menor valor da coleção ou de uma coluna específica.
     *
     * @param  string|null $key Nome da coluna (opcional).
     * @return mixed            Menor valor ou null se a coleção estiver vazia.
     */
    public function min(?string $key = null): mixed
    {
        if ($key) {
            $values = array_column($this->items, $key);
            return $values ? min($values) : null;
        }
        return $this->items ? min($this->items) : null;
    }

    /**
     * Retorna o maior valor da coleção ou de uma coluna específica.
     *
     * @param  string|null $key Nome da coluna (opcional).
     * @return mixed            Maior valor ou null se a coleção estiver vazia.
     */
    public function max(?string $key = null): mixed
    {
        if ($key) {
            $values = array_column($this->items, $key);
            return $values ? max($values) : null;
        }
        return $this->items ? max($this->items) : null;
    }

    /**
     * Reduz a coleção a um único valor acumulado.
     *
     * @param  callable $callback Função redutora `(carry, item) => mixed`.
     * @param  mixed    $carry    Valor inicial do acumulador (padrão: null).
     * @return mixed              Valor final após a redução.
     */
    public function reduce(callable $callback, mixed $carry = null): mixed
    {
        return array_reduce($this->items, $callback, $carry);
    }

    // =========================================================================
    // MODIFICAÇÃO
    // =========================================================================

    /**
     * Adiciona um item ao final da coleção e retorna uma nova instância.
     *
     * @param  mixed  $item Item a adicionar.
     * @return static       Nova coleção com o item adicionado.
     */
    public function push(mixed $item): static
    {
        $clone = clone $this;
        $clone->items[] = $item;
        return $clone;
    }

    /**
     * Adiciona um item ao início da coleção e retorna uma nova instância.
     *
     * @param  mixed  $item Item a adicionar no início.
     * @return static       Nova coleção com o item no início.
     */
    public function prepend(mixed $item): static
    {
        return new static(array_merge([$item], $this->items));
    }

    /**
     * Define um valor para uma chave específica e retorna uma nova instância.
     *
     * @param  int|string $key   Chave de destino.
     * @param  mixed      $value Valor a definir.
     * @return static            Nova coleção com o valor atualizado.
     */
    public function put(int|string $key, mixed $value): static
    {
        $items = $this->items;
        $items[$key] = $value;
        return new static($items);
    }

    /**
     * Remove um item pela chave e retorna uma nova instância reindexada.
     *
     * @param  int|string $key Chave do item a remover.
     * @return static          Nova coleção sem o item removido.
     */
    public function forget(int|string $key): static
    {
        $items = $this->items;
        unset($items[$key]);
        return new static(array_values($items));
    }

    /**
     * Mescla os itens fornecidos com a coleção atual.
     *
     * @param  array|static $items Itens a mesclar.
     * @return static              Nova coleção com os itens mesclados.
     */
    public function merge(array|self $items): static
    {
        $other = $items instanceof self ? $items->all() : $items;
        return new static(array_merge($this->items, $other));
    }

    /**
     * Combina a coleção com um array em pares, como `array_map(null, ...)`.
     *
     * @param  array  $other Array a combinar.
     * @return static        Nova coleção de pares combinados.
     */
    public function zip(array $other): static
    {
        return new static(array_map(null, $this->items, $other));
    }

    // =========================================================================
    // CHAVES
    // =========================================================================

    /**
     * Retorna uma nova coleção com todas as chaves dos itens atuais.
     *
     * @return static Nova coleção contendo apenas as chaves.
     */
    public function keys(): static
    {
        return new static(array_keys($this->items));
    }

    /**
     * Retorna uma nova coleção reindexada com índices numéricos sequenciais.
     *
     * @return static Nova coleção reindexada.
     */
    public function values(): static
    {
        return new static(array_values($this->items));
    }

    /**
     * Re-indexa a coleção usando o valor de uma coluna como chave.
     *
     * @param  string $key Coluna a usar como índice.
     * @return static      Nova coleção indexada pelo valor da coluna.
     */
    public function keyBy(string $key): static
    {
        $result = [];
        foreach ($this->items as $item) {
            $k = is_array($item) ? ($item[$key] ?? null) : ($item->$key ?? null);
            $result[$k] = $item;
        }
        return new static($result);
    }

    // =========================================================================
    // PAGINAÇÃO
    // =========================================================================

    /**
     * Pagina os itens da coleção e retorna os dados com metadados de paginação.
     *
     * Lê automaticamente o parâmetro `?page=` da query string se não fornecido.
     *
     * @param  int      $perPage Itens por página.
     * @param  int|null $page    Página atual (null = lê de $_GET['page']).
     * @return array             Resultado paginado com metadados.
     */
    public function paginate(int $perPage, ?int $page = null): array
    {
        $page = $page ?? max(1, (int) ($_GET['page'] ?? 1));
        return array_paginate($this->items, $perPage, $page);
    }

    // =========================================================================
    // UTILIDADE
    // =========================================================================

    /**
     * Executa um callback com a coleção atual e a retorna (side-effect seguro).
     *
     * @param  callable $callback Função a executar com a coleção.
     * @return static             A própria coleção, sem alterações.
     */
    public function tap(callable $callback): static
    {
        $callback($this);
        return $this;
    }

    /**
     * Passa a coleção por um callback e retorna o seu resultado.
     *
     * @param  callable $callback Função a executar com a coleção.
     * @return mixed              Resultado retornado pelo callback.
     */
    public function pipe(callable $callback): mixed
    {
        return $callback($this);
    }

    /**
     * Despeja os itens da coleção e encerra a execução.
     *
     * @return never
     */
    public function dd(): never
    {
        dd($this->items);
    }

    /**
     * Exibe os itens da coleção sem encerrar a execução.
     *
     * @return static A própria coleção (fluente).
     */
    public function dump(): static
    {
        dump($this->items);
        return $this;
    }

    /**
     * Serializa os itens da coleção para JSON ao usar em contexto de string.
     *
     * @return string JSON dos itens da coleção.
     */
    public function __toString(): string
    {
        return $this->toJson();
    }
}

// =============================================================================
// VALIDATION
// =============================================================================
if (!function_exists('validate')) {
    /**
     * Valida dados contra um conjunto de regras.
     * Em caso de falha, redireciona de volta com erros e old input.
     * Em contexto JSON, lança ValidationException.
     *
     * @example validate($request->all(), ['email' => 'required|email'])
     */
    function validate(array $data, array $rules, array $messages = []): array
    {
        try {
            return Validator::make($data, $rules, $messages)->validate();
        } catch (ValidationException $e) {
            // JSON / AJAX → lança a exceção (o controller trata)
            if (
                isset($_SERVER['HTTP_ACCEPT']) &&
                str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')
            ) {
                throw $e;
            }

            Session::start();
            Session::flash('_errors', ['default' => $e->errors()]);
            Session::flash('_old_input', $data);
 
            $referer = $_SERVER['HTTP_REFERER'] ?? '/';
            header("Location: {$referer}", true, 302);
            exit;
        }
    }
}
 
// =============================================================================
// CACHE
// =============================================================================
  
if (!function_exists('cache')) {
    /**
     * Acessa o sistema de cache.
     *
     * cache()                          → instância de Cache (estático)
     * cache('key')                     → Cache::get('key')
     * cache('key', $default)           → Cache::get('key', $default)
     * cache(['key' => $value], $ttl)   → Cache::put('key', $value, $ttl)
     */
    function cache(string|array|null $key = null, mixed $default = null, int $ttl = 3600): mixed
    {
        // cache() sem args → retorna a classe para chamadas estáticas
        if ($key === null) return Cache::class;
 
        // cache(['key' => value], ttl) → put
        if (is_array($key)) {
            foreach ($key as $k => $v) {
                Cache::put((string) $k, $v, $ttl);
            }
            return null;
        }
 
        // cache('key') ou cache('key', $default) → get
        return Cache::get($key, $default);
    }
}
 
// =============================================================================
// LOG
// =============================================================================
  
if (!function_exists('logger')) {
    /**
     * Atalho para logar mensagens.
     *
     * logger('mensagem')                    → Log::debug
     * logger('mensagem', [], 'info')        → Log::info
     * logger('mensagem', ['key' => 'val'])  → Log::debug com contexto
     */
    function logger(string $message, array $context = [], string $level = 'debug'): void
    {
        match ($level) {
            'info'     => Log::info($message, $context),
            'warning'  => Log::warning($message, $context),
            'error'    => Log::error($message, $context),
            'critical' => Log::critical($message, $context),
            default    => Log::debug($message, $context),
        };
    }
}
 
// =============================================================================
// STORAGE
// =============================================================================
  
if (!function_exists('storage')) {
    /**
     * Acessa o sistema de storage.
     *
     * storage()              → instância do disco padrão (StorageDisk)
     * storage('local')       → disco privado
     * storage('public')      → disco público
     */
    function storage(string $disk = 'public'): \Slenix\Supports\Storage\StorageDisk
    {
        return Storage::disk($disk);
    }
}
 
if (!function_exists('storage_url')) {
    /**
     * Gera URL pública para um ficheiro no disco 'public'.
     *
     * @example storage_url('avatars/user-1.jpg')
     * → 'http://localhost/storage/avatars/user-1.jpg'
     */
    function storage_url(string $path): string
    {
        return Storage::disk('public')->url($path);
    }
}


// =============================================================================
// LUNA — Variáveis globais disponíveis em todos os templates
// =============================================================================

if (class_exists(Luna::class)) {

    // Rotas
    Luna::share('route', fn(string $name, array $params = []): ?string => Router::route($name, $params));

    // CSRF
    Luna::share('csrf_token', fn(): string => csrf_token());
    Luna::share('csrf_field', fn(): string => csrf_field());

    // Formulários
    Luna::share('old', fn(string $key, mixed $default = ''): mixed => old($key, $default));
    Luna::share('errors', fn(?string $field = null): mixed => errors($field));
    Luna::share('has_error', fn(string $field): bool => has_error($field));

    // Flash
    Luna::share('flash', fn(): FlashMessage => flash());

    // URL / navegação
    Luna::share('is_active', fn(string $pattern, string $a = 'active', string $i = ''): string => is_active($pattern, $a, $i));
    Luna::share('asset', fn(string $path): string => asset($path));
    Luna::share('url', fn(string $path = '', array $q = []): string => url($path, $q));
    Luna::share('current_url', fn(): string => current_url());
    Luna::share('request_path', fn(): string => request_path());

    // Debug (apenas em ambiente local)
    if (function_exists('is_local') && is_local()) {
        Luna::share('benchmark', fn(): float => benchmark());
        Luna::share('memory_usage', fn(): string => memory_usage());
    }

    // Session
    Luna::share('Session', [
        'has' => fn(string $key): bool => Session::has($key),
        'get' => fn(string $key, mixed $d = null) => Session::get($key, $d),
        'set' => fn(string $key, mixed $v) => Session::set($key, $v),
        'flash' => fn(string $key, mixed $v) => Session::flash($key, $v),
        'getFlash' => fn(string $key, mixed $d = null) => Session::getFlash($key, $d),
        'hasFlash' => fn(string $key): bool => Session::hasFlash($key),
        'remove' => fn(string $key) => Session::remove($key),
        'all' => fn(): array => Session::all(),
        'destroy' => fn() => Session::destroy(),
        'id' => fn(): string => session_id(),
    ]);

    // Utilitários para templates
    Luna::share('now', fn(): \DateTimeImmutable => now());
    Luna::share('format_date', fn(string $d, string $f = 'd/m/Y H:i:s'): ?string => format_date($d, $f));
    Luna::share('human_date', fn(string|\DateTimeInterface $d): string => human_date($d));
    Luna::share('currency', fn(float $v, string $s = 'R$'): string => currency($v, $s));
    Luna::share('excerpt', fn(string $html, int $l = 160): string => excerpt($html, $l));
    Luna::share('initials', fn(string $name, int $limit = 2): string => initials($name, $limit));
}