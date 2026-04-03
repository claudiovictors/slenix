<?php

/*
|--------------------------------------------------------------------------
| FlashMessage — Slenix Framework
|--------------------------------------------------------------------------
|
| Esta classe é responsável pelo gerenciamento de mensagens flash na sessão.
| Permite armazenar e recuperar mensagens temporárias de sucesso, erro,
| aviso e informação que persistem apenas até a próxima requisição.
|
| Utiliza a camada de sessão do framework para garantir que as mensagens
| sejam exibidas uma única vez ao utilizador.
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Libraries;

use Slenix\Supports\Security\Session;

class FlashMessage
{

    /**
     * Armazena uma mensagem flash de sucesso na sessão.
     *
     * @param  string $message Conteúdo da mensagem de sucesso.
     * @return static          Instância atual para encadeamento fluente.
     */
    public function success(string $message): static
    {
        Session::flash('_flash_success', $message);
        return $this;
    }

    /**
     * Armazena uma mensagem flash de erro na sessão.
     *
     * @param  string $message Conteúdo da mensagem de erro.
     * @return static          Instância atual para encadeamento fluente.
     */
    public function error(string $message): static
    {
        Session::flash('_flash_error', $message);
        return $this;
    }

    /**
     * Armazena uma mensagem flash de aviso na sessão.
     *
     * @param  string $message Conteúdo da mensagem de aviso.
     * @return static          Instância atual para encadeamento fluente.
     */
    public function warning(string $message): static
    {
        Session::flash('_flash_warning', $message);
        return $this;
    }

    /**
     * Armazena uma mensagem flash informativa na sessão.
     *
     * @param  string $message Conteúdo da mensagem informativa.
     * @return static          Instância atual para encadeamento fluente.
     */
    public function info(string $message): static
    {
        Session::flash('_flash_info', $message);
        return $this;
    }

    /**
     * Armazena um valor flash sob uma chave personalizada na sessão.
     *
     * Útil quando os tipos convencionais (success, error, etc.) não são
     * suficientes e é necessária uma chave semântica específica.
     *
     * @param  string $key   Chave personalizada para identificar a mensagem.
     * @param  mixed  $value Valor a ser armazenado.
     * @return static        Instância atual para encadeamento fluente.
     */
    public function write(string $key, mixed $value): static
    {
        Session::flash($key, $value);
        return $this;
    }

    /**
     * Verifica se existe uma mensagem flash para a chave informada.
     *
     * Aceita tanto a chave direta quanto o prefixo `_flash_` automaticamente,
     * permitindo verificar tanto chaves personalizadas quanto as tipadas.
     *
     * @param  string $key Chave da mensagem (ex: 'success', '_flash_success').
     * @return bool        True se existir mensagem, false caso contrário.
     */
    public function has(string $key): bool
    {
        return Session::hasFlash($key) || Session::hasFlash('_flash_' . $key);
    }

    /**
     * Recupera o valor de uma mensagem flash da sessão.
     *
     * Tenta primeiro a chave exata informada; se não encontrar, tenta com o
     * prefixo `_flash_` adicionado automaticamente. Retorna o valor padrão
     * caso nenhuma das variantes exista.
     *
     * @param  string $key     Chave da mensagem (ex: 'success', '_flash_error').
     * @param  mixed  $default Valor retornado caso a chave não seja encontrada.
     * @return mixed           Valor da mensagem ou o padrão definido.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (Session::hasFlash($key)) {
            return Session::getFlash($key, $default);
        }

        return Session::getFlash('_flash_' . $key, $default);
    }

    /**
     * Retorna todas as mensagens flash presentes na sessão.
     *
     * @return array Mapa associativo com todas as mensagens flash armazenadas.
     */
    public function all(): array
    {
        return $_SESSION['_flash'] ?? [];
    }
}