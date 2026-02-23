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

namespace Slenix\Http;

use InvalidArgumentException;
use Slenix\Supports\Uploads\Upload;

class Request
{
    // Propriedades principais
    private array $params = [];
    private array $server = [];
    private array $headers = [];
    private array $attributes = [];
    private array $queryParams = [];
    
    // Cache para lazy loading
    private ?array $parsedBody = null;
    private ?array $uploadedFiles = null;
    private ?string $rawBody = null;
    private ?array $deviceInfo = null;
    private ?array $acceptableLanguages = null;
    
    // Configurações
    private int $maxInputSize = 8388608; // 8MB
    private array $trustedProxies = [];
    private array $trustedHeaders = [
        'X-Forwarded-For',
        'X-Forwarded-Proto',
        'X-Forwarded-Host',
        'X-Forwarded-Port'
    ];

    /**
     * Construtor da classe Request.
     *
     * @param array<string, string> $params Array associativo contendo os parâmetros da rota.
     * @param array $server Dados do servidor (padrão $_SERVER)
     * @param array $query Dados da query string (padrão $_GET)
     * @param array $cookies Dados dos cookies (padrão $_COOKIE)
     * @param array $files Dados dos arquivos (padrão $_FILES)
     */
    public function __construct(
        array $params = [],
        array $server = [],
        array $query = [],
        array $cookies = [],
        array $files = []
    ) {
        $this->params = $params;
        $this->server = $server ?: $_SERVER;
        $this->queryParams = $query ?: ($_GET ?? []);
        
        $this->parseHeaders();
    }

    /**
     * Retorna o valor de um parâmetro da rota.
     *
     * @param string $key A chave do parâmetro.
     * @param mixed $default O valor padrão se a chave não existir.
     * @return mixed
     */
    public function param(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
    }

    /**
     * Retorna todos os parâmetros da rota.
     *
     * @return array
     */
    public function params(): array
    {
        return $this->params;
    }

    /**
     * Define um parâmetro da rota.
     *
     * @param string $key
     * @param mixed $value
     * @return self
     */
    public function setParam(string $key, mixed $value): self
    {
        $this->params[$key] = $value;
        return $this;
    }

    /**
     * Define múltiplos parâmetros da rota.
     *
     * @param array $params
     * @return self
     */
    public function setParams(array $params): self
    {
        $this->params = array_merge($this->params, $params);
        return $this;
    }

    /**
     * Retorna o método HTTP da requisição.
     *
     * @return string O método HTTP em maiúsculas.
     */
    public function method(): string
    {
        // Cache para evitar reprocessamento
        static $method = null;
        
        if ($method !== null) {
            return $method;
        }

        // Verifica método override via header ou campo oculto
        $overrideMethod = $this->getHeader('X-HTTP-Method-Override')
            ?? $this->input('_method')
            ?? null;

        if ($overrideMethod && $this->isMethod('POST')) {
            $method = strtoupper((string) $overrideMethod);
        } else {
            $method = strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');
        }

        return $method;
    }

