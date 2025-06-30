<?php

declare(strict_types=1);

use Slenix\Libraries\Template;
use Slenix\Http\Message\Router;
use Slenix\Libraries\Session;

/*                                            
|--------------------------------------------|
|****** HELPERS GERAIS E CONSTANTES v1 ******|
|--------------------------------------------|
*/

// Constantes úteis
define('SLENIX_START', microtime(true));
define('DS', DIRECTORY_SEPARATOR);
define('ROOT_PATH', dirname(__DIR__) . DS);
define('PUBLIC_PATH', ROOT_PATH . 'public' . DS);
define('APP_PATH', ROOT_PATH . 'app' . DS);

/*                                            
|--------------------------------------------|
|****** FUNÇÕES PARA MANIPULAR STRINGS ******|
|--------------------------------------------|
*/

if (!function_exists('sanetize')):
    function sanetize(string $string): string {
        return trim(htmlspecialchars($string, ENT_QUOTES, 'UTF-8'));
    }
endif;

if (!function_exists('validate')):
    function validate(string $string): mixed {
        return preg_match('/^[A-Za-zÀ-ÖØ-öø-ÿ\s]+$/u', $string);
    }
endif;

if (!function_exists('camel_case')) {
    function camel_case(string $string)
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $string))));
    }
}

if (!function_exists('snake_case')) {
    function snake_case(string $string, string $delimiter = '_')
    {
        if (!ctype_lower($string)) {
            $string = preg_replace('/\s+/u', '', $string);
            $string = mb_strtolower(preg_replace('/(.)(?=[A-Z])/u', '$1' . $delimiter, $string), 'UTF-8');
        }
        return $string;
    }
}

if (!function_exists('str_default')) {
    function str_default(?string $string, string $default)
    {
        return empty($string) ? $default : $string;
    }
}

if (!function_exists('limit')):
    function limit($text, $limit): string {
        return (strlen($text) >= $limit) ? substr($text, 0, $limit).'...' : $text;
    }
endif;

/*                                            
|--------------------------------------------|
|****** FUNÇÕES PARA MANIPULAR O LUNA *******|
|--------------------------------------------|
*/

if (!function_exists('env')):
    function env(string $key, mixed $default = null): string|int|bool|null {
        return $_ENV[$key] ?? getenv($key) ?? $default;
    }
endif;

if (!function_exists('view')):
    function view(string $template, array $data = []) {
        $view_template = new Template($template, $data);
        echo $view_template->render();
    }
endif;

if (!function_exists('route')):
    /**
     * Gera a URL para uma rota nomeada.
     *
     * @param string $name O nome da rota.
     * @param array $params Parâmetros para substituir na URL.
     * @return string|null A URL gerada ou null se a rota não for encontrada.
     * @throws \Exception Se parâmetros obrigatórios estiverem faltando.
     */
    function route(string $name, array $params = []): ?string {
        return Router::route($name, $params);
    }
endif;

if (!function_exists('old')):
    /**
     * Recupera o valor antigo de um campo de formulário armazenado na sessão.
     *
     * @param string $key A chave do campo de formulário.
     * @param mixed $default O valor padrão a ser retornado caso o campo não exista.
     * @param string $flashKey A chave usada para armazenar os dados do formulário na sessão.
     * @return mixed O valor antigo ou o valor padrão.
     */
    function old(string $key, mixed $default = null, string $flashKey = '_old_input'): mixed {
        return Session::getFlash($flashKey . '.' . $key, $default);
    }
endif;

if (!function_exists('flash_input')):
    /**
     * Armazena os dados do formulário como flash data na sessão.
     *
     * @param array $input Os dados do formulário a serem armazenados.
     * @param string $flashKey A chave usada para armazenar os dados na sessão.
     * @return void
     */
    function flash_input(array $input, string $flashKey = '_old_input'): void {
        Session::flash($flashKey, $input);
    }
endif;

// Registrar funções como globais no Template
Template::share('route', function (string $name, array $params = []): ?string {
    return Router::route($name, $params);
});
Template::share('old', function (string $key, mixed $default = null, string $flashKey = '_old_input'): mixed {
    return Session::getFlash($flashKey . '.' . $key, $default);
});