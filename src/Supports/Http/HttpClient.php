<?php

/*
|--------------------------------------------------------------------------
| HttpClient Class
|--------------------------------------------------------------------------
|
| Fluent HTTP client with Http::to(url)->method() syntax.
| Supports: GET, POST, PUT, PATCH, DELETE, HEAD, OPTIONS,
| authentication, retries, timeouts, events, body types,
| and static shortcuts — all in a clean, readable interface.
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Http;

use Exception;
use RuntimeException;
use InvalidArgumentException;
use BadMethodCallException;
use Slenix\Http\Response;

class HttpClient
{
    /**
     * Configuration options for the HTTP request.
     *
     * @var array
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
     * Custom HTTP headers for the request.
     *
     * @var array
     */
    protected array $headers = [];

    /**
     * The payload or payload raw content to be sent.
     *
     * @var mixed
     */
    protected mixed $body = null;

    /**
     * Pre-configured target domain/base URL path.
     *
     * @var string|null
     */
    protected ?string $baseUrl = null;

    /**
     * Current explicit uppercase HTTP Verb.
     *
     * @var string|null
     */
    protected ?string $method = null;

    /**
     * Computed full target endpoint path.
     *
     * @var string|null
     */
    protected ?string $url = null;

    /**
     * Authentication array structure details ['type' => ..., 'credentials' => ...].
     *
     * @var array|null
     */
    protected ?array $auth = null;

    /**
     * Internal array containing events callbacks loops.
     *
     * @var array
     */
    protected array $events = [];

    /**
     * Matching HTTP response integer codes treated as a failing connection.
     *
     * @var array
     */
    protected array $errorStatusCodes = [400, 401, 403, 404, 500, 502, 503];

    /**
     * Initialize the client instance with dynamic settings overrides.
     *
     * @param array $options
     */
    public function __construct(array $options = [])
    {
        $this->options = array_merge($this->options, $options);
        $this->validateOptions();
    }

    /**
     * Entry point — sets the target URL and returns a configured instance.
     *
     * @param string $url
     * @param array $options
     * @return static
     */
    public static function to(string $url, array $options = []): static
    {
        $instance = new static($options);
        $instance->baseUrl = rtrim($url, '/');
        return $instance;
    }

    /**
     * Static factory when you need to configure before setting the URL.
     *
     * @param array $options
     * @return static
     */
    public static function make(array $options = []): static
    {
        return new static($options);
    }

    /**
     * Changes (or sets) the base URL after construction.
     *
     * @param string $url
     * @return static
     * @throws InvalidArgumentException
     */
    public function baseUrl(string $url): static
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException("Invalid base URL: '{$url}'");
        }

        $this->baseUrl = rtrim($url, '/');
        return $this;
    }

    /**
     * Append or override a singular HTTP header.
     *
     * @param string $name
     * @param string $value
     * @return static
     */
    public function withHeader(string $name, string $value): static
    {
        $this->headers[trim($name)] = trim($value);
        return $this;
    }

    /**
     * Key-value associative layout payload appending routine loop.
     *
     * @param array $headers
     * @return static
     */
    public function withHeaders(array $headers): static
    {
        foreach ($headers as $name => $value) {
            $this->withHeader((string) $name, (string) $value);
        }
        return $this;
    }

    /**
     * Remove explicit header constraint entry tracker reference.
     *
     * @param string $name
     * @return static
     */
    public function withoutHeader(string $name): static
    {
        unset($this->headers[trim($name)]);
        return $this;
    }

    /**
     * Shorthand: sets Accept: application/json.
     *
     * @return static
     */
    public function acceptJson(): static
    {
        return $this->withHeader('Accept', 'application/json');
    }

    /**
     * Generic auth — supports 'basic', 'bearer', 'digest'.
     *
     * @param string $type
     * @param string|array $credentials
     * @return static
     * @throws InvalidArgumentException
     */
    public function withAuth(string $type, string|array $credentials): static
    {
        $type = strtolower($type);

        if (!in_array($type, ['basic', 'bearer', 'digest'], true)) {
            throw new InvalidArgumentException("Unsupported auth type: '{$type}'");
        }

        if ($type === 'basic' && (!is_array($credentials) || count($credentials) !== 2)) {
            throw new InvalidArgumentException('Basic auth requires [username, password]');
        }

        if (in_array($type, ['bearer', 'digest']) && !is_string($credentials)) {
            throw new InvalidArgumentException("'{$type}' auth requires a string token");
        }

        $this->auth = ['type' => $type, 'credentials' => $credentials];
        return $this;
    }

    /**
     * Shorthand for Bearer token auth header structure setup.
     *
     * @param string $token
     * @return static
     */
    public function withToken(string $token): static
    {
        return $this->withAuth('bearer', $token);
    }

    /**
     * Shorthand routing setup parsing basic layout constraints.
     *
     * @param string $username
     * @param string $password
     * @return static
     */
    public function withBasicAuth(string $username, string $password): static
    {
        return $this->withAuth('basic', [$username, $password]);
    }

    /**
     * Sends body payload format parsed explicit as application/json pattern.
     *
     * @param array|object $data
     * @return static
     */
    public function asJson(array|object $data): static
    {
        $this->body = $data;
        return $this->withHeader('Content-Type', 'application/json');
    }

    /**
     * Set explicit request layout form behavior to multipart/form-data rules.
     *
     * @param array $data
     * @return static
     */
    public function asForm(array $data): static
    {
        $this->body = $data;
        return $this->withHeader('Content-Type', 'multipart/form-data');
    }

    /**
     * Payload content standard to application/x-www-form-urlencoded matching.
     *
     * @param array $data
     * @return static
     */
    public function asFormUrlEncoded(array $data): static
    {
        $this->body = $data;
        return $this->withHeader('Content-Type', 'application/x-www-form-urlencoded');
    }

    /**
     * Force text layout target interpretation output data type as XML structure.
     *
     * @param string $xml
     * @return static
     */
    public function asXml(string $xml): static
    {
        $this->body = $xml;
        return $this->withHeader('Content-Type', 'application/xml');
    }

    /**
     * Simple raw textual format body injection.
     *
     * @param string $text
     * @return static
     */
    public function asText(string $text): static
    {
        $this->body = $text;
        return $this->withHeader('Content-Type', 'text/plain');
    }

    /**
     * Sets retry loops rules constraint properties metrics.
     *
     * @param int $retries
     * @param int $delayMs
     * @return static
     * @throws InvalidArgumentException
     */
    public function retry(int $retries, int $delayMs = 1000): static
    {
        if ($retries < 0 || $delayMs < 0) {
            throw new InvalidArgumentException('Retries and delays cannot be negative');
        }

        $this->options['retries'] = $retries;
        $this->options['retry_delay'] = $delayMs;
        return $this;
    }

    /**
     * Request connection active max processing cycle limit time frame.
     *
     * @param int $seconds
     * @return static
     * @throws InvalidArgumentException
     */
    public function timeout(int $seconds): static
    {
        if ($seconds <= 0) {
            throw new InvalidArgumentException('Timeout must be greater than 0');
        }

        $this->options['timeout'] = $seconds;
        return $this;
    }

    /**
     * Max processing connect layout wait timing threshold configuration rules.
     *
     * @param int $seconds
     * @return static
     * @throws InvalidArgumentException
     */
    public function connectTimeout(int $seconds): static
    {
        if ($seconds <= 0) {
            throw new InvalidArgumentException('Connect timeout must be greater than 0');
        }

        $this->options['connect_timeout'] = $seconds;
        return $this;
    }

    /**
     * Toggle SSL local certificate verification logic matching constraints.
     *
     * @return static
     */
    public function withoutVerifying(): static
    {
        $this->options['verify'] = false;
        return $this;
    }

    /**
     * Prevent tracking loops to drop exceptions capturing standard 4xx or 5xx hooks.
     *
     * @return static
     */
    public function withoutHttpErrors(): static
    {
        $this->options['http_errors'] = false;
        return $this;
    }

    /**
     * Custom browser platform identity User-Agent representation context.
     *
     * @param string $userAgent
     * @return static
     */
    public function withUserAgent(string $userAgent): static
    {
        $this->options['user_agent'] = $userAgent;
        return $this;
    }

    /**
     * Add event callback attachment listener tracking lifecycle states.
     *
     * @param string $event
     * @param callable $callback
     * @return static
     * @throws InvalidArgumentException
     */
    public function on(string $event, callable $callback): static
    {
        if (!in_array($event, ['before', 'after', 'error'], true)) {
            throw new InvalidArgumentException("Unsupported event '{$event}'. Use: before, after, error");
        }

        $this->events[$event][] = $callback;
        return $this;
    }

    /**
     * Dispatch an implicit dynamic safe GET request call method routing.
     *
     * @param string $path
     * @param array $query
     * @return Response
     */
    public function get(string $path = '', array $query = []): Response
    {
        return $this->send('GET', $path, query: $query);
    }

    /**
     * Dispatch an implicit dynamic safe POST request call method routing.
     *
     * @param string $path
     * @param mixed $data
     * @return Response
     */
    public function post(string $path = '', mixed $data = null): Response
    {
        return $this->send('POST', $path, body: $data);
    }

    /**
     * Dispatch an implicit dynamic safe PUT request call method routing.
     *
     * @param string $path
     * @param mixed $data
     * @return Response
     */
    public function put(string $path = '', mixed $data = null): Response
    {
        return $this->send('PUT', $path, body: $data);
    }

    /**
     * Dispatch an implicit dynamic safe PATCH request call method routing.
     *
     * @param string $path
     * @param mixed $data
     * @return Response
     */
    public function patch(string $path = '', mixed $data = null): Response
    {
        return $this->send('PATCH', $path, body: $data);
    }

    /**
     * Dispatch an implicit dynamic safe DELETE request call method routing.
     *
     * @param string $path
     * @param mixed $data
     * @return Response
     */
    public function delete(string $path = '', mixed $data = null): Response
    {
        return $this->send('DELETE', $path, body: $data);
    }

    /**
     * Dispatch an implicit dynamic safe HEAD request call method routing.
     *
     * @param string $path
     * @return Response
     */
    public function head(string $path = ''): Response
    {
        return $this->send('HEAD', $path);
    }

    /**
     * Dispatch an implicit dynamic safe OPTIONS request call method routing.
     *
     * @param string $path
     * @return Response
     */
    public function options(string $path = ''): Response
    {
        return $this->send('OPTIONS', $path);
    }

    /**
     * Prepares configuration parameters state contexts data, managing execution dispatch.
     *
     * @param string $method
     * @param string $path
     * @param mixed $body
     * @param array $query
     * @return Response
     */
    protected function send(
        string $method,
        string $path = '',
        mixed $body = null,
        array $query = []
    ): Response {
        $this->method = strtoupper($method);
        $this->url = $this->buildUrl($path, $query);

        if ($body !== null) {
            $this->body = $body;
        }

        return $this->executeWithRetries();
    }

    /**
     * Processes relative vs complete absolute raw web path endpoint formatting string maps.
     *
     * @param string $path
     * @param array $query
     * @return string
     */
    protected function buildUrl(string $path = '', array $query = []): string
    {
        if (filter_var($path, FILTER_VALIDATE_URL)) {
            $url = $path;
        } else {
            $url = $this->baseUrl
                ? rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/')
                : $path;
        }

        if (!empty($query)) {
            $sep = parse_url($url, PHP_URL_QUERY) ? '&' : '?';
            $url .= $sep . http_build_query($query);
        }

        return $url;
    }

    /**
     * Master loops runner wrapping tracking blocks over active attempt execution.
     *
     * @return Response
     * @throws Exception
     * @throws RuntimeException
     */
    protected function executeWithRetries(): Response
    {
        $attempts = 0;
        $maxAttempts = $this->options['retries'] + 1;
        $lastException = null;

        while ($attempts < $maxAttempts) {
            try {
                $attempts++;
                $this->dispatchEvent('before', [$this->method, $this->url, $this->body]);

                $response = $this->executeCurlRequest();

                if (
                    $this->options['http_errors']
                    && in_array($response->getStatusCode(), $this->errorStatusCodes, true)
                ) {
                    throw new RuntimeException("HTTP Error: {$response->getStatusCode()}");
                }

                $this->dispatchEvent('after', [$response]);
                return $response;

            } catch (Exception $e) {
                $lastException = $e;

                if ($attempts >= $maxAttempts) {
                    $this->dispatchEvent('error', [$e]);
                    throw $e;
                }

                usleep($this->options['retry_delay'] * 1000);
            }
        }

        throw $lastException ?? new RuntimeException('Request failed after all retry attempts.');
    }

    /**
     * Direct execution bridge connecting PHP environment extension cURL resources hooks.
     *
     * @return Response
     * @throws RuntimeException
     */
    protected function executeCurlRequest(): Response
    {
        $ch = curl_init();
        curl_setopt_array($ch, $this->buildCurlOptions());

        $rawResponse = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?? '';
        $error = curl_error($ch);
        $errno = curl_errno($ch);

        curl_close($ch);

        if ($rawResponse === false) {
            throw new RuntimeException("cURL error ({$errno}): {$error}");
        }

        $response = new Response();
        $response->status($httpCode);

        if (stripos($contentType, 'application/json') !== false) {
            $decoded = json_decode((string) $rawResponse, true);
            $response->setContent(json_last_error() === JSON_ERROR_NONE ? $decoded : $rawResponse);
        } else {
            $response->setContent($rawResponse);
        }

        return $response;
    }

    /**
     * Map structured options dictionary into regular continuous native curl array maps flags.
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
            CURLOPT_ENCODING => '',
        ];

        match ($this->method) {
            'POST' => $options[CURLOPT_POST] = true,
            'GET',
            'HEAD' => null,
            default => $options[CURLOPT_CUSTOMREQUEST] = $this->method,
        };

        if ($this->body !== null && !in_array($this->method, ['GET', 'HEAD'], true)) {
            $options[CURLOPT_POSTFIELDS] = $this->prepareBody();
        }

        $this->applyCurlAuth($options);

        if (!empty($this->headers)) {
            $options[CURLOPT_HTTPHEADER] = array_map(
                fn($k, $v) => "{$k}: {$v}",
                array_keys($this->headers),
                array_values($this->headers)
            );
        }

        return $options;
    }

    /**
     * Serialize payload format elements according to requested Content-Type contexts.
     *
     * @return mixed
     */
    protected function prepareBody(): mixed
    {
        $contentType = $this->headers['Content-Type'] ?? '';

        if (stripos($contentType, 'application/json') !== false) {
            return json_encode($this->body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        if (stripos($contentType, 'application/xml') !== false) {
            return (string) $this->body;
        }

        if (stripos($contentType, 'multipart/form-data') !== false) {
            return $this->body;
        }

        if (is_array($this->body)) {
            return http_build_query($this->body);
        }

        return (string) $this->body;
    }

    /**
     * Append requested authorization structures parameters tags inside curl options rules maps.
     *
     * @param array $options
     * @return void
     */
    protected function applyCurlAuth(array &$options): void
    {
        if (!$this->auth) {
            return;
        }

        switch ($this->auth['type']) {
            case 'basic':
                [$user, $pass] = $this->auth['credentials'];
                $options[CURLOPT_USERPWD] = "{$user}:{$pass}";
                break;

            case 'bearer':
                $this->headers['Authorization'] = 'Bearer ' . $this->auth['credentials'];
                break;

            case 'digest':
                [$user, $pass] = $this->auth['credentials'];
                $options[CURLOPT_USERPWD] = "{$user}:{$pass}";
                $options[CURLOPT_HTTPAUTH] = CURLAUTH_DIGEST;
                break;
        }
    }

    /**
     * Safe runner loop triggering attached listener callbacks scopes monitoring state errors.
     *
     * @param string $event
     * @param array $params
     * @return void
     */
    protected function dispatchEvent(string $event, array $params): void
    {
        foreach ($this->events[$event] ?? [] as $callback) {
            try {
                call_user_func_array($callback, $params);
            } catch (Exception $e) {
                error_log("Error in '{$event}' event callback: " . $e->getMessage());
            }
        }
    }

    /**
     * Validate active internal parameters fields boundary validation constraints mapping rules.
     *
     * @return void
     * @throws InvalidArgumentException
     */
    protected function validateOptions(): void
    {
        if ($this->options['timeout'] <= 0 || $this->options['connect_timeout'] <= 0) {
            throw new InvalidArgumentException('Timeouts must be greater than 0');
        }

        if ($this->options['retries'] < 0) {
            throw new InvalidArgumentException('Retries cannot be negative');
        }
    }

    /**
     * Resets mutable state parameters configuration maps fields context.
     *
     * @return static
     */
    public function reset(): static
    {
        $this->method = null;
        $this->url = null;
        $this->body = null;
        $this->headers = [];
        $this->auth = null;
        return $this;
    }

    /**
     * Handles dynamic static magic method redirections proxy workflow routing rules.
     *
     * @param string $method
     * @param array $arguments
     * @return mixed
     * @throws BadMethodCallException
     */
    public static function __callStatic(string $method, array $arguments)
    {
        $instance = new static();

        if (method_exists($instance, $method)) {
            return call_user_func_array([$instance, $method], $arguments);
        }

        if (in_array(strtoupper($method), ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'], true)) {
            $url = array_shift($arguments) ?? '';
            return $instance->send($method, $url, ...$arguments);
        }

        throw new BadMethodCallException("Static method {$method} does not exist on HttpClient.");
    }
}