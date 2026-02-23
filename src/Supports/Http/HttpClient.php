<?php

/*
|--------------------------------------------------------------------------
| Classe HttpClient
|--------------------------------------------------------------------------
|
| Esta classe fornece uma interface fluida e robusta para realizar requisições HTTP,
| com suporte a métodos HTTP, autenticação, cabeçalhos personalizados, corpo da requisição,
| retries, timeouts, e integração com o framework Slenix.
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Http;

use Exception;
use RuntimeException;
use InvalidArgumentException;
use Slenix\Http\Response;

class HttpClient
{
    /**
     * @var array Configurações padrão para a requisição
     */
    protected array $options = [
        'timeout' => 30,
        'connect_timeout' => 5,
        'verify' => true,
        'http_errors' => true,
        'retries' => 0,
        'retry_delay' => 1000,
        'follow_redirects' => true,
        'max_redirects' => 5,
        'user_agent' => 'Slenix-HttpClient/1.0',
    ];

    /**
     * @var array Cabeçalhos da requisição
     */
    protected array $headers = [];

    /**
     * @var mixed Dados do corpo da requisição
     */
    protected mixed $body = null;

    /**
     * @var string|null URL base para as requisições
     */
    protected ?string $baseUrl = null;

    /**
     * @var string|null Método HTTP
     */
    protected ?string $method = null;

    /**
     * @var string|null URL da requisição
     */
    protected ?string $url = null;

    /**
     * @var array|null Dados de autenticação
     */
    protected ?array $auth = null;

    /**
     * @var array Eventos de callback (before, after, error)
     */
    protected array $events = [];

    /**
     * @var array Status codes que devem ser considerados erros
     */
    protected array $errorStatusCodes = [400, 401, 403, 404, 500, 502, 503];

    /**
     * Construtor da classe HttpClient.
     *
     * @param array $options Configurações iniciais
     */
    public function __construct(array $options = [])
    {
        $this->options = array_merge($this->options, $options);
        $this->validateOptions();
    }

    /**
     * Valida as opções fornecidas.
     *
     * @throws InvalidArgumentException
     */
    protected function validateOptions(): void
    {
        if ($this->options['timeout'] <= 0) {
            throw new InvalidArgumentException('Timeout deve ser maior que 0');
        }
        
        if ($this->options['connect_timeout'] <= 0) {
            throw new InvalidArgumentException('Connect timeout deve ser maior que 0');
        }
        
        if ($this->options['retries'] < 0) {
            throw new InvalidArgumentException('Retries não pode ser negativo');
        }
    }

    /**
     * Define a URL base para as requisições.
     *
     * @param string $baseUrl
     * @return self
     * @throws InvalidArgumentException
     */
    public function baseUrl(string $baseUrl): self
    {
        if (!filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('URL base inválida');
        }
        
        $this->baseUrl = rtrim($baseUrl, '/');
        return $this;
    }

    /**
     * Define um cabeçalho para a requisição.
     *
     * @param string $name
     * @param string $value
     * @return self
     */
    public function withHeader(string $name, string $value): self
    {
        $this->headers[trim($name)] = trim($value);
        return $this;
    }

    /**
     * Define múltiplos cabeçalhos.
     *
     * @param array $headers
     * @return self
     */
    public function withHeaders(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->withHeader($name, $value);
        }
        return $this;
    }

    /**
     * Remove um cabeçalho.
     *
     * @param string $name
     * @return self
     */
    public function withoutHeader(string $name): self
    {
        unset($this->headers[trim($name)]);
        return $this;
    }

    /**
     * Define autenticação para a requisição (Basic Auth, Bearer Token, etc.).
     *
     * @param string $type Tipo de autenticação ('basic', 'bearer', 'digest')
     * @param string|array $credentials Credenciais (usuário/senha ou token)
     * @return self
     * @throws InvalidArgumentException
     */
    public function withAuth(string $type, string|array $credentials): self
    {
        $type = strtolower($type);
        
        if (!in_array($type, ['basic', 'bearer', 'digest'])) {
            throw new InvalidArgumentException('Tipo de autenticação não suportado');
        }
        
        if ($type === 'basic' && (!is_array($credentials) || count($credentials) !== 2)) {
            throw new InvalidArgumentException('Credenciais básicas devem ser um array [username, password]');
        }
        
        $this->auth = ['type' => $type, 'credentials' => $credentials];
        return $this;
    }

    /**
     * Define o corpo da requisição como JSON.
     *
     * @param array|object $data
     * @return self
     */
    public function asJson(array|object $data): self
    {
        $this->body = $data;
        $this->withHeader('Content-Type', 'application/json');
        return $this;
    }

    /**
     * Define o corpo da requisição como formulário (multipart/form-data).
     *
     * @param array $data
     * @return self
     */
    public function asForm(array $data): self
    {
        $this->body = $data;
        $this->withHeader('Content-Type', 'multipart/form-data');
        return $this;
    }

    /**
     * Define o corpo da requisição como URL-encoded.
     *
     * @param array $data
     * @return self
     */
    public function asFormUrlEncoded(array $data): self
    {
        $this->body = $data;
        $this->withHeader('Content-Type', 'application/x-www-form-urlencoded');
        return $this;
    }

    /**
     * Define o corpo da requisição como XML.
     *
     * @param string $xml
     * @return self
     */
    public function asXml(string $xml): self
    {
        $this->body = $xml;
        $this->withHeader('Content-Type', 'application/xml');
        return $this;
    }

    /**
     * Define o corpo da requisição como texto puro.
     *
     * @param string $text
     * @return self
     */
    public function asText(string $text): self
    {
        $this->body = $text;
        $this->withHeader('Content-Type', 'text/plain');
        return $this;
    }

    /**
     * Define o número de tentativas de repetição em caso de falha.
     *
     * @param int $retries
     * @param int $delay Delay entre tentativas (em milissegundos)
     * @return self
     * @throws InvalidArgumentException
     */
    public function withRetries(int $retries, int $delay = 1000): self
    {
        if ($retries < 0) {
            throw new InvalidArgumentException('Número de retries não pode ser negativo');
        }
        
        if ($delay < 0) {
            throw new InvalidArgumentException('Delay não pode ser negativo');
        }
        
        $this->options['retries'] = $retries;
        $this->options['retry_delay'] = $delay;
        return $this;
    }

    /**
     * Define o tempo limite da requisição.
     *
     * @param int $timeout
     * @return self
     * @throws InvalidArgumentException
     */
    public function timeout(int $timeout): self
    {
        if ($timeout <= 0) {
            throw new InvalidArgumentException('Timeout deve ser maior que 0');
        }
        
        $this->options['timeout'] = $timeout;
        return $this;
    }

    /**
     * Define o user agent.
     *
     * @param string $userAgent
     * @return self
     */
    public function withUserAgent(string $userAgent): self
    {
        $this->options['user_agent'] = $userAgent;
        return $this;
    }

    /**
     * Registra um callback para um evento (before, after, error).
     *
     * @param string $event
     * @param callable $callback
     * @return self
     * @throws InvalidArgumentException
     */
    public function on(string $event, callable $callback): self
    {
        if (!in_array($event, ['before', 'after', 'error'])) {
            throw new InvalidArgumentException('Evento não suportado');
        }
        
        $this->events[$event][] = $callback;
        return $this;
    }

    /**
     * Executa uma requisição GET.
     *
     * @param string $url
     * @param array $query
     * @return Response
     */
    public function get(string $url, array $query = []): Response
    {
        return $this->request('GET', $url, ['query' => $query]);
    }

    /**
     * Executa uma requisição POST.
     *
     * @param string $url
     * @param mixed $data
     * @return Response
     */
    public function post(string $url, mixed $data = []): Response
    {
        return $this->request('POST', $url, ['body' => $data]);
    }

    /**
     * Executa uma requisição PUT.
     *
     * @param string $url
     * @param mixed $data
     * @return Response
     */
    public function put(string $url, mixed $data = []): Response
    {
        return $this->request('PUT', $url, ['body' => $data]);
    }

    /**
     * Executa uma requisição PATCH.
     *
     * @param string $url
     * @param mixed $data
     * @return Response
     */
    public function patch(string $url, mixed $data = []): Response
    {
        return $this->request('PATCH', $url, ['body' => $data]);
    }

    /**
     * Executa uma requisição DELETE.
     *
     * @param string $url
     * @return Response
     */
    public function delete(string $url): Response
    {
        return $this->request('DELETE', $url);
    }

    /**
     * Executa uma requisição HEAD.
     *
     * @param string $url
     * @return Response
     */
    public function head(string $url): Response
    {
        return $this->request('HEAD', $url);
    }

    /**
     * Executa uma requisição OPTIONS.
     *
     * @param string $url
     * @return Response
     */
    public function options(string $url): Response
    {
        return $this->request('OPTIONS', $url);
    }

    /**
     * Método genérico para executar requisições HTTP.
     *
     * @param string $method
     * @param string $url
     * @param array $options
     * @return Response
     */
    public function request(string $method, string $url, array $options = []): Response
    {
        $this->method = strtoupper($method);
        
        // Aplica query parameters se fornecidos
        $query = $options['query'] ?? [];
        $this->url = $this->buildUrl($url, $query);
        
        // Aplica body se fornecido
        if (isset($options['body'])) {
            $this->body = $options['body'];
        }
        
        return $this->send();
    }

    /**
     * Constrói a URL completa com base na baseUrl e parâmetros de query.
     *
     * @param string $url
     * @param array $query
     * @return string
     */
    protected function buildUrl(string $url, array $query = []): string
    {
        $base = $this->baseUrl ? rtrim($this->baseUrl, '/') . '/' : '';
        $url = $base . ltrim($url, '/');
        
        if (!empty($query)) {
            $separator = parse_url($url, PHP_URL_QUERY) ? '&' : '?';
            $url .= $separator . http_build_query($query);
        }
        
        return $url;
    }

    /**
     * Executa a requisição HTTP com sistema de retry.
     *
     * @return Response
     * @throws Exception
     */
    protected function send(): Response
    {
        $attempts = 0;
        $maxAttempts = $this->options['retries'] + 1;
        $lastException = null;

        while ($attempts < $maxAttempts) {
            try {
                $attempts++;
                
                // Dispara evento 'before'
                $this->dispatchEvent('before', [$this->method, $this->url, $this->body]);

                $response = $this->executeCurlRequest();
                
                // Verifica se deve considerar como erro
                if ($this->options['http_errors'] && in_array($response->getStatusCode(), $this->errorStatusCodes)) {
                    throw new RuntimeException("HTTP Error: {$response->getStatusCode()}");
                }

                // Dispara evento 'after'
                $this->dispatchEvent('after', [$response]);

                return $response;
                
            } catch (Exception $e) {
                $lastException = $e;
                
                if ($attempts >= $maxAttempts) {
                    // Dispara evento 'error'
                    $this->dispatchEvent('error', [$e]);
                    throw $e;
                }
                
                // Aguarda antes da próxima tentativa
                usleep($this->options['retry_delay'] * 1000);
            }
        }

        throw $lastException ?? new RuntimeException('Failed to execute request after retries.');
    }

    /**
     * Executa a requisição cURL.
     *
     * @return Response
     * @throws RuntimeException
     */
    protected function executeCurlRequest(): Response
    {
        $ch = curl_init();
        curl_setopt_array($ch, $this->buildCurlOptions());

        $responseContent = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $responseHeaders = curl_getinfo($ch, CURLINFO_HEADER_OUT);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        
        curl_close($ch);

        if ($responseContent === false) {
            throw new RuntimeException("cURL error ($errno): $error");
        }

        // Cria objeto Response melhorado
        $response = new Response();
        $response->status($httpCode);
        
        // Define o conteúdo baseado no tipo
        if ($contentType && stripos($contentType, 'application/json') !== false) {
            $decodedData = json_decode($responseContent, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $response->setContent($decodedData);
            } else {
                $response->setContent($responseContent);
            }
        } else {
            $response->setContent($responseContent);
        }

        return $response;
    }

    /**
     * Constrói as opções do cURL.
     *
     * @return array
     */
    protected function buildCurlOptions(): array
    {
        $options = [
            CURLOPT_URL => $this->url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_TIMEOUT => $this->options['timeout'],
            CURLOPT_CONNECTTIMEOUT => $this->options['connect_timeout'],
            CURLOPT_SSL_VERIFYPEER => $this->options['verify'],
            CURLOPT_FOLLOWLOCATION => $this->options['follow_redirects'],
            CURLOPT_MAXREDIRS => $this->options['max_redirects'],
            CURLOPT_USERAGENT => $this->options['user_agent'],
            CURLOPT_ENCODING => '', // Aceita todas as encodings suportadas
        ];

        // Define o método HTTP
        switch (strtoupper($this->method)) {
            case 'POST':
                $options[CURLOPT_POST] = true;
                break;
            case 'GET':
            case 'HEAD':
                // Métodos que não precisam de configuração especial
                break;
            default:
                $options[CURLOPT_CUSTOMREQUEST] = strtoupper($this->method);
                break;
        }

        // Define o corpo da requisição
        if ($this->body !== null && !in_array($this->method, ['GET', 'HEAD'])) {
            $options[CURLOPT_POSTFIELDS] = $this->prepareRequestBody();
        }

        // Configura autenticação
        $this->applyCurlAuth($options);

        // Define cabeçalhos
        if (!empty($this->headers)) {
            $headers = [];
            foreach ($this->headers as $name => $value) {
                $headers[] = "$name: $value";
            }
            $options[CURLOPT_HTTPHEADER] = $headers;
        }

        return $options;
    }

    /**
     * Prepara o corpo da requisição baseado no tipo de conteúdo.
     *
     * @return string
     */
    protected function prepareRequestBody(): string
    {
        $contentType = $this->headers['Content-Type'] ?? '';
        
        if (stripos($contentType, 'application/json') !== false) {
            return json_encode($this->body, JSON_UNESCAPED_UNICODE);
        }
        
        if (stripos($contentType, 'application/xml') !== false) {
            return (string) $this->body;
        }
        
        if (stripos($contentType, 'multipart/form-data') !== false) {
            // Para multipart, deixar cURL preparar automaticamente
            return $this->body;
        }
        
        if (is_array($this->body)) {
            return http_build_query($this->body);
        }
        
        return (string) $this->body;
    }

    /**
     * Aplica configurações de autenticação ao cURL.
     *
     * @param array &$options
     */
    protected function applyCurlAuth(array &$options): void
    {
        if (!$this->auth) {
            return;
        }

        switch ($this->auth['type']) {
            case 'basic':
                $credentials = $this->auth['credentials'];
                $options[CURLOPT_USERPWD] = $credentials[0] . ':' . $credentials[1];
                break;
                
            case 'bearer':
                $this->headers['Authorization'] = 'Bearer ' . $this->auth['credentials'];
                break;
                
            case 'digest':
                $credentials = $this->auth['credentials'];
                $options[CURLOPT_USERPWD] = $credentials[0] . ':' . $credentials[1];
                $options[CURLOPT_HTTPAUTH] = CURLAUTH_DIGEST;
                break;
        }
    }

    /**
     * Dispara os callbacks de um evento.
     *
     * @param string $event
     * @param array $params
     * @return void
     */
    protected function dispatchEvent(string $event, array $params): void
    {
        if (isset($this->events[$event])) {
            foreach ($this->events[$event] as $callback) {
                try {
                    call_user_func_array($callback, $params);
                } catch (Exception $e) {
                    // Log do erro mas não interrompe a execução
                    error_log("Erro no callback do evento {$event}: " . $e->getMessage());
                }
            }
        }
    }

    /**
     * Reseta o estado do cliente para uma nova requisição.
     *
     * @return self
     */
    public function reset(): self
    {
        $this->method = null;
        $this->url = null;
        $this->body = null;
        $this->headers = [];
        $this->auth = null;
        
        return $this;
    }

    /**
     * Método estático para criar uma nova instância do cliente.
     *
     * @param array $options
     * @return self
     */
    public static function make(array $options = []): self
    {
        return new self($options);
    }

    /**
     * Método estático para criar requisições rápidas GET.
     *
     * @param string $url
     * @param array $options
     * @return Response
     */
    public static function quickGet(string $url, array $options = []): Response
    {
        return self::make($options)->get($url);
    }

    /**
     * Método estático para criar requisições rápidas POST.
     *
     * @param string $url
     * @param mixed $data
     * @param array $options
     * @return Response
     */
    public static function quickPost(string $url, mixed $data = [], array $options = []): Response
    {
        return self::make($options)->post($url, $data);
    }
}