<?php

declare(strict_types=1);

namespace Slenix\Commands;

use Slenix\Helpers\Console;

abstract class Command {
    
    protected static string $version = '1.0';
    protected const ASCII_ART = <<<EOT
    ╔═╗╦  ╔═╗╦═╗╦╔═╗╦╔═╗╦═╗
    ╚═╗║  ║  ╠╦╝║╚═╗║╠═╣╠╦╝
    ╚═╝╩═╝╚═╝╩╚═╩╚═╝╩╩ ╩╩╚═
    EOT;

    protected static function console(): Console
    {
        return new Console();
    }

    public static function error(string $message): void
    {
        $console = self::console();
        echo $console->colorize('[✗] ', 'red') . $console->colorize($message, 'red') . PHP_EOL;
    }

    public static function warning(string $message): void
    {
        $console = self::console();
        echo $console->colorize('[!] ', 'yellow') . $console->colorize($message, 'yellow') . PHP_EOL;
    }

    public static function success(string $message): void
    {
        $console = self::console();
        echo $console->colorize('[✔] ', 'green') . $console->colorize($message, 'green') . PHP_EOL;
    }

    public static function info(string $message): void
    {
        $console = self::console();
        echo $console->colorize('[ℹ] ', 'cyan') . $console->colorize($message, 'cyan') . PHP_EOL;
    }

    public static function version(): void
    {
        $console = self::console();
        echo self::ASCII_ART . PHP_EOL;
        echo $console->colorize("Slenix CLI v" . self::$version, 'purple') . PHP_EOL;
        echo $console->colorize('Desenvolvido com ♥ para o ecossistema Slenix', 'white') . PHP_EOL;
    }

    public static function help(): void
    {
        $console = self::console();
        echo self::ASCII_ART . PHP_EOL;
        echo $console->colorize("Slenix CLI v" . self::$version, 'purple') . PHP_EOL;
        echo $console->colorize('Ferramenta de desenvolvimento para o framework Slenix', 'white') . PHP_EOL . PHP_EOL;

        echo $console->colorize('Uso:', 'white', true) . PHP_EOL;
        echo "  php celestial <comando> [opções]" . PHP_EOL . PHP_EOL;

        echo $console->colorize('Comandos disponíveis:', 'white', true) . PHP_EOL;
        echo "  " . $console->colorize('make:model <nome>', 'green') . "      Cria um novo model" . PHP_EOL;
        echo "  " . $console->colorize('make:controller <nome>', 'green') . " Cria um novo controller" . PHP_EOL;
        echo "  " . $console->colorize('serve [porta]', 'green') . "         Inicia o servidor de desenvolvimento" . PHP_EOL;
        echo "  " . $console->colorize('help', 'green') . "                 Exibe esta ajuda" . PHP_EOL;
        echo "  " . $console->colorize('version', 'green') . "              Exibe a versão da CLI" . PHP_EOL . PHP_EOL;

        echo $console->colorize('Exemplos:', 'white', true) . PHP_EOL;
        echo "  " . $console->colorize('php celestial make:model User', 'cyan') . PHP_EOL;
        echo "  " . $console->colorize('php celestial make:controller Home', 'cyan') . PHP_EOL;
        echo "  " . $console->colorize('php celestial serve 8000', 'cyan') . PHP_EOL;
    }
}