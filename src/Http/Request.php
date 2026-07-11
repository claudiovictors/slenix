<?php

/*
|--------------------------------------------------------------------------
| Request Class
|--------------------------------------------------------------------------
|
| This class represents an HTTP request, encapsulating information such as
| route parameters, HTTP method, URI, input data (POST, GET), files,
| cookies, headers, client IP, and user agent.
|
*/

declare(strict_types=1);

namespace Slenix\Http;

use InvalidArgumentException;
use Slenix\Supports\Libraries\Collection;
use Slenix\Supports\Security\CSRF;
use Slenix\Supports\Security\RateLimit;
use Slenix\Supports\Uploads\Upload;

class Request
{
    // -------------------------------------------------------------------------
    // Core properties
    // -------------------------------------------------------------------------
    private array $params = [];
    private array $server = [];
    private array $headers = [];
    private array $attributes = [];
    private array $queryParams = [];

    // -------------------------------------------------------------------------
    // Cache / lazy loading
    // -------------------------------------------------------------------------
    private ?array $parsedBody = null;
    private ?array $uploadedFiles = null;
    private ?string $rawBody = null;
    private ?array $deviceInfo = null;
    private ?array $acceptableLanguages = null;
    private ?string $fingerprint = null;
    private mixed $authenticatedUser = null;
    private bool $userResolved = false;

    /** CSRF helper instance (lazy-loaded) */
    private ?CSRF $csrf = null;

    /** Cached bearer token extracted from Authorization header */
    private ?string $bearerToken = null;

    /** Whether bearer token has been parsed (allows null as valid result) */
    private bool $bearerTokenParsed = false;

    // -------------------------------------------------------------------------
    // Configuration
    // -------------------------------------------------------------------------
    private int $maxInputSize = 8_388_608; // 8 MB
    private array $trustedProxies = [];
    private array $trustedHeaders = [
        'X-Forwarded-For',
        'X-Forwarded-Proto',
        'X-Forwarded-Host',
        'X-Forwarded-Port',
    ];

    /** Authentication resolver (callable) */
    private static mixed $authResolver = null;

    /** Rate-limit store (memory / APCu / session) */
    private static array $rateLimitStore = [];

    /**
     * @param array $params Route parameters
     * @param array $server Server data (default $_SERVER)
     * @param array $query Query string (default $_GET)
     * @param array $cookies Cookies (default $_COOKIE)
     * @param array $files Uploaded files (default $_FILES)
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
        $this->validateRequest();
    }

    /**
     * Magic getter to access input fields as properties.
     *
     * @param string $key
     * @return mixed
     */
    public function __get(string $key): mixed
    {
        return $this->input($key);
    }

    /**
     * Magic isset to check input fields as properties.
     *
     * @param string $key
     * @return bool
     */
    public function __isset(string $key): bool
    {
        return $this->has($key);
    }

    /**
     * Returns a route parameter value.
     *
     * @param string $key Parameter key
     * @param mixed $default Default value
     * @return mixed
     */
    public function param(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
    }

    /**
     * Returns all route parameters.
     *
     * @return array<string, mixed>
     */
    public function params(): array
    {
        return $this->params;
    }

