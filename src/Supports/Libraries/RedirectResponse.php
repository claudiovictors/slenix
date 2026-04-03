<?php

/*
|--------------------------------------------------------------------------
| RedirectResponse — Slenix Framework
|--------------------------------------------------------------------------
|
| Esta classe implementa respostas de redirecionamento HTTP de forma fluente.
| Fornece métodos encadeáveis para redirecionar o utilizador para URLs
| absolutas, rotas nomeadas ou para a página anterior, com suporte nativo
| a mensagens flash, dados de formulário antigo e erros de validação.
|
| É instanciada automaticamente pelo helper global redirect().
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Libraries;

use Slenix\Http\Routing\Router;
use Slenix\Supports\Security\Session;

class RedirectResponse
{

    /**
     * Código de status HTTP do redirecionamento.
     *
     * @var int
     */
    private int $status;

    /**
     * Dados flash a serem enviados para a sessão antes do redirecionamento.
     *
     * @var array<string, mixed>
     */
    private array $flashData = [];

    /**
     * Inicializa a resposta de redirecionamento com o código de status HTTP.
     *
     * @param int $status Código HTTP do redirecionamento (padrão: 302 Found).
     */
    public function __construct(int $status = 302)
    {
        $this->status = $status;
    }

    /**
     * Executa o redirecionamento para a URL fornecida.
     *
     * Sanitiza a URL removendo caracteres de controle antes de emitir o
     * cabeçalho Location. Envia os dados flash para a sessão previamente.
     * Esta operação encerra a execução do script.
     *
     * @param  string $url URL de destino do redirecionamento.
     * @return never
     */
    public function to(string $url): never
    {
        $this->sendFlash();
        $url = str_replace(["\r", "\n", "\0"], '', $url);
        header("Location: {$url}", true, $this->status);
        exit;
    }

    /**
     * Redireciona o utilizador de volta à página anterior (HTTP_REFERER).
     *
     * Caso o cabeçalho Referer não esteja disponível, utiliza a URL de
     * fallback fornecida como destino alternativo.
     *
     * @param  string $fallback URL de fallback caso o Referer não exista (padrão: '/').
     * @return never
     */
    public function back(string $fallback = '/'): never
    {
        $this->to($_SERVER['HTTP_REFERER'] ?? $fallback);
    }

    /**
     * Redireciona para uma rota nomeada registada no Router.
     *
     * Caso a rota não seja encontrada, redireciona para a raiz '/'.
     *
     * @param  string              $name   Nome da rota registada.
     * @param  array<string,mixed> $params Parâmetros da rota (ex: ['id' => 42]).
     * @return never
     */
    public function route(string $name, array $params = []): never
    {
        $this->to(Router::route($name, $params) ?? '/');
    }

    /**
     * Adiciona um único valor flash a ser enviado com o redirecionamento.
     *
     * @param  string $key   Chave do valor flash.
     * @param  mixed  $value Valor a ser armazenado na sessão.
     * @return static        Instância atual para encadeamento fluente.
     */
    public function with(string $key, mixed $value): static
    {
        $this->flashData[$key] = $value;
        return $this;
    }

    /**
     * Adiciona múltiplos valores flash de uma só vez.
     *
     * @param  array<string, mixed> $data Mapa associativo de chave/valor.
     * @return static                     Instância atual para encadeamento fluente.
     */
    public function withMany(array $data): static
    {
        foreach ($data as $key => $value) {
            $this->flashData[$key] = $value;
        }
        return $this;
    }

    /**
     * Adiciona erros de validação ao flash data, organizados por bag.
     *
     * Os erros ficam disponíveis na próxima requisição através do helper
     * global errors() e da variável de template $errors.
     *
     * @param  array<string, string|string[]> $errors Erros indexados por nome de campo.
     * @param  string                         $bag    Nome do bag de erros (padrão: 'default').
     * @return static                                 Instância atual para encadeamento fluente.
     */
    public function withErrors(array $errors, string $bag = 'default'): static
    {
        $this->flashData['_errors'][$bag] = $errors;
        return $this;
    }

    /**
     * Preserva os dados do formulário atual na sessão para repopulação.
     *
     * Remove automaticamente campos sensíveis como password, password_confirmation
     * e _token antes de armazenar os dados na sessão. Disponível via old().
     *
     * @param  array<string, mixed>|null $input Dados do formulário (padrão: $_POST).
     * @return static                           Instância atual para encadeamento fluente.
     */
    public function withInput(?array $input = null): static
    {
        $input ??= $_POST;
        unset($input['password'], $input['password_confirmation'], $input['_token']);
        $this->flashData['_old_input'] = $input;
        return $this;
    }

    /**
     * Envia todos os dados flash acumulados para a sessão.
     *
     * Chamado internamente imediatamente antes de executar o redirecionamento.
     *
     * @return void
     */
    private function sendFlash(): void
    {
        foreach ($this->flashData as $key => $value) {
            Session::flash($key, $value);
        }
    }
}