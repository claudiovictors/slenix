<?php

/*
|--------------------------------------------------------------------------
| SessionManager — Slenix Framework
|--------------------------------------------------------------------------
|
| Esta classe fornece uma interface orientada a objetos e fluente sobre
| a camada de sessão baixo nível do framework (Session). Expõe operações
| de leitura, escrita, remoção, flash e controlo do ciclo de vida da sessão
| de forma encadeável e expressiva.
|
| É instanciada automaticamente pelo helper global session() quando chamado
| sem argumentos, e pode ser injetada diretamente em serviços ou controllers.
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Libraries;

use Slenix\Supports\Security\Session;

class SessionManager
{

    /**
     * Define um valor na sessão sob a chave especificada.
     *
     * @param  string $key   Chave de identificação do valor.
     * @param  mixed  $value Valor a ser armazenado na sessão.
     * @return static        Instância atual para encadeamento fluente.
     */
    public function set(string $key, mixed $value): static
    {
        Session::set($key, $value);
        return $this;
    }

    /**
     * Recupera um valor da sessão pela chave.
     *
     * @param  string $key     Chave do valor a ser recuperado.
     * @param  mixed  $default Valor retornado caso a chave não exista.
     * @return mixed           Valor armazenado ou o padrão definido.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return Session::get($key, $default);
    }

    /**
     * Verifica se uma chave existe na sessão.
     *
     * @param  string $key Chave a ser verificada.
     * @return bool        True se a chave existir, false caso contrário.
     */
    public function has(string $key): bool
    {
        return Session::has($key);
    }

    /**
     * Verifica se uma chave NÃO existe na sessão.
     *
     * @param  string $key Chave a ser verificada.
     * @return bool        True se a chave não existir, false se existir.
     */
    public function missing(string $key): bool
    {
        return !Session::has($key);
    }

    /**
     * Retorna todos os dados presentes na sessão.
     *
     * @return array<string, mixed> Mapa associativo com todos os valores da sessão.
     */
    public function all(): array
    {
        return Session::all();
    }

    /**
     * Define um ou múltiplos valores na sessão.
     *
     * Aceita uma chave e valor individuais ou um array associativo para
     * definir múltiplos valores de uma só vez.
     *
     * @param  string|array<string, mixed> $key   Chave ou array de chave/valor.
     * @param  mixed                       $value Valor (usado apenas se $key for string).
     * @return static                             Instância atual para encadeamento fluente.
     */
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

    /**
     * Adiciona um valor ao final de um array armazenado na sessão.
     *
     * Se a chave não existir ou não for um array, é inicializada como array vazio.
     *
     * @param  string $key   Chave do array na sessão.
     * @param  mixed  $value Valor a ser adicionado ao array.
     * @return static        Instância atual para encadeamento fluente.
     */
    public function push(string $key, mixed $value): static
    {
        $arr   = (array) Session::get($key, []);
        $arr[] = $value;
        Session::set($key, $arr);
        return $this;
    }

    /**
     * Recupera um valor da sessão e o remove imediatamente após a leitura.
     *
     * @param  string $key     Chave do valor a ser recuperado e removido.
     * @param  mixed  $default Valor retornado caso a chave não exista.
     * @return mixed           Valor armazenado ou o padrão definido.
     */
    public function pull(string $key, mixed $default = null): mixed
    {
        $value = Session::get($key, $default);
        Session::remove($key);
        return $value;
    }

    /**
     * Remove uma ou múltiplas chaves da sessão.
     *
     * @param  string|string[] $keys Chave ou array de chaves a serem removidas.
     * @return static                Instância atual para encadeamento fluente.
     */
    public function forget(string|array $keys): static
    {
        foreach ((array) $keys as $key) {
            Session::remove($key);
        }

        return $this;
    }

    /**
     * Remove todos os dados da sessão sem destruí-la.
     *
     * Mantém a sessão ativa mas limpa todo o seu conteúdo.
     *
     * @return static Instância atual para encadeamento fluente.
     */
    public function flush(): static
    {
        Session::start();
        $_SESSION = [];
        return $this;
    }

    /**
     * Retorna o ID da sessão atual.
     *
     * Inicia a sessão automaticamente caso ainda não esteja ativa.
     *
     * @return string ID único da sessão atual.
     */
    public function id(): string
    {
        Session::start();
        return session_id();
    }

    /**
     * Destrói completamente a sessão atual e todos os seus dados.
     *
     * @return static Instância atual para encadeamento fluente.
     */
    public function invalidate(): static
    {
        Session::destroy();
        return $this;
    }

    /**
     * Regenera o ID da sessão para mitigar ataques de session fixation.
     *
     * @param  bool   $deleteOld Se true, destrói a sessão com o ID antigo (padrão: true).
     * @return static            Instância atual para encadeamento fluente.
     */
    public function regenerate(bool $deleteOld = true): static
    {
        Session::regenerateId($deleteOld);
        return $this;
    }

    /**
     * Armazena um valor flash que persiste apenas até a próxima requisição.
     *
     * @param  string $key   Chave do valor flash.
     * @param  mixed  $value Valor a ser armazenado temporariamente.
     * @return static        Instância atual para encadeamento fluente.
     */
    public function flash(string $key, mixed $value): static
    {
        Session::flash($key, $value);
        return $this;
    }

    /**
     * Recupera um valor flash da sessão.
     *
     * @param  string $key     Chave do valor flash.
     * @param  mixed  $default Valor retornado caso a chave não exista.
     * @return mixed           Valor flash armazenado ou o padrão definido.
     */
    public function getFlash(string $key, mixed $default = null): mixed
    {
        return Session::getFlash($key, $default);
    }

    /**
     * Verifica se existe um valor flash para a chave informada.
     *
     * @param  string $key Chave do valor flash.
     * @return bool        True se existir, false caso contrário.
     */
    public function hasFlash(string $key): bool
    {
        return Session::hasFlash($key);
    }

    /**
     * Armazena os dados de input do formulário como flash para repopulação.
     *
     * Remove automaticamente campos sensíveis antes de armazenar.
     *
     * @param  array<string, mixed> $data Dados do formulário a serem preservados.
     * @return static                     Instância atual para encadeamento fluente.
     */
    public function flashInput(array $data): static
    {
        unset($data['password'], $data['password_confirmation'], $data['_token']);
        Session::flashOldInput($data);
        return $this;
    }


    /**
     * Incrementa um valor numérico armazenado na sessão.
     *
     * Se a chave não existir, é inicializada em zero antes do incremento.
     *
     * @param  string $key    Chave do contador na sessão.
     * @param  int    $amount Quantidade a incrementar (padrão: 1).
     * @return int            Novo valor após o incremento.
     */
    public function increment(string $key, int $amount = 1): int
    {
        $new = ((int) Session::get($key, 0)) + $amount;
        Session::set($key, $new);
        return $new;
    }

    /**
     * Decrementa um valor numérico armazenado na sessão.
     *
     * Se a chave não existir, é inicializada em zero antes do decremento.
     *
     * @param  string $key    Chave do contador na sessão.
     * @param  int    $amount Quantidade a decrementar (padrão: 1).
     * @return int            Novo valor após o decremento.
     */
    public function decrement(string $key, int $amount = 1): int
    {
        return $this->increment($key, -$amount);
    }
}