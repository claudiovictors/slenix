<?php

/*
 |--------------------------------------------------------------------------
 | Classe AppFactory
 |--------------------------------------------------------------------------
 |
 | Fábrica principal da aplicação. Centraliza a criação do Kernel,
 | registro de serviços compartilhados (Request, Response) e a
 | execução do ciclo de vida HTTP.
 |
 */

declare(strict_types=1);

namespace Slenix\Core;

use Slenix\Http\Request;
use Slenix\Http\Response;

class AppFactory
{
    /** @var array<string, mixed> Serviços registrados no container simples */
    private static array $bindings = [];

    /** @var self|null Instância singleton da factory */
    private static ?self $instance = null;

    private function __construct() {}

    /**
     * Cria e executa a aplicação.
     *
     * @param float $startTime Timestamp de início (microtime(true)).
     */
    public static function create(float $startTime): void
    {
        static::bootstrap();
        (new Kernel($startTime))->run();
    }

    /**
     * Registra serviços fundamentais no container antes de iniciar o Kernel.
     */
    private static function bootstrap(): void
    {
        // Request singleton (criado a partir dos superglobais atuais)
        static::singleton('request', static fn () => Request::createFromGlobals());

        // Response singleton
        static::singleton('response', static fn () => new Response());
    }

    /**
     * Registra uma instância como singleton (criada uma única vez sob demanda).
     *
     * @param string   $abstract Nome/chave do serviço.
     * @param callable $factory  Callable que retorna a instância.
     */
    public static function singleton(string $abstract, callable $factory): void
    {
        static::$bindings[$abstract] = [
            'factory'   => $factory,
            'singleton' => true,
            'resolved'  => null,
        ];
    }

    /**
     * Registra uma ligação simples (nova instância a cada chamada).
     *
     * @param string   $abstract Nome/chave do serviço.
     * @param callable $factory  Callable que retorna a instância.
     */
    public static function bind(string $abstract, callable $factory): void
    {
        static::$bindings[$abstract] = [
            'factory'   => $factory,
            'singleton' => false,
            'resolved'  => null,
        ];
    }

    /**
     * Registra uma instância já criada diretamente.
     *
     * @param string $abstract Nome/chave do serviço.
     * @param mixed  $instance Instância a armazenar.
     */
    public static function instance(string $abstract, mixed $instance): void
    {
        static::$bindings[$abstract] = [
            'factory'   => null,
            'singleton' => true,
            'resolved'  => $instance,
        ];
    }

    /**
     * Resolve e retorna um serviço registrado.
     *
     * @param string $abstract Nome/chave do serviço.
     * @return mixed
     *
     * @throws \InvalidArgumentException Se o serviço não estiver registrado.
     */
    public static function make(string $abstract): mixed
    {
        if (!isset(static::$bindings[$abstract])) {
            throw new \InvalidArgumentException(
                "Serviço '{$abstract}' não está registrado no container."
            );
        }

        $binding = &static::$bindings[$abstract];

        // Singleton: retorna a instância já resolvida se existir
        if ($binding['singleton'] && $binding['resolved'] !== null) {
            return $binding['resolved'];
        }

        // Resolve via factory
        if ($binding['factory'] !== null) {
            $resolved = ($binding['factory'])();

            if ($binding['singleton']) {
                $binding['resolved'] = $resolved;
            }

            return $resolved;
        }

        return $binding['resolved'];
    }

    /**
     * Verifica se um serviço está registrado.
     *
     * @param string $abstract
     * @return bool
     */
    public static function has(string $abstract): bool
    {
        return isset(static::$bindings[$abstract]);
    }

    /**
     * Remove um serviço do container (útil em testes).
     *
     * @param string $abstract
     */
    public static function forget(string $abstract): void
    {
        unset(static::$bindings[$abstract]);
    }

    /**
     * Limpa todos os serviços registrados (útil em testes).
     */
    public static function flush(): void
    {
        static::$bindings = [];
    }

    /**
     * Retorna todos os serviços registrados (sem resolver).
     *
     * @return array<string>
     */
    public static function registered(): array
    {
        return array_keys(static::$bindings);
    }

    /**
     * Retorna a instância da requisição atual.
     */
    public static function request(): Request
    {
        /** @var Request */
        return static::make('request');
    }

    /**
     * Retorna a instância da resposta atual.
     */
    public static function response(): Response
    {
        /** @var Response */
        return static::make('response');
    }
}