<?php

declare(strict_types=1);

use Slenix\Libraries\Template;

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

if(!function_exists('sanetize')):
    function sanetize(string $string): string {
        return trim(htmlspecialchars($string, ENT_QUOTES, 'UTF-8'));
    }
endif;

if(!function_exists('validate')):
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

if(!function_exists('limit')):
    function limit($text, $limit): string {
        return (strlen($text) >= $limit) ? substr($text, 0, $limit).'...' : $text;
    }
endif;

/*                                            
|--------------------------------------------|
|****** FUNÇÕES PARA MANIPULAR O LUNA *******|
|--------------------------------------------|
*/

if(!function_exists('env')):
    function env(string $key, mixed $default = null): string|int|bool|null {
        return $_ENV[$key] ?? getenv($key) ?? $default;
    }
endif;

if(!function_exists('view')):
    function view(string $template, array $data = []){
        $view_template = new Template($template, $data);
        echo $view_template->render();
    }
endif;