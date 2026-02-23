<?php

/*
|--------------------------------------------------------------------------
| Classe EnvLoad
|--------------------------------------------------------------------------
|
| Esta classe é responsável por carregar as variáveis de ambiente a partir
| de um arquivo .env. Ela lê o arquivo linha por linha, ignora comentários
| e linhas vazias, e define as variáveis no ambiente do sistema ($_ENV,
| $_SERVER e através da função putenv()).
|
*/

declare(strict_types=1);

namespace Slenix\Core;

class EnvLoader
{
    /**
     * Caminho para o arquivo .env.
     *
     * @var string
     */
    private static string $path_env = '';

    /**
     * Carrega as variáveis de ambiente do arquivo especificado.
     *
     * @param string $path_env O caminho completo para o arquivo .env.
     * @throws \Exception Se o arquivo .env não for encontrado.
     * @return void
     */
    public static function load(string $path_env): void
    {
        self::$path_env = $path_env;

        if (!file_exists(self::$path_env)) {
            throw new \Exception('Arquivo .env não encontrado!');
        }

        $lines_path = file(self::$path_env, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines_path as $line) {
            $line = trim($line);

            if (strpos($line, '#') === 0) {
                continue;
            }

            if (strpos($line, '=') !== false) {
                list($variable, $value) = explode('=', $line, 2);
                if (!array_key_exists($variable, $_ENV) && !array_key_exists($variable, $_SERVER)) {
                    putenv("$variable=$value");
                    $_ENV[$variable] = $value;
                    $_SERVER[$variable] = $value;
                }
            }
        }
    }

    /**
     * Obtém o valor de uma variável carregada
     *
     * @param string $name
     * @param mixed $default
     * @return mixed
     */
    public function get(string $name, mixed $default = null): mixed
    {
        return getenv($name) ?? $default;
    }
}