    /**
     * Sets a route parameter.
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
     * Sets multiple route parameters.
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
     * Returns the HTTP method of the request.
     *
     * @return string Uppercase HTTP method
     */
    public function method(): string
    {
        static $method = null;

        if ($method !== null) {
            return $method;
        }

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
     * Returns the request URI path.
     *
     * @return string
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
     * Returns the full request URI.
     *
     * @return string
     */
    public function fullUri(): string
    {
        return $this->server['REQUEST_URI'] ?? '/';
    }

    /**
     * Returns the full request URL.
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

            $portString = '';
            if (($scheme === 'http' && $port !== 80) || ($scheme === 'https' && $port !== 443)) {
                $portString = ':' . $port;
            }

            $url = "{$scheme}://{$host}{$portString}{$uri}";
        }

        return $url;
    }

    /**
     * Returns base URL without query string.
     *
     * @return string
     */
    public function baseUrl(): string
    {
        $url = $this->url();
        return strtok($url, '?') ?: $url;
    }

    /**
     * Returns query string.
     *
     * @return string|null
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
     * Returns an input value (GET, POST, JSON body).
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function input(string $key, mixed $default = null): mixed
    {
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

    // -------------------------------------------------------------------------
    // Stage — Input Manipulation
    // -------------------------------------------------------------------------

    /**
     * Merge data into the current input.
     *
     * @param array $data
     * @return self
     */
    public function merge(array $data): self
    {
        $this->parsedBody = array_merge($this->getParsedBody(), $data);
        $_POST = array_merge($_POST, $data);
        return $this;
    }

    /**
     * Replace all input data.
     *
     * @param array $data
     * @return self
     */
    public function replace(array $data): self
    {
        $this->parsedBody = $data;
        $_POST = $data;
        return $this;
    }

    // -------------------------------------------------------------------------
    // Stage — Content Type Helpers
    // -------------------------------------------------------------------------

    /**
     * Get the request Content-Type header.
     *
     * @return string|null
     */
    public function getContentType(): ?string
    {
        return $this->getHeader('Content-Type') ?? null;
    }

    /**
     * Check if the request is a form submission.
     *
     * @return bool
     */
    public function isFormRequest(): bool
    {
        $contentType = strtolower($this->getContentType() ?? '');
        return str_contains($contentType, 'application/x-www-form-urlencoded')
            || str_contains($contentType, 'multipart/form-data');
    }

    /**
     * Check if the request accepts a given MIME type.
     *
     * @param string $mime
     * @return bool
     */
    public function acceptsMimeType(string $mime): bool
    {
        $accept = strtolower($this->getHeader('Accept', ''));
        return str_contains($accept, strtolower($mime)) || str_contains($accept, '*/*');
    }

    // -------------------------------------------------------------------------
    // Stage — File Helpers
    // -------------------------------------------------------------------------

    /**
     * Check if all given file keys are present.
     *
     * @param array $keys
     * @return bool
     */
    public function hasAllFiles(array $keys): bool
    {
        foreach ($keys as $key) {
            if (!$this->hasFile($key)) {
                return false;
            }
        }
        return true;
    }

    // -------------------------------------------------------------------------
    // Stage — URI Segments
    // -------------------------------------------------------------------------

    /**
     * Return all URI segments.
     *
     * Example: /users/5/edit → ['users', '5', 'edit']
     *
     * @return array<int, string>
     */
    public function segments(): array
    {
        return array_values(array_filter(
            explode('/', $this->uri()),
            fn($s) => $s !== ''
        ));
    }

    /**
     * Return a specific URI segment (1-indexed).
     *
     * Example: /users/5/edit → segment(2) = '5'
     *
     * @param int $index 1-based index
     * @param mixed $default
     * @return mixed
     */
    public function segment(int $index, mixed $default = null): mixed
    {
        return $this->segments()[$index - 1] ?? $default;
    }

    // -------------------------------------------------------------------------
    // Stage — Additional Input Checks
    // -------------------------------------------------------------------------

    /**
     * Check if all given fields are present and filled.
     *
     * @param array $keys
     * @return bool
     */
    public function hasAll(array $keys): bool
    {
        foreach ($keys as $key) {
            if (!$this->has($key)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Return selected fields as a Collection.
     *
     * @param array|string $keys Pass empty array to collect all input.
     * @return Collection
     */
    public function collect(array|string $keys = []): Collection
    {
        $keys = is_string($keys) ? func_get_args() : $keys;
        $data = empty($keys) ? $this->all() : $this->only($keys);
        return new Collection($data);
    }

    /**
     * Returns all input data merged.
     *
     * @return array
     */
    public function all(): array
    {
        return array_merge($this->queryParams, $_POST, $this->getParsedBody());
    }

    /**
     * Returns only selected fields.
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
     * Returns all fields except selected ones.
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
     * Checks if a field exists.
     *
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool
    {
        return $this->input($key) !== null;
    }

    /**
     * Checks if any field exists.
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
     * Checks if field is filled.
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
     * Checks if field is missing or empty.
     *
     * @param string $key
     * @return bool
     */
    public function missing(string $key): bool
    {
        return !$this->filled($key);
    }

    /**
     * Returns value type integer.
     * 
     * @param string $key
     * @param int $default
     * @return int
     */
    public function integer(string $key, int $default = 0): int
    {
        return (int) $this->input($key, $default);
    }

    /**
     * Returns value type float.
     * @param string $key
     * @param float $default
     * @return float
     */
    public function float(string $key, float $default = 0.0): float
    {
        return (float) $this->input($key, $default);
    }

    /**
     * Returns value type bool.
     * @param string $key
     * @param bool $default
     * @return bool
     */
    public function boolean(string $key, bool $default = false): bool
    {
        $value = $this->input($key, $default);
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /**
     * Returns value type string.
     * @param string $key
     * @param string $default
     * @return string
     */
    public function string(string $key, string $default = ''): string
    {
        return (string) $this->input($key, $default);
    }

    /**
     * Returns value type array.
     * @param string $key
     * @param array $default
     * @return array
     */
    public function array(string $key, array $default = []): array
    {
        $value = $this->input($key, $default);
        return is_array($value) ? $value : $default;
    }

    /**
     * Returns value type POST.
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
     * Returns all values type POST.
     *
     * @return array
     */
    public function postData(): array
    {
        return $_POST;
    }

    /**
     * Returns value type GET.
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
     * Return value params query.
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
     * Return all value params query.
     *
     * @return array
     */
    public function queryParams(): array
    {
        return $this->queryParams;
    }

    /**
     * Check if a file was uploaded.
     *
     * @param string $key
     * @return bool
     */
    public function hasFile(string $key): bool
    {
        if (!isset($_FILES[$key]) || !is_array($_FILES[$key])) {
            return false;
        }

        $requiredKeys = ['name', 'tmp_name', 'size', 'error'];
        foreach ($requiredKeys as $requiredKey) {
            if (
                !array_key_exists($requiredKey, $_FILES[$key]) ||
                (is_array($_FILES[$key][$requiredKey]) && !isset($_FILES[$key]['name'][0]))
            ) {
                error_log("Upload error: missing required key '{$requiredKey}' for '{$key}'");
                return false;
            }
        }

        if (!is_array($_FILES[$key]['name'])) {
            return $_FILES[$key]['error'] !== UPLOAD_ERR_NO_FILE && !empty($_FILES[$key]['tmp_name']);
        }

        return is_array($_FILES[$key]['name']) && !empty($_FILES[$key]['name'][0]);
    }

    /**
     * Return a new Upload instance for the specified file.
     *
     * @param string $key The file key in the $_FILES array.
     * @return Upload
     */
    public function file(string $key): Upload
    {
        $fileData = $_FILES[$key] ?? [];
        if (is_array($fileData['name']) && isset($fileData['name'][0])) {
            // For multiple files, return the first one
            $normalizedFiles = $this->normalizeNestedFiles($fileData);
            $fileData = $normalizedFiles[0] ?? [];
        }
        return new Upload($fileData);
    }

    /**
     * Return all uploaded files as Upload objects.
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
     * Normalize nested files for multiple uploads.
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
     * Retrieve a cookie value.
     *
     * @param string $key The cookie key.
     * @param mixed $default Default value if the key does not exist.
     * @param bool $decrypt Whether to decrypt the value.
     * @return mixed
     */
    public function cookie(string $key, mixed $default = null, bool $decrypt = false): mixed
    {
        $value = $_COOKIE[$key] ?? $default;

        if ($decrypt && $value !== null && $value !== $default && function_exists('decrypt')) {
            $decrypted = decrypt((string) $value);
            return $decrypted !== false ? $decrypted : $value;
        }

        return $value;
    }

    /**
     * Retrieve all cookies.
     *
     * @return array
     */
    public function cookies(): array
    {
        return $_COOKIE;
    }

    /**
     * Check if a cookie key exists.
     * 
     * @param string $key
     * @return bool
     */
    public function hasCookie(string $key): bool
    {
        return isset($_COOKIE[$key]);
    }

    /**
     * Retrieve all cookies as a sanitized array.
     * 
     * @param bool $sanitize
     * @return array
     */
    public function allCookies(bool $sanitize = true): array
    {
        if (!$sanitize) {
            return $_COOKIE;
        }

        return array_map(
            fn($v) => is_string($v) ? htmlspecialchars($v, ENT_QUOTES, 'UTF-8') : $v,
            $_COOKIE
        );
    }

    /**
     * Get the client's IP address (considering trusted proxies).
     *
     * @return string|null The client's IP or null.
     */
    public function ip(): ?string
    {
        static $ip = null;

        if ($ip !== null) {
            return $ip;
        }

        // Check for trusted proxies and forwarding headers
        if (!empty($this->trustedProxies)) {
            $forwardedIp = $this->getForwardedIp();
            if ($forwardedIp) {
                $ip = $forwardedIp;
                return $ip;
            }
        }

        // Standard headers for IP detection
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
     * Retrieve the request user agent.
     *
     * @return string|null The user agent or null.
     */
    public function userAgent(): ?string
    {
        return $this->server['HTTP_USER_AGENT'] ?? null;
    }

    /**
     * Retrieve the request host.
     *
     * @return string
     */
    public function getHost(): string
    {
        // Check trusted proxy headers first
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
     * Retrieve the request port.
     *
     * @return int
     */
    public function getPort(): int
    {
        // Check trusted proxy headers first
        if (!empty($this->trustedProxies)) {
            $forwardedPort = $this->getHeader('X-Forwarded-Port');
            if ($forwardedPort && $this->isTrustedProxy($this->server['REMOTE_ADDR'] ?? '')) {
                return (int) $forwardedPort;
            }
        }

        return (int) ($this->server['SERVER_PORT'] ?? ($this->isSecure() ? 443 : 80));
    }

    /**
     * Retrieve the request scheme (http or https).
     *
     * @return string
     */
    public function getScheme(): string
    {
        return $this->isSecure() ? 'https' : 'http';
    }

    /**
     * Check if the connection is secure (HTTPS).
     *
     * @return bool
     */
    public function isSecure(): bool
    {
        static $isSecure = null;

        if ($isSecure !== null) {
            return $isSecure;
        }

        // Check trusted proxy headers first
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
     * Check if the request method matches the given value(s).
     *
     * @param string|array $methods The HTTP method(s) to compare.
     * @return bool
     */
    public function isMethod(string|array $methods): bool
    {
        $methods = is_string($methods) ? [$methods] : $methods;
        return in_array($this->method(), array_map('strtoupper', $methods));
    }

    /**
     * Check if the request is an AJAX request.
     *
     * @return bool
     */
    public function isAjax(): bool
    {
        return strtolower($this->getHeader('X-Requested-With', '')) === 'xmlhttprequest';
    }

    /**
     * Check if the request is JSON.
     *
     * @return bool
     */
    public function isJson(): bool
    {
        $contentType = $this->getHeader('Content-Type', '');
        return str_contains(strtolower($contentType), 'application/json');
    }

    /**
     * Check if the request expects a JSON response.
     *
     * @return bool
     */
    public function expectsJson(): bool
    {
        return $this->isAjax() || $this->wantsJson();
    }

    /**
     * Check if the request wants a JSON response.
     *
     * @return bool
     */
    public function wantsJson(): bool
    {
        $acceptable = $this->getHeader('Accept', '');
        return str_contains(strtolower($acceptable), 'application/json');
    }

    /**
     * Check if the request accepts HTML.
     *
     * @return bool
     */
    public function acceptsHtml(): bool
    {
        $acceptable = $this->getHeader('Accept', '');
        return str_contains(strtolower($acceptable), 'text/html');
    }

    /**
     * Retrieve a specific HTTP header.
     *
     * @param string $name Header name.
     * @param mixed $default Default value if header does not exist.
     * @return mixed
     */
    public function getHeader(string $name, mixed $default = null): mixed
    {
        $name = $this->normalizeHeaderName($name);
        return $this->headers[$name] ?? $default;
    }

    /**
     * Retrieve all headers.
     *
     * @return array
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Check if a header exists.
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
     * Retrieve a header value as a string.
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
     * Set an attribute on the request.
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
     * Retrieve an attribute from the request.
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
     * Remove an attribute.
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
     * Get the parsed request body.
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
     * Get the raw request content.
     *
     * @return string
     */
    public function getRawBody(): string
    {
        if ($this->rawBody === null) {
            $this->rawBody = file_get_contents('php://input');

            // Check body size
            if (strlen($this->rawBody) > $this->maxInputSize) {
                throw new InvalidArgumentException('Request body is too large');
            }
        }

        return $this->rawBody;
    }

    /**
     * Check if the request body is empty.
     *
     * @return bool
     */
    public function hasBody(): bool
    {
        return !empty($this->getRawBody());
    }

    /**
     * Get server data.
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
     * Validate if required fields are present and filled.
     *
     * @param array $required
     * @return array List of missing fields
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
     * Sanitize an input value.
     *
     * @param string $key
     * @param string $filter Filter type
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
     * Sanitize multiple fields at once.
     *
     * @param array $rules ['field' => 'filter']
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
     * Get the request referer.
     *
     * @param string|null $default
     * @return string|null
     */
    public function referer(?string $default = null): ?string
    {
        return $this->getHeader('Referer') ?? $default;
    }

    /**
     * Check if the request came from a specific origin.
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
     * Check if the request is from a bot/crawler.
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
            'googlebot',
            'bingbot',
            'slurp',
            'duckduckbot',
            'baiduspider',
            'yandexbot',
            'facebookexternalhit',
            'twitterbot',
            'linkedinbot',
            'whatsapp',
            'telegram',
            'crawler',
            'spider',
            'bot',
            'scraper'
        ];

        foreach ($botSignatures as $signature) {
            if (str_contains($userAgent, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get device information based on User-Agent.
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
     * Check if the request is from a mobile device.
     *
     * @return bool
     */
    public function isMobile(): bool
    {
        return $this->getDeviceInfo()['is_mobile'];
    }

    /**
     * Check if the request is from a tablet.
     *
     * @return bool
     */
    public function isTablet(): bool
    {
        return $this->getDeviceInfo()['is_tablet'];
    }

    /**
     * Check if the request is from a desktop.
     *
     * @return bool
     */
    public function isDesktop(): bool
    {
        return $this->getDeviceInfo()['is_desktop'];
    }

    /**
     * Get the acceptable languages from the client.
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
     * Get the client's preferred language.
     *
     * @param array $available Available languages
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

            // Check base language (e.g., "en" from "en-US")
            $baseLang = substr($language, 0, 2);
            if (in_array($baseLang, $available)) {
                return $baseLang;
            }
        }

        return null;
    }

    /**
     * Set trusted proxies.
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
     * Set trusted proxy headers.
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
     * Set the maximum request body size.
     *
     * @param int $size Size in bytes
     * @return self
     */
    public function setMaxInputSize(int $size): self
    {
        $this->maxInputSize = $size;
        return $this;
    }

    /**
     * Set a static callable that resolves the authenticated user for this request.
     *
     * The resolver receives the current Request instance and should return the
     * authenticated user entity or null when no user is authenticated.
     *
     * @example
     *   Request::setAuthResolver(fn(Request $req) => Auth::resolve()->user());
     *
     * @param  callable $resolver  Callable with signature: fn(Request): mixed
     * @return void
     */
    public static function setAuthResolver(callable $resolver): void
    {
        self::$authResolver = $resolver;
    }

    /**
     * Resolve and return the currently authenticated user.
     *
     * The result is cached on the instance so the resolver is invoked at most
     * once per request. Returns null when no auth resolver has been registered
     * or when the resolver itself returns null.
     *
     * @template TUser
     * @return TUser|null
     */
    public function user(): mixed
    {
        if (!$this->userResolved) {
            $this->userResolved = true;
            $this->authenticatedUser = self::$authResolver !== null
                ? (self::$authResolver)($this)
                : null;
        }

        return $this->authenticatedUser;
    }

    /**
     * Determine whether the current request is authenticated (a user is present).
     *
     * @return bool True when a user could be resolved, false otherwise.
     */
    public function check(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Determine whether the current request is unauthenticated (no user resolved).
     *
     * @return bool True when no user is present, false otherwise.
     */
    public function guest(): bool
    {
        return !$this->check();
    }

    /**
     * Return the authenticated user or throw when the request is unauthenticated.
     *
     * @throws \RuntimeException When no user is resolved by the auth resolver.
     * @return mixed The authenticated user entity.
     */
    public function userOrFail(): mixed
    {
        $user = $this->user();

        if ($user === null) {
            throw new \RuntimeException('Unauthenticated request.', 401);
        }

        return $user;
    }

    /**
     * Extract the bearer token from the Authorization header.
     *
     * Parses the standard {@see https://datatracker.ietf.org/doc/html/rfc6750 RFC 6750}
     * Authorization header of the form: {@code Authorization: Bearer <token>}.
     * The result is cached so the header is parsed only once per request.
     *
     * @return string|null The raw token string, or null when no bearer token is present.
     */
    public function bearerToken(): ?string
    {
        if ($this->bearerTokenParsed) {
            return $this->bearerToken;
        }

        $this->bearerTokenParsed = true;
        $authorization = $this->getHeader('Authorization', '');

        if (is_string($authorization) && str_starts_with($authorization, 'Bearer ')) {
            $this->bearerToken = substr($authorization, 7);
        }

        return $this->bearerToken;
    }

    /**
     * Retrieve an API key from a named request header or query parameter.
     *
     * Checks the header first; falls back to the query string when absent.
     * This covers both {@code X-Api-Key: <key>} and {@code ?api_key=<key>} conventions.
     *
     * @param  string $header     Header name to inspect (default: 'X-Api-Key').
     * @param  string $queryParam Query-string parameter name to fall back to (default: 'api_key').
     * @return string|null The API key string, or null when not found in either location.
     */
    public function apiKey(string $header = 'X-Api-Key', string $queryParam = 'api_key'): ?string
    {
        $fromHeader = $this->getHeader($header);

        if ($fromHeader !== null && $fromHeader !== '') {
            return $fromHeader;
        }

        $fromQuery = $this->query($queryParam);

        return ($fromQuery !== null && $fromQuery !== '') ? (string) $fromQuery : null;
    }

    /**
     * Determine whether the request carries any form of authentication token.
     *
     * Returns true when any of the following are present:
     * - A bearer token in the Authorization header.
     * - An API key in the configured header or query parameter.
     * - An authenticated user resolved by the auth resolver.
     *
     * @return bool
     */
    public function hasToken(): bool
    {
        return $this->bearerToken() !== null
            || $this->apiKey() !== null
            || $this->check();
    }

    /**
     * Attempt a rate-limited action for the current request.
     *
     * Delegates to {@see \Slenix\Supports\Security\RateLimit::attempt()} using the
     * resolved identity key for this request. The identity key is built automatically
     * from the authenticated user (when available) or the client IP address.
     *
     * @param  string $route         Short label scoping this limit to a route or action
     *                               (e.g. {@code 'api'}, {@code 'login'}, {@code 'otp'}).
     * @param  int    $maxAttempts   Maximum number of hits allowed within the window.
     * @param  int    $decaySeconds  Duration of the time window in seconds.
     * @return array{
     *     allowed: bool,
     *     attempts: int,
     *     max_attempts: int,
     *     remaining: int,
     *     reset_at: int,
     *     retry_after: int
     * }
     */
    public function rateLimit(string $route, int $maxAttempts, int $decaySeconds): array
    {
        $key = RateLimit::buildKey(
            route: $route,
            ip: $this->ip(),
        );

        return RateLimit::attempt($key, $maxAttempts, $decaySeconds);
    }

    /**
     * Determine whether the current request has exceeded the rate limit.
     *
     * This is a read-only check — it does not increment the counter.
     * Use {@see rateLimit()} to both check and increment in one call.
     *
     * @param  string $route        Action label matching the one used in {@see rateLimit()}.
     * @param  int    $maxAttempts  The configured limit to compare against.
     * @return bool True when the limit has been exceeded, false otherwise.
     */
    public function isRateLimited(string $route, int $maxAttempts): bool
    {
        $key = RateLimit::buildKey(route: $route, ip: $this->ip());

        return RateLimit::tooManyAttempts($key, $maxAttempts);
    }

    /**
     * Return the number of remaining allowed attempts for the given route.
     *
     * Non-destructive — does not modify the stored counter.
     *
     * @param  string $route        Action label matching the one used in {@see rateLimit()}.
     * @param  int    $maxAttempts  The configured ceiling to calculate remaining hits.
     * @return int Remaining attempts available (0 when the limit has been reached).
     */
    public function remainingAttempts(string $route, int $maxAttempts): int
    {
        $key = RateLimit::buildKey(route: $route, ip: $this->ip());

        return RateLimit::remaining($key, $maxAttempts);
    }

    /**
     * Clear all recorded rate-limit attempts for the given route.
     *
     * Useful after a successful login or any action that should lift
     * a throttle penalty for the current request's identity.
     *
     * @param  string $route  Action label matching the one used in {@see rateLimit()}.
     * @return void
     */
    public function clearRateLimit(string $route): void
    {
        $key = RateLimit::buildKey(route: $route, ip: $this->ip());
        RateLimit::clear($key);
    }

    /**
     * Return the lazy-loaded CSRF helper instance.
     *
     * The instance is created once and reused for the lifetime of the request.
     *
     * @return \Slenix\Supports\Security\CSRF
     */
    private function csrf(): CSRF
    {
        if ($this->csrf === null) {
            $this->csrf = new CSRF();
        }

        return $this->csrf;
    }

    /**
     * Retrieve the active CSRF token for the current session.
     *
     * Generates and stores a new token when none exists yet.
     * Delegates to {@see \Slenix\Supports\Security\CSRF::token()}.
     *
     * @return string The hex-encoded CSRF token.
     */
    public function csrfToken(): string
    {
        return CSRF::token();
    }

    /**
     * Verify the CSRF token carried by the current request.
     *
     * Checks the token against the value stored in the session using a
     * constant-time comparison to prevent timing attacks.
     * Returns false when the request method is safe (GET, HEAD, OPTIONS),
     * when the route is excluded, or when no token is found.
     *
     * @return bool True when the token is valid, false otherwise.
     */
    public function verifyCsrf(): bool
    {
        if (CSRF::isSafeMethod() || CSRF::isExcluded()) {
            return true;
        }

        return CSRF::verify();
    }

    /**
     * Verify the CSRF token or throw a RuntimeException on failure.
     *
     * Responds with HTTP 419 (Page Expired) and halts execution when the
     * token is absent or does not match the session value.
     *
     * @throws \RuntimeException HTTP 419 when the CSRF token is invalid or missing.
     * @return void
     */
    public function verifyCsrfOrFail(): void
    {
        if (CSRF::isSafeMethod() || CSRF::isExcluded()) {
            return;
        }

        CSRF::verifyOrFail();
    }

    /**
     * Determine whether the current route is excluded from CSRF verification.
     *
     * @return bool True when the request path matches a registered exclusion pattern.
     */
    public function isCsrfExcluded(): bool
    {
        return CSRF::isExcluded();
    }

    /**
     * Return the requested page number from the query string.
     *
     * Clamps the result to a minimum of 1, so callers never receive
     * a zero or negative page number regardless of the input.
     *
     * @param  string $key      Query-string parameter name (default: {@code 'page'}).
     * @param  int    $default  Fallback page when the parameter is absent (default: 1).
     * @return int The resolved page number (always ≥ 1).
     */
    public function page(string $key = 'page', int $default = 1): int
    {
        return max(1, $this->integer($key, $default));
    }

    /**
     * Return the requested number of items per page from the query string.
     *
     * Enforces a configurable upper bound so clients cannot request an
     * arbitrarily large page that would overload the database or serializer.
     *
     * @param  string $key      Query-string parameter name (default: {@code 'per_page'}).
     * @param  int    $default  Fallback per-page count when the parameter is absent (default: 15).
     * @param  int    $max      Maximum allowed value; clamped silently (default: 100).
     * @return int The resolved per-page count (always in the range [1, $max]).
     */
    public function perPage(string $key = 'per_page', int $default = 15, int $max = 100): int
    {
        return min($max, max(1, $this->integer($key, $default)));
    }

    /**
     * Execute a callback when the given field is present and filled.
     *
     * The callback receives the field value as its first argument and the
     * current Request instance as its second. Returns the callback's return
     * value when the field is filled, or {@code $default} otherwise.
     *
     * @template TReturn
     * @param  string   $key       Input field name to inspect.
     * @param  callable $callback  Callable with signature: {@code fn(mixed $value, Request $req): TReturn}
     * @param  mixed    $default   Value to return when the field is absent or empty (default: null).
     * @return mixed
     */
    public function whenFilled(string $key, callable $callback, mixed $default = null): mixed
    {
        if ($this->filled($key)) {
            return $callback($this->input($key), $this);
        }

        return $default;
    }

    /**
     * Execute a callback when the given field key is present in the input.
     *
     * Unlike {@see whenFilled()}, this fires even when the field value is an
     * empty string or null — presence in the payload is the only condition.
     *
     * @template TReturn
     * @param  string   $key       Input field name to inspect.
     * @param  callable $callback  Callable with signature: {@code fn(mixed $value, Request $req): TReturn}
     * @param  mixed    $default   Value to return when the field is absent (default: null).
     * @return mixed
     */
    public function whenHas(string $key, callable $callback, mixed $default = null): mixed
    {
        if ($this->has($key)) {
            return $callback($this->input($key), $this);
        }

        return $default;
    }

    /**
     * Determine whether the request originates from the same host as the application.
     *
     * Compares the {@code Origin} header against the current request host.
     * Falls back to comparing the {@code Referer} header when {@code Origin} is absent.
     * Returns false when neither header is present.
     *
     * @return bool True when the origin matches the application host.
     */
    public function isFromSameOrigin(): bool
    {
        $origin = $this->getHeader('Origin');

        if ($origin) {
            $host = parse_url((string) $origin, PHP_URL_HOST);
            return $host === $this->getHost();
        }

        $referer = $this->referer();

        if ($referer) {
            $host = parse_url($referer, PHP_URL_HOST);
            return $host === $this->getHost();
        }

        return false;
    }

    /**
     * Create a new Request instance from current global data.
     *
     * @param array $params
     * @return self
     */
    public static function createFromGlobals(array $params = []): self
    {
        return new self($params);
    }

    /**
     * Create a new Request instance for testing.
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

        // Define custom headers
        foreach ($headers as $name => $value) {
            $headerKey = 'HTTP_' . str_replace('-', '_', strtoupper($name));
            $serverData[$headerKey] = $value;
        }

        $queryParams = [];
        $postData = [];

        // Define data based on method
        if (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            $postData = $data;
            $_POST = $data; // For compatibility
        } else {
            $queryParams = $data;
        }

        $request = new self([], $serverData, $queryParams);

        // If JSON data is present, simulate it
        if (isset($headers['Content-Type']) && str_contains($headers['Content-Type'], 'application/json')) {
            $request->parsedBody = $data;
        }

        return $request;
    }

    /**
     * Convert the request to an array (useful for debugging).
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
     * Debug the request (returns formatted information).
     *
     * @return string
     */
    public function debug(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Convert the request to string (for logs).
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
     * Generate a unique request fingerprint based on IP, User-Agent, and other signals.
     *
     * @param bool $includeSession Include session ID in the fingerprint
     * @return string SHA-256 hash
     */
    public function fingerprint(bool $includeSession = false): string
    {
        if ($this->fingerprint !== null) {
            return $this->fingerprint;
        }

        $components = [
            $this->ip() ?? '',
            $this->userAgent() ?? '',
            $this->getHeader('Accept-Language', ''),
            $this->getHeader('Accept-Encoding', ''),
            $this->getScheme(),
        ];

        if ($includeSession && session_status() === PHP_SESSION_ACTIVE) {
            $components[] = session_id();
        }

        $this->fingerprint = hash('sha256', implode('|', $components));

        return $this->fingerprint;
    }

    /**
     * Generate partial fingerprint (lightweight, IP + UA only).
     * 
     * @return string
     */
    public function lightFingerprint(): string
    {
        return hash('sha256', ($this->ip() ?? '') . '|' . ($this->userAgent() ?? ''));
    }

    /**
     * Parse HTTP headers.
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

        // Add special headers that don't start with HTTP_
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
     * Parse the request body based on Content-Type.
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
                // For multipart, data is already in $_POST
                $this->parsedBody = $_POST;
            } else {
                // For other types, store the raw content
                $this->parsedBody = ['_raw' => $input];
            }
        } catch (\JsonException $e) {
            throw new InvalidArgumentException('Invalid JSON: ' . $e->getMessage());
        } catch (\Exception $e) {
            throw new InvalidArgumentException('Error processing request body: ' . $e->getMessage());
        }
    }

    /**
     * Parse XML body.
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
            $errorMessage = 'Invalid XML';
            if (!empty($errors)) {
                $errorMessage .= ': ' . $errors[0]->message;
            }
            libxml_clear_errors();
            throw new InvalidArgumentException($errorMessage);
        }
    }

    /**
     * Normalize header name.
     *
     * @param string $name
     * @return string
     */
    private function normalizeHeaderName(string $name): string
    {
        return str_replace('_', '-', strtoupper($name));
    }

    /**
     * Validate basic request.
     *
     * @return void
     * @throws InvalidArgumentException
     */
    private function validateRequest(): void
    {
        // Validate HTTP method
        $method = $this->method();
        $allowedMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

        if (!in_array($method, $allowedMethods)) {
            throw new InvalidArgumentException("Invalid HTTP method: {$method}");
        }

        // Validate Content-Length if present
        $contentLength = $this->getHeader('Content-Length');
        if ($contentLength !== null && !is_numeric($contentLength)) {
            throw new InvalidArgumentException('Invalid Content-Length');
        }

        // Validate maximum body size if configured
        if ($contentLength && (int) $contentLength > $this->maxInputSize) {
            throw new InvalidArgumentException('Request body is too large');
        }
    }

    /**
     * Check if an IP is valid.
     *
     * @param string $ip
     * @return bool
     */
    private function isValidIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /**
     * Get IP from proxy headers.
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
     * Check if the IP is from a trusted proxy.
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
     * Check if an IP is within a CIDR range.
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
     * Check if an IPv4 is within range.
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
     * Check if an IPv6 is within range.
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
     * Detect if the device is mobile.
     *
     * @param string $userAgent
     * @return bool
     */
    private function detectMobile(string $userAgent): bool
    {
        $mobilePatterns = [
            'Mobile',
            'Android',
            'iPhone',
            'iPod',
            'BlackBerry',
            'IEMobile',
            'Opera Mini',
            'webOS',
            'Windows Phone'
        ];

        foreach ($mobilePatterns as $pattern) {
            if (stripos($userAgent, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect if the device is a tablet.
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
     * Detect the operating system.
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
     * Detect the browser.
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
     * Create a slug from a string.
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