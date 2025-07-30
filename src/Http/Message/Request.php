<?php

/*
|--------------------------------------------------------------------------
| Classe Request
|--------------------------------------------------------------------------
|
| Esta classe representa um pedido HTTP, encapsulando informações como
| parâmetros da rota, método HTTP, URI, dados de entrada (POST, GET),
| arquivos, cookies, cabeçalhos, IP do cliente e user agent.
|
*/
declare(strict_types=1);

namespace Slenix\Http\Message;

/**
 * Classe que representa um pedido HTTP.
 */
class Request
{
    /**
     * Parâmetros da rota extraídos da URI.
     *
     * @var array<string, string>
     */
    private array $params = [];
    private array $server = [];
    private array $headers = [];
    private array $attributes = [];
    private array $parsedBody = [];

    /**
     * Construtor da classe Request.
     *
     * @param array<string, string> $param Array associativo contendo os parâmetros da rota.
     */
    public function __construct(array $param = [])
    {
        $this->params = $param;
        $this->server = $_SERVER;
        $this->parsedBody();
        $this->parseHeader();
    }

    /**
     * Retorna o valor de um parâmetro da rota.
     *
     * @param string $key A chave do parâmetro.
     * @param ?string $default O valor padrão se a chave não existir.
     * @return string|array|null
     */
    public function param(string $key, ?string $default = null): string|array|null
    {
        return $this->params[$key] ?? $default;
    }

    /**
     * Retorna o método HTTP da requisição.
     *
     * @return string O método HTTP em maiúsculas.
     */
    public function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    /**
     * Retorna o caminho da URI da requisição.
     *
     * @return string O caminho da URI.
     */
    public function uri(): string
    {
        return parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
    }

    /**
     * Retorna a query string da requisição.
     *
     * @return ?string A query string ou null se não existir.
     */
    public function queryString(): ?string
    {
        return parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_QUERY) ?? null;
    }

    /**
     * Retorna um valor de entrada (POST ou GET).
     *
     * @param string $key A chave do valor de entrada.
     * @return string|null|bool|array
     */
    public function input(string $key): string|null|bool|array
    {
        return $_POST[$key] ?? $_GET[$key] ?? null;
    }

    /**
     * Retorna um valor POST.
     *
     * @param string $key A chave do valor de entrada.
     * @return string|null|bool|array
     */
    public function post(string $key): string|null|bool|array
    {
        return $_POST[$key] ?? null;
    }

    /**
     * Retorna um valor GET.
     *
     * @param string $key A chave do valor de entrada.
     * @return string|null|bool|array
     */
    public function get(string $key): string|null|bool|array
    {
        return $_GET[$key] ?? null;
    }

    /**
     * Retorna informações sobre um arquivo enviado.
     *
     * @param string $key A chave do arquivo.
     * @return ?array Informações do arquivo ou null.
     */
    public function file(string $key): ?array
    {
        return $_FILES[$key] ?? null;
    }

    /**
     * Retorna o valor de um cookie.
     *
     * @param string $key A chave do cookie.
     * @param mixed $default O valor padrão se a chave não existir.
     * @return mixed
     */
    public function cookie(string $key, mixed $default = null): mixed
    {
        return $_COOKIE[$key] ?? $default;
    }

    /**
     * Retorna o endereço IP do cliente.
     *
     * @return ?string O IP do cliente ou null.
     */
    public function ip(): ?string
    {
        return $_SERVER['HTTP_CLIENT_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
    }

    /**
     * Retorna o user agent da requisição.
     *
     * @return ?string O user agent ou null.
     */
    public function userAgent(): ?string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? null;
    }

    /**
     * Verifica se o método da requisição corresponde ao fornecido.
     *
     * @param string $method O método HTTP a comparar.
     * @return bool
     */
    public function isMethod(string $method): bool
    {
        return $this->method() === strtoupper($method);
    }

    /**
     * Verifica se a requisição é AJAX.
     *
     * @return bool
     */
    public function isAjax(): bool
    {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }

    /**
     * Obtém um cabeçalho HTTP específico.
     *
     * @param string $name Nome do cabeçalho
     * @param string|null $default Valor padrão se o cabeçalho não existir
     * @return string|null
     */
    public function getHeader(string $name, ?string $default = null): ?string
    {
        $name = str_replace('_', '-', strtoupper($name));
        return $this->headers[$name] ?? $default;
    }


    /**
     * Define um atributo na resposta.
     *
     * @param string $key
     * @param mixed $value
     * @return self
     */
    public function setAttribute(string $key, mixed $value): self {
        $this->attributes[$key] = $value;
        return $this;
    }

    /**
     * Obtém um atributo da resposta.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getAttribute(string $key, mixed $default = null) {
        return $this->attributes[$key] ?? $default;
    }

    public function parseHeader(): void {
        foreach($this->server as $key => $value):
            if(str_starts_with($key, 'HTTP_')):
                $headerName = str_replace('_', '-', substr($key, 5));
                $this->headers[$headerName] = $value;
            endif;
        endforeach;
    }

    public function parsedBody(): void {
        $input = file_get_contents('php://input');
        $contentType = $this->server['CONTENT_TYPE'] ?? 'application/x-www-form-urlencoded';

        if(str_contains($contentType, 'application/json')):
            $this->parsedBody = json_decode($input, true) ?? [];
        elseif(str_contains($contentType, 'application/x-www-form-urlencoded')):
            parse_str($input, $this->parsedBody);
        endif;
    }

    /**
     * Obtém o corpo da requisição parseado.
     * @return array
     */
    public function getParsedBody(): array {
        return $this->parsedBody;
    }

}