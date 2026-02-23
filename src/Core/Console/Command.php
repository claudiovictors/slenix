<?php

/*
|--------------------------------------------------------------------------
| Classe Command
|--------------------------------------------------------------------------
|
| Classe base da CLI do Slenix.
| Fornece métodos padronizados para saída no terminal,
| utilizando cores e símbolos para melhor legibilidade.
|
*/

declare(strict_types=1);

namespace Slenix\Core\Console;

abstract class Command
{
    /**
     * Versão atual da CLI.
     */
    protected static string $version = '2.2';

    /**
     * Retorna instância do helper de console.
     * @return Console
     */
    protected static function console(): Console
    {
        return new Console();
    }

    /**
     * Formata e imprime uma linha padronizada.
     * @param string $symbol
     * @param string $message
     * @param string $color
     * @return void
     */
    protected static function line(string $symbol, string $message, string $color): void
    {
        $c = self::console();

        echo $c->colorize("[$symbol] ", $color, true)
            . $c->colorize($message, $color)
            . PHP_EOL;
    }

    /**
     * Exibe mensagem informativa.
     * @param string $message
     * @return void
     */
    public static function info(string $message): void
    {
        self::line('ℹ', $message, 'cyan');
    }

    /**
     * Exibe mensagem de sucesso.
     * @param string $message
     * @return void
     */
    public static function success(string $message): void
    {
        self::line('✔', $message, 'green');
    }

    /**
     * Exibe mensagem de aviso.
     * @param string $message
     * @return void
     */
    public static function warning(string $message): void
    {
        self::line('!', $message, 'yellow');
    }

    /**
     * Exibe mensagem de erro.
     * @param string $message
     * @return void
     */
    public static function error(string $message): void
    {
        self::line('✗', $message, 'red');
    }

    /**
     * Exibe a versão da CLI.
     * @return void
     */
    public static function version(): void
    {
        self::info("Celestial CLI v" . self::$version);
    }

    /**
     * Exibe ajuda da CLI.
     * @return void
     */
    public static function help(): void
    {
        echo PHP_EOL;
        self::info("Celestial CLI v" . self::$version);
        echo PHP_EOL;

        echo "Usage:" . PHP_EOL;
        echo "  php celestial <command> [arguments]" . PHP_EOL . PHP_EOL;

        echo "Available commands:" . PHP_EOL;

        $commands = [
            'key:generate'           => 'Gera e salva o APP_KEY no .env',
            'make:controller <name>' => 'Cria um novo controller',
            'make:model <name>'      => 'Cria um novo model',
            'serve [port]'           => 'Inicia servidor de desenvolvimento',
            'version'                => 'Exibe a versão da CLI',
            'help'                   => 'Exibe esta ajuda',
        ];

        foreach ($commands as $cmd => $desc) {
            echo "  " . str_pad($cmd, 24) . $desc . PHP_EOL;
        }

        echo PHP_EOL;
    }
}
