<?php

declare(strict_types=1);

namespace Slenix\Core\Console;

class ServeCommand extends Command
{
    private array $args;
    private const DEFAULT_PORT = 8080;

    public function __construct(array $args)
    {
        $this->args = $args;
    }

    public function execute(): void
    {
        $c = self::console();

        $port = $this->args[2] ?? self::DEFAULT_PORT;

        if (!is_numeric($port) || $port < 1 || $port > 65535) {
            self::error('Invalid port. Use a number between 1 and 65535.');
            exit(1);
        }

        $host = '127.0.0.1';
        $publicDir = PUBLIC_PATH;

        echo PHP_EOL;

        // Banner estilo Next.js
        echo $c->colorize("▲ Celestial Dev Server", 'white', true) . PHP_EOL;

        if (!is_dir($publicDir)) {
            echo $c->colorize("  - Creating public directory...", 'gray') . PHP_EOL;

            if (!mkdir($publicDir, 0755, true)) {
                self::error('Failed to create public directory.');
                exit(1);
            }
        }

        // Pronto
        echo $c->colorize("  ✓ Ready in " . rand(120, 400) . "ms", 'green') . PHP_EOL;

        echo PHP_EOL;

        // URLs
        echo $c->colorize("  ➜ Local:   ", 'cyan') . "http://{$host}:{$port}" . PHP_EOL;
        echo $c->colorize("  ➜ Network: ", 'cyan') . "http://192.168.x.x:{$port}" . PHP_EOL;

        echo PHP_EOL;

        // Hint
        echo $c->colorize("  press Ctrl+C to stop", 'white') . PHP_EOL;

        echo PHP_EOL;

        passthru("php -S {$host}:{$port} -t {$publicDir}");
    }
}