    /**
     * Retorna o caminho da URI da requisição.
     *
     * @return string O caminho da URI.
     */
    public function uri(): string
    {
        static $uri = null;
        
        if ($uri === null) {
            $uri = parse_url($this->server['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
        }
        
        return $uri;
    }

    /**
     * Retorna a URI completa da requisição.
     *
     * @return string
     */
    public function fullUri(): string
    {
        return $this->server['REQUEST_URI'] ?? '/';
    }

    /**
     * Retorna a URL completa da requisição.
     *
     * @return string
     */
    public function url(): string
    {
        static $url = null;
        
        if ($url === null) {
            $scheme = $this->getScheme();
            $host = $this->getHost();
            $port = $this->getPort();
            $uri = $this->fullUri();

            // Inclui porta apenas se não for padrão
            $portString = '';
            if (($scheme === 'http' && $port !== 80) || ($scheme === 'https' && $port !== 443)) {
                $portString = ':' . $port;
            }

            $url = "{$scheme}://{$host}{$portString}{$uri}";
        }
        
        return $url;
    }

    /**
     * Retorna apenas a URL base (sem query string).
     *
     * @return string
     */
    public function baseUrl(): string
    {
        $url = $this->url();
        return strtok($url, '?') ?: $url;
    }

    /**
     * Retorna a query string da requisição.
     *
     * @return ?string A query string ou null se não existir.
     */
    public function queryString(): ?string
    {
        static $queryString = null;
        
        if ($queryString === null) {
            $queryString = parse_url($this->server['REQUEST_URI'] ?? '/', PHP_URL_QUERY);
        }
        
        return $queryString;
    }

    /**
     * Retorna um valor de entrada (POST, GET, JSON ou corpo parseado).
     *
     * @param string $key A chave do valor de entrada.
     * @param mixed $default Valor padrão
     * @return mixed
     */
    public function input(string $key, mixed $default = null): mixed
    {
        // Ordem de prioridade: JSON -> POST -> GET
        $parsedBody = $this->getParsedBody();
        
        if (array_key_exists($key, $parsedBody)) {
            return $parsedBody[$key];
        }

        if (isset($_POST[$key])) {
            return $_POST[$key];
        }

        if (isset($this->queryParams[$key])) {
            return $this->queryParams[$key];
        }

        return $default;
    }

    /**
     * Retorna todos os dados de entrada combinados.
     *
     * @return array
     */
    public function all(): array
    {
        return array_merge($this->queryParams, $_POST, $this->getParsedBody());
    }

    /**
     * Retorna apenas os campos especificados.
     *
     * @param array|string $keys
     * @return array
     */
    public function only(array|string $keys): array
    {
        $keys = is_string($keys) ? func_get_args() : $keys;
        $data = $this->all();
        return array_intersect_key($data, array_flip($keys));
    }

    /**
     * Retorna todos os campos exceto os especificados.
     *
     * @param array|string $keys
     * @return array
     */
    public function except(array|string $keys): array
    {
        $keys = is_string($keys) ? func_get_args() : $keys;
        $data = $this->all();
        return array_diff_key($data, array_flip($keys));
    }

    /**
     * Verifica se um campo existe nos dados de entrada.
     *
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool
    {
        return $this->input($key) !== null;
    }

    /**
     * Verifica se múltiplos campos existem.
     *
     * @param array $keys
     * @return bool
     */
    public function hasAny(array $keys): bool
    {
        foreach ($keys as $key) {
            if ($this->has($key)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Verifica se um campo existe e não está vazio.
     *
     * @param string $key
     * @return bool
     */
    public function filled(string $key): bool
    {
        $value = $this->input($key);
        
        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        if (is_array($value)) {
            return !empty($value);
        }

        if (is_bool($value)) {
            return true;
        }

        return $value !== '' && $value !== 0 && $value !== '0';
    }

    /**
     * Verifica se um campo está vazio ou ausente.
     *
     * @param string $key
     * @return bool
     */
    public function missing(string $key): bool
    {
        return !$this->filled($key);
    }

    /**
     * Retorna um valor POST.
     *
     * @param string $key A chave do valor de entrada.
     * @param mixed $default Valor padrão
     * @return mixed
     */
    public function post(string $key, mixed $default = null): mixed
    {
        return $_POST[$key] ?? $default;
    }

    /**
     * Retorna todos os dados POST.
     *
     * @return array
     */
    public function postData(): array
    {
        return $_POST;
    }

    /**
     * Retorna um valor GET.
     *
     * @param string $key A chave do valor de entrada.
     * @param mixed $default Valor padrão
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->queryParams[$key] ?? $default;
    }

    /**
     * Retorna um parâmetro de query.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function query(string $key, mixed $default = null): mixed
    {
        return $this->get($key, $default);
    }

    /**
     * Retorna todos os parâmetros de query.
     *
     * @return array
     */
    public function queryParams(): array
    {
        return $this->queryParams;
    }

    /**
     * Verifica se um arquivo foi enviado.
     *
     * @param string $key
     * @return bool
     */
    public function hasFile(string $key): bool
    {
        // Verifica se $_FILES[$key] existe, é um array e contém todas as chaves necessárias
        if (!isset($_FILES[$key]) || !is_array($_FILES[$key])) {
            return false;
        }

        // Verifica chaves obrigatórias para um arquivo único
        $requiredKeys = ['name', 'tmp_name', 'size', 'error'];
        foreach ($requiredKeys as $requiredKey) {
            if (!array_key_exists($requiredKey, $_FILES[$key]) || 
                (is_array($_FILES[$key][$requiredKey]) && !isset($_FILES[$key]['name'][0]))) {
                error_log("Erro no upload: Chave obrigatória '{$requiredKey}' ausente ou inválida para a chave '{$key}'.");
                return false;
            }
        }

        // Para arquivos únicos, verifica se é válido
        if (!is_array($_FILES[$key]['name'])) {
            return $_FILES[$key]['error'] !== UPLOAD_ERR_NO_FILE && !empty($_FILES[$key]['tmp_name']);
        }

        // Para múltiplos arquivos, verifica se pelo menos um é válido
        return is_array($_FILES[$key]['name']) && !empty($_FILES[$key]['name'][0]);
    }

    /**
     * Retorna uma nova instância da classe Upload para o arquivo especificado.
     *
     * @param string $key A chave do arquivo no array $_FILES.
     * @return Upload
     */
    public function file(string $key): Upload
    {
        $fileData = $_FILES[$key] ?? [];
        if (is_array($fileData['name']) && isset($fileData['name'][0])) {
            // Para múltiplos arquivos, retorna o primeiro
            $normalizedFiles = $this->normalizeNestedFiles($fileData);
            $fileData = $normalizedFiles[0] ?? [];
        }
        return new Upload($fileData);
    }

    /**
     * Retorna todos os arquivos enviados como objetos Upload.
     *
     * @return array<string, Upload>
     */
    public function files(): array
    {
        if ($this->uploadedFiles === null) {
            $this->uploadedFiles = Upload::createMultiple($_FILES);
        }

        return $this->uploadedFiles;
    }

    /**
     * Normaliza arquivos aninhados para múltiplos uploads.
     *
     * @param array $fileData
     * @return array
     */
    private function normalizeNestedFiles(array $fileData): array
    {
        $normalized = [];
        $keys = ['name', 'type', 'tmp_name', 'error', 'size'];
        $count = count($fileData['name'] ?? []);

        for ($i = 0; $i < $count; $i++) {
            $singleFile = [];
            foreach ($keys as $key) {
                $singleFile[$key] = $fileData[$key][$i] ?? ($fileData[$key] ?? null);
            }
            $normalized[] = $singleFile;
        }

        return $normalized;
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
     * Retorna todos os cookies.
     *
     * @return array
     */
    public function cookies(): array
    {
        return $_COOKIE;
    }

    /**
     * Retorna o endereço IP do cliente (considerando proxies confiáveis).
     *
     * @return ?string O IP do cliente ou null.
     */
    public function ip(): ?string
    {
        static $ip = null;
        
        if ($ip !== null) {
            return $ip;
        }

        // Se há proxies confiáveis, verifica headers de forwarding
        if (!empty($this->trustedProxies)) {
            $forwardedIp = $this->getForwardedIp();
            if ($forwardedIp) {
                $ip = $forwardedIp;
                return $ip;
            }
        }

        // Headers padrão para detecção de IP
        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];

        foreach ($headers as $header) {
            if (!empty($this->server[$header])) {
                $ips = explode(',', $this->server[$header]);
                $cleanIp = trim($ips[0]);

                if ($this->isValidIp($cleanIp)) {
                    $ip = $cleanIp;
                    return $ip;
                }
            }
        }

        $ip = $this->server['REMOTE_ADDR'] ?? null;
        return $ip;
    }

    /**
     * Retorna o user agent da requisição.
     *
     * @return ?string O user agent ou null.
     */
    public function userAgent(): ?string
    {
        return $this->server['HTTP_USER_AGENT'] ?? null;
    }

    /**
     * Retorna o host da requisição.
     *
     * @return string
     */
    public function getHost(): string
    {
        // Verifica headers de proxy confiável primeiro
        if (!empty($this->trustedProxies)) {
            $forwardedHost = $this->getHeader('X-Forwarded-Host');
            if ($forwardedHost && $this->isTrustedProxy($this->server['REMOTE_ADDR'] ?? '')) {
                $hosts = explode(',', $forwardedHost);
                return trim($hosts[0]);
            }
        }

        return $this->server['HTTP_HOST'] ?? $this->server['SERVER_NAME'] ?? 'localhost';
    }

    /**
     * Retorna a porta da requisição.
     *
     * @return int
     */
    public function getPort(): int
    {
        // Verifica header de proxy confiável primeiro
        if (!empty($this->trustedProxies)) {
            $forwardedPort = $this->getHeader('X-Forwarded-Port');
            if ($forwardedPort && $this->isTrustedProxy($this->server['REMOTE_ADDR'] ?? '')) {
                return (int) $forwardedPort;
            }
        }

        return (int) ($this->server['SERVER_PORT'] ?? ($this->isSecure() ? 443 : 80));
    }

    /**
     * Retorna o esquema da requisição (http ou https).
     *
     * @return string
     */
    public function getScheme(): string
    {
        return $this->isSecure() ? 'https' : 'http';
    }

    /**
     * Verifica se a conexão é segura (HTTPS).
     *
     * @return bool
     */
    public function isSecure(): bool
    {
        static $isSecure = null;
        
        if ($isSecure !== null) {
            return $isSecure;
        }

        // Verifica headers de proxy confiável primeiro
        if (!empty($this->trustedProxies)) {
            $forwardedProto = $this->getHeader('X-Forwarded-Proto');
            if ($forwardedProto && $this->isTrustedProxy($this->server['REMOTE_ADDR'] ?? '')) {
                $isSecure = strtolower($forwardedProto) === 'https';
                return $isSecure;
            }
        }

        $isSecure = (
            (!empty($this->server['HTTPS']) && $this->server['HTTPS'] !== 'off') ||
            (!empty($this->server['HTTP_X_FORWARDED_PROTO']) && $this->server['HTTP_X_FORWARDED_PROTO'] === 'https') ||
            (!empty($this->server['HTTP_X_FORWARDED_SSL']) && $this->server['HTTP_X_FORWARDED_SSL'] === 'on') ||
            $this->getPort() === 443
        );
        
        return $isSecure;
    }

    /**
     * Verifica se o método da requisição corresponde ao fornecido.
     *
     * @param string|array $methods O(s) método(s) HTTP a comparar.
     * @return bool
     */
    public function isMethod(string|array $methods): bool
    {
        $methods = is_string($methods) ? [$methods] : $methods;
        return in_array($this->method(), array_map('strtoupper', $methods));
    }

    /**
     * Verifica se a requisição é AJAX.
     *
     * @return bool
     */
    public function isAjax(): bool
    {
        return strtolower($this->getHeader('X-Requested-With', '')) === 'xmlhttprequest';
    }

    /**
     * Verifica se a requisição é JSON.
     *
     * @return bool
     */
    public function isJson(): bool
    {
        $contentType = $this->getHeader('Content-Type', '');
        return str_contains(strtolower($contentType), 'application/json');
    }

    /**
     * Verifica se a requisição espera uma resposta JSON.
     *
     * @return bool
     */
    public function expectsJson(): bool
    {
        return $this->isAjax() || $this->wantsJson();
    }

    /**
     * Verifica se a requisição quer uma resposta JSON.
     *
     * @return bool
     */
    public function wantsJson(): bool
    {
        $acceptable = $this->getHeader('Accept', '');
        return str_contains(strtolower($acceptable), 'application/json');
    }

    /**
     * Verifica se a requisição aceita HTML.
     *
     * @return bool
     */
    public function acceptsHtml(): bool
    {
        $acceptable = $this->getHeader('Accept', '');
        return str_contains(strtolower($acceptable), 'text/html');
    }

    /**
     * Obtém um cabeçalho HTTP específico.
     *
     * @param string $name Nome do cabeçalho
     * @param mixed $default Valor padrão se o cabeçalho não existir
     * @return mixed
     */
    public function getHeader(string $name, mixed $default = null): mixed
    {
        $name = $this->normalizeHeaderName($name);
        return $this->headers[$name] ?? $default;
    }

    /**
     * Retorna todos os cabeçalhos.
     *
     * @return array
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Verifica se um cabeçalho existe.
     *
     * @param string $name
     * @return bool
     */
    public function hasHeader(string $name): bool
    {
        $name = $this->normalizeHeaderName($name);
        return isset($this->headers[$name]);
    }

    /**
     * Retorna o valor de um cabeçalho como string.
     *
     * @param string $name
     * @param string|null $default
     * @return string|null
     */
    public function getHeaderLine(string $name, ?string $default = null): ?string
    {
        $header = $this->getHeader($name);

        if ($header === null) {
            return $default;
        }

        if (is_array($header)) {
            return implode(', ', $header);
        }

        return (string) $header;
    }

    /**
     * Define um atributo na requisição.
     *
     * @param string $key
     * @param mixed $value
     * @return self
     */
    public function setAttribute(string $key, mixed $value): self
    {
        $this->attributes[$key] = $value;
        return $this;
    }

    /**
     * Obtém um atributo da requisição.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * Retorna todos os atributos.
     *
     * @return array
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Remove um atributo.
     *
     * @param string $key
     * @return self
     */
    public function removeAttribute(string $key): self
    {
        unset($this->attributes[$key]);
        return $this;
    }

    /**
     * Obtém o corpo da requisição parseado.
     *
     * @return array
     */
    public function getParsedBody(): array
    {
        if ($this->parsedBody === null) {
            $this->parsedBody();
        }
        
        return $this->parsedBody ?? [];
    }

    /**
     * Obtém o conteúdo bruto da requisição.
     *
     * @return string
     */
    public function getRawBody(): string
    {
        if ($this->rawBody === null) {
            $this->rawBody = file_get_contents('php://input');
            
            // Verifica o tamanho do corpo
            if (strlen($this->rawBody) > $this->maxInputSize) {
                throw new InvalidArgumentException('Corpo da requisição muito grande');
            }
        }
        
        return $this->rawBody;
    }

    /**
     * Verifica se o corpo da requisição está vazio.
     *
     * @return bool
     */
    public function hasBody(): bool
    {
        return !empty($this->getRawBody());
    }

    /**
     * Obtém dados do servidor.
     *
     * @param string|null $key
     * @param mixed $default
     * @return mixed
     */
    public function server(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->server;
        }

        return $this->server[$key] ?? $default;
    }

    /**
     * Valida se os campos obrigatórios estão presentes e preenchidos.
     *
     * @param array $required
     * @return array Lista de campos faltando
     */
    public function validate(array $required): array
    {
        $missing = [];

        foreach ($required as $field) {
            if (!$this->filled($field)) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * Sanitiza um valor de entrada.
     *
     * @param string $key
     * @param string $filter Tipo de filtro
     * @param mixed $default
     * @return mixed
     */
    public function sanitize(string $key, string $filter = 'string', mixed $default = null): mixed
    {
        $value = $this->input($key, $default);

        if ($value === null || $value === $default) {
            return $default;
        }

        return match ($filter) {
            'string' => htmlspecialchars(strip_tags((string) $value), ENT_QUOTES, 'UTF-8'),
            'email' => filter_var($value, FILTER_SANITIZE_EMAIL),
            'url' => filter_var($value, FILTER_SANITIZE_URL),
            'int' => filter_var($value, FILTER_SANITIZE_NUMBER_INT),
            'float' => filter_var($value, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION),
            'html' => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'),
            'trim' => trim((string) $value),
            'lower' => strtolower((string) $value),
            'upper' => strtoupper((string) $value),
            'slug' => $this->createSlug((string) $value),
            default => $value,
        };
    }

    /**
     * Sanitiza múltiplos campos de uma vez.
     *
     * @param array $rules ['campo' => 'filtro']
     * @return array
     */
    public function sanitizeMultiple(array $rules): array
    {
        $sanitized = [];
        
        foreach ($rules as $key => $filter) {
            $sanitized[$key] = $this->sanitize($key, $filter);
        }
        
        return $sanitized;
    }

    /**
     * Obtém o referer da requisição.
     *
     * @param string|null $default
     * @return string|null
     */
    public function referer(?string $default = null): ?string
    {
        return $this->getHeader('Referer') ?? $default;
    }

    /**
     * Verifica se a requisição veio de uma origem específica.
     *
     * @param string|array $origins
     * @return bool
     */
    public function isFromOrigin(string|array $origins): bool
    {
        $referer = $this->referer();

        if (!$referer) {
            return false;
        }

        $origins = is_array($origins) ? $origins : [$origins];

        foreach ($origins as $origin) {
            if (str_starts_with($referer, $origin)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verifica se a requisição é de um bot/crawler.
     *
     * @return bool
     */
    public function isBot(): bool
    {
        $userAgent = strtolower($this->userAgent() ?? '');
        
        if (empty($userAgent)) {
            return false;
        }

        $botSignatures = [
            'googlebot', 'bingbot', 'slurp', 'duckduckbot', 'baiduspider',
            'yandexbot', 'facebookexternalhit', 'twitterbot', 'linkedinbot',
            'whatsapp', 'telegram', 'crawler', 'spider', 'bot', 'scraper'
        ];

        foreach ($botSignatures as $signature) {
            if (str_contains($userAgent, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Obtém informações do dispositivo baseado no User-Agent.
     *
     * @return array
     */
    public function getDeviceInfo(): array
    {
        if ($this->deviceInfo === null) {
            $userAgent = $this->userAgent() ?? '';
            
            $isMobile = $this->detectMobile($userAgent);
            $isTablet = $this->detectTablet($userAgent);
            
            $this->deviceInfo = [
                'is_mobile' => $isMobile,
                'is_tablet' => $isTablet,
                'is_desktop' => !$isMobile && !$isTablet,
                'is_bot' => $this->isBot(),
                'os' => $this->detectOS($userAgent),
                'browser' => $this->detectBrowser($userAgent),
                'user_agent' => $userAgent,
            ];
        }
        
        return $this->deviceInfo;
    }

    /**
     * Verifica se a requisição é de um dispositivo móvel.
     *
     * @return bool
     */
    public function isMobile(): bool
    {
        return $this->getDeviceInfo()['is_mobile'];
    }

    /**
     * Verifica se a requisição é de um tablet.
     *
     * @return bool
     */
    public function isTablet(): bool
    {
        return $this->getDeviceInfo()['is_tablet'];
    }

    /**
     * Verifica se a requisição é de um desktop.
     *
     * @return bool
     */
    public function isDesktop(): bool
    {
        return $this->getDeviceInfo()['is_desktop'];
    }

    /**
     * Obtém as linguagens aceitas pelo cliente.
     *
     * @return array
     */
    public function getAcceptableLanguages(): array
    {
        if ($this->acceptableLanguages === null) {
            $this->acceptableLanguages = [];
            $acceptLanguage = $this->getHeader('Accept-Language', '');

            if ($acceptLanguage) {
                $languages = [];
                $parts = explode(',', $acceptLanguage);

                foreach ($parts as $part) {
                    $part = trim($part);
                    if (preg_match('/([a-z-]+)(?:;q=([0-9.]+))?/i', $part, $matches)) {
                        $language = strtolower($matches[1]);
                        $quality = isset($matches[2]) ? (float) $matches[2] : 1.0;
                        $languages[$language] = $quality;
                    }
                }

                arsort($languages);
                $this->acceptableLanguages = array_keys($languages);
            }
        }
        
        return $this->acceptableLanguages;
    }

    /**
     * Obtém a linguagem preferida do cliente.
     *
     * @param array $available Linguagens disponíveis
     * @return string|null
     */
    public function getPreferredLanguage(array $available = []): ?string
    {
        $acceptable = $this->getAcceptableLanguages();

        if (empty($available)) {
            return $acceptable[0] ?? null;
        }

        foreach ($acceptable as $language) {
            if (in_array($language, $available)) {
                return $language;
            }

            // Verifica idioma base (ex: en de en-US)
            $baseLang = substr($language, 0, 2);
            if (in_array($baseLang, $available)) {
                return $baseLang;
            }
        }

        return null;
    }

    /**
     * Define proxies confiáveis.
     *
     * @param array $proxies
     * @return self
     */
    public function setTrustedProxies(array $proxies): self
    {
        $this->trustedProxies = $proxies;
        return $this;
    }

    /**
     * Define headers confiáveis de proxy.
     *
     * @param array $headers
     * @return self
     */
    public function setTrustedHeaders(array $headers): self
    {
        $this->trustedHeaders = $headers;
        return $this;
    }

    /**
     * Define o tamanho máximo do corpo da requisição.
     *
     * @param int $size Tamanho em bytes
     * @return self
     */
    public function setMaxInputSize(int $size): self
    {
        $this->maxInputSize = $size;
        return $this;
    }

    /**
     * Cria uma nova instância de Request a partir dos dados globais atuais.
     *
     * @param array $params
     * @return self
     */
    public static function createFromGlobals(array $params = []): self
    {
        return new self($params);
    }

    /**
     * Cria uma nova instância de Request para testes.
     *
     * @param string $method
     * @param string $uri
     * @param array $data
     * @param array $headers
     * @param array $server
     * @return self
     */
    public static function create(
        string $method = 'GET',
        string $uri = '/',
        array $data = [],
        array $headers = [],
        array $server = []
    ): self {
        $serverData = array_merge($_SERVER, $server, [
            'REQUEST_METHOD' => strtoupper($method),
            'REQUEST_URI' => $uri,
            'PATH_INFO' => parse_url($uri, PHP_URL_PATH),
            'QUERY_STRING' => parse_url($uri, PHP_URL_QUERY) ?? '',
        ]);

        // Define headers customizados
        foreach ($headers as $name => $value) {
            $headerKey = 'HTTP_' . str_replace('-', '_', strtoupper($name));
            $serverData[$headerKey] = $value;
        }

        $queryParams = [];
        $postData = [];

        // Define dados baseado no método
        if (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            $postData = $data;
            $_POST = $data; // Para compatibilidade
        } else {
            $queryParams = $data;
        }

        $request = new self([], $serverData, $queryParams);

        // Se há dados JSON, simula
        if (isset($headers['Content-Type']) && str_contains($headers['Content-Type'], 'application/json')) {
            $request->parsedBody = $data;
        }

        return $request;
    }

    /**
     * Converte a requisição para array (útil para debugging).
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'method' => $this->method(),
            'uri' => $this->uri(),
            'full_uri' => $this->fullUri(),
            'url' => $this->url(),
            'base_url' => $this->baseUrl(),
            'query_string' => $this->queryString(),
            'is_secure' => $this->isSecure(),
            'is_ajax' => $this->isAjax(),
            'is_json' => $this->isJson(),
            'expects_json' => $this->expectsJson(),
            'ip' => $this->ip(),
            'user_agent' => $this->userAgent(),
            'host' => $this->getHost(),
            'port' => $this->getPort(),
            'scheme' => $this->getScheme(),
            'referer' => $this->referer(),
            'params' => $this->params,
            'query_params' => $this->queryParams,
            'post_data' => $_POST,
            'parsed_body' => $this->getParsedBody(),
            'all_input' => $this->all(),
            'headers' => $this->headers,
            'cookies' => $_COOKIE,
            'files' => array_keys($this->files()),
            'attributes' => $this->attributes,
            'device_info' => $this->getDeviceInfo(),
            'acceptable_languages' => $this->getAcceptableLanguages(),
            'preferred_language' => $this->getPreferredLanguage(),
        ];
    }

    /**
     * Debug da requisição (retorna informações formatadas).
     *
     * @return string
     */
    public function debug(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Converte a requisição para string (para logs).
     *
     * @return string
     */
    public function __toString(): string
    {
        return sprintf(
            '%s %s [%s] - IP: %s - UA: %s',
            $this->method(),
            $this->uri(),
            $this->isSecure() ? 'HTTPS' : 'HTTP',
            $this->ip() ?? 'unknown',
            substr($this->userAgent() ?? 'unknown', 0, 100)
        );
    }

    /**
     * Parse dos cabeçalhos HTTP.
     *
     * @return void
     */
    private function parseHeaders(): void
    {
        $this->headers = [];
        
        foreach ($this->server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headerName = str_replace('_', '-', substr($key, 5));
                $this->headers[$headerName] = $value;
            }
        }

        // Adiciona cabeçalhos especiais que não começam com HTTP_
        $specialHeaders = [
            'CONTENT_TYPE' => 'CONTENT-TYPE',
            'CONTENT_LENGTH' => 'CONTENT-LENGTH',
            'CONTENT_MD5' => 'CONTENT-MD5',
            'CONTENT_ENCODING' => 'CONTENT-ENCODING',
        ];

        foreach ($specialHeaders as $serverKey => $headerName) {
            if (isset($this->server[$serverKey])) {
                $this->headers[$headerName] = $this->server[$serverKey];
            }
        }
    }

    /**
     * Parse do corpo da requisição baseado no Content-Type.
     *
     * @return void
     */
    private function parsedBody(): void
    {
        if ($this->parsedBody !== null) {
            return;
        }

        $input = $this->getRawBody();

        if (empty($input)) {
            $this->parsedBody = [];
            return;
        }

        $contentType = $this->getHeader('Content-Type', '');

        try {
            if (str_contains($contentType, 'application/json')) {
                $decoded = json_decode($input, true, 512, JSON_THROW_ON_ERROR);
                $this->parsedBody = is_array($decoded) ? $decoded : [];
            } elseif (str_contains($contentType, 'application/x-www-form-urlencoded')) {
                parse_str($input, $this->parsedBody);
                $this->parsedBody = $this->parsedBody ?? [];
            } elseif (str_contains($contentType, 'application/xml') || str_contains($contentType, 'text/xml')) {
                $this->parseXmlBody($input);
            } elseif (str_contains($contentType, 'multipart/form-data')) {
                // Para multipart, os dados já estão em $_POST
                $this->parsedBody = $_POST;
            } else {
                // Para outros tipos, armazena o conteúdo bruto
                $this->parsedBody = ['_raw' => $input];
            }
        } catch (\JsonException $e) {
            throw new InvalidArgumentException('JSON inválido: ' . $e->getMessage());
        } catch (\Exception $e) {
            throw new InvalidArgumentException('Erro ao processar corpo da requisição: ' . $e->getMessage());
        }
    }

    /**
     * Parse do corpo XML.
     *
     * @param string $input
     * @return void
     */
    private function parseXmlBody(string $input): void
    {
        if (empty($input)) {
            $this->parsedBody = [];
            return;
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($input, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NOERROR);

        if ($xml !== false) {
            $this->parsedBody = json_decode(json_encode($xml), true) ?? [];
        } else {
            $errors = libxml_get_errors();
            $errorMessage = 'XML inválido';
            if (!empty($errors)) {
                $errorMessage .= ': ' . $errors[0]->message;
            }
            libxml_clear_errors();
            throw new InvalidArgumentException($errorMessage);
        }
    }

    /**
     * Normaliza nome de cabeçalho.
     *
     * @param string $name
     * @return string
     */
    private function normalizeHeaderName(string $name): string
    {
        return str_replace('_', '-', strtoupper($name));
    }

    /**
     * Valida requisição básica.
     *
     * @return void
     * @throws InvalidArgumentException
     */
    private function validateRequest(): void
    {
        // Valida método HTTP
        $method = $this->method();
        $allowedMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];
        
        if (!in_array($method, $allowedMethods)) {
            throw new InvalidArgumentException("Método HTTP inválido: {$method}");
        }

        // Valida Content-Length se presente
        $contentLength = $this->getHeader('Content-Length');
        if ($contentLength !== null && !is_numeric($contentLength)) {
            throw new InvalidArgumentException('Content-Length inválido');
        }

        // Valida tamanho máximo do corpo se configurado
        if ($contentLength && (int) $contentLength > $this->maxInputSize) {
            throw new InvalidArgumentException('Corpo da requisição muito grande');
        }
    }

    /**
     * Verifica se um IP é válido.
     *
     * @param string $ip
     * @return bool
     */
    private function isValidIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /**
     * Obtém IP de headers de proxy.
     *
     * @return string|null
     */
    private function getForwardedIp(): ?string
    {
        $remoteAddr = $this->server['REMOTE_ADDR'] ?? '';
        
        if (!$this->isTrustedProxy($remoteAddr)) {
            return null;
        }

        foreach ($this->trustedHeaders as $header) {
            $headerValue = $this->getHeader($header);
            if ($headerValue) {
                $ips = explode(',', $headerValue);
                $cleanIp = trim($ips[0]);
                
                if ($this->isValidIp($cleanIp)) {
                    return $cleanIp;
                }
            }
        }

        return null;
    }

    /**
     * Verifica se o IP é de um proxy confiável.
     *
     * @param string $ip
     * @return bool
     */
    private function isTrustedProxy(string $ip): bool
    {
        if (empty($this->trustedProxies)) {
            return false;
        }

        foreach ($this->trustedProxies as $proxy) {
            if ($ip === $proxy || $this->ipInRange($ip, $proxy)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verifica se um IP está dentro de um range CIDR.
     *
     * @param string $ip
     * @param string $range
     * @return bool
     */
    private function ipInRange(string $ip, string $range): bool
    {
        if (!str_contains($range, '/')) {
            return $ip === $range;
        }

        list($subnet, $mask) = explode('/', $range);
        
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $this->ipv4InRange($ip, $subnet, (int) $mask);
        } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $this->ipv6InRange($ip, $subnet, (int) $mask);
        }

        return false;
    }

    /**
     * Verifica se um IPv4 está no range.
     *
     * @param string $ip
     * @param string $subnet
     * @param int $mask
     * @return bool
     */
    private function ipv4InRange(string $ip, string $subnet, int $mask): bool
    {
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        $maskLong = -1 << (32 - $mask);

        return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
    }

    /**
     * Verifica se um IPv6 está no range.
     *
     * @param string $ip
     * @param string $subnet
     * @param int $mask
     * @return bool
     */
    private function ipv6InRange(string $ip, string $subnet, int $mask): bool
    {
        $ipBin = inet_pton($ip);
        $subnetBin = inet_pton($subnet);
        
        if ($ipBin === false || $subnetBin === false) {
            return false;
        }

        $bytes = $mask >> 3;
        $bits = $mask & 7;

        return substr($ipBin, 0, $bytes) === substr($subnetBin, 0, $bytes) &&
               (($bits === 0) || (ord($ipBin[$bytes]) >> (8 - $bits)) === (ord($subnetBin[$bytes]) >> (8 - $bits)));
    }

    /**
     * Detecta se é dispositivo móvel.
     *
     * @param string $userAgent
     * @return bool
     */
    private function detectMobile(string $userAgent): bool
    {
        $mobilePatterns = [
            'Mobile', 'Android', 'iPhone', 'iPod', 'BlackBerry', 
            'IEMobile', 'Opera Mini', 'webOS', 'Windows Phone'
        ];

        foreach ($mobilePatterns as $pattern) {
            if (stripos($userAgent, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detecta se é tablet.
     *
     * @param string $userAgent
     * @return bool
     */
    private function detectTablet(string $userAgent): bool
    {
        $tabletPatterns = ['iPad', 'Android.*Tablet', 'Kindle', 'Silk/', 'PlayBook'];

        foreach ($tabletPatterns as $pattern) {
            if (preg_match("/{$pattern}/i", $userAgent)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detecta o sistema operacional.
     *
     * @param string $userAgent
     * @return string
     */
    private function detectOS(string $userAgent): string
    {
        $osPatterns = [
            'Windows NT 10.0' => 'Windows 10',
            'Windows NT 6.3' => 'Windows 8.1',
            'Windows NT 6.2' => 'Windows 8',
            'Windows NT 6.1' => 'Windows 7',
            'Windows NT' => 'Windows',
            'Mac OS X' => 'macOS',
            'iPhone OS' => 'iOS',
            'iPad.*OS' => 'iPadOS',
            'Android' => 'Android',
            'Linux' => 'Linux',
            'Ubuntu' => 'Ubuntu',
            'CrOS' => 'Chrome OS',
        ];

        foreach ($osPatterns as $pattern => $os) {
            if (preg_match("/{$pattern}/i", $userAgent)) {
                return $os;
            }
        }

        return 'Unknown';
    }

    /**
     * Detecta o navegador.
     *
     * @param string $userAgent
     * @return string
     */
    private function detectBrowser(string $userAgent): string
    {
        $browserPatterns = [
            'Edg/' => 'Edge',
            'Chrome/' => 'Chrome',
            'Firefox/' => 'Firefox',
            'Safari/' => 'Safari',
            'Opera/' => 'Opera',
            'OPR/' => 'Opera',
            'Trident/' => 'Internet Explorer',
        ];

        foreach ($browserPatterns as $pattern => $browser) {
            if (stripos($userAgent, $pattern) !== false) {
                return $browser;
            }
        }

        return 'Unknown';
    }

    /**
     * Cria um slug a partir de uma string.
     *
     * @param string $text
     * @return string
     */
    private function createSlug(string $text): string
    {
        $text = trim($text);
        $text = iconv('UTF-8', 'ASCII//TRANSLIT', $text);
        $text = preg_replace('/[^a-zA-Z0-9\-_]/', '-', $text);
        $text = preg_replace('/\-+/', '-', $text);
        return trim(strtolower($text), '-');
    }
}