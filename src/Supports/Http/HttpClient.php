<?php

/*
|--------------------------------------------------------------------------
| HttpClient Class
|--------------------------------------------------------------------------
|
| Provides a fluent and robust interface for making HTTP requests,
| with support for HTTP methods, authentication, custom headers, request
| body, retries, timeouts, and integration with the Slenix framework.
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
     * @var array Default request options
     */
    protected array $options = [
        'timeout'          => 30,
        'connect_timeout'  => 5,
        'verify'           => true,
        'http_errors'      => true,
        'retries'          => 0,
        'retry_delay'      => 1000,
        'follow_redirects' => true,
        'max_redirects'    => 5,
        'user_agent'       => 'Slenix-HttpClient/1.0',
    ];

    /** @var array Request headers */
    protected array $headers = [];

    /** @var mixed Request body data */
    protected mixed $body = null;

    /** @var string|null Base URL for requests */
    protected ?string $baseUrl = null;

    /** @var string|null HTTP method */
    protected ?string $method = null;

    /** @var string|null Request URL */
    protected ?string $url = null;

    /** @var array|null Authentication data */
    protected ?array $auth = null;

    /** @var array Event callbacks (before, after, error) */
    protected array $events = [];

    /** @var array Status codes that should be treated as errors */
    protected array $errorStatusCodes = [400, 401, 403, 404, 500, 502, 503];

    /**
     * Creates a new HttpClient instance.
     *
     * @param array $options Initial options
     */
    public function __construct(array $options = [])
    {
        $this->options = array_merge($this->options, $options);
        $this->validateOptions();
    }

    /**
     * Validates the provided options.
     *
     * @throws InvalidArgumentException
     */
    protected function validateOptions(): void
    {
        if ($this->options['timeout'] <= 0) {
            throw new InvalidArgumentException('Timeout must be greater than 0');
        }

        if ($this->options['connect_timeout'] <= 0) {
            throw new InvalidArgumentException('Connect timeout must be greater than 0');
        }

        if ($this->options['retries'] < 0) {
            throw new InvalidArgumentException('Retries cannot be negative');
        }
    }

    /**
     * Sets the base URL for requests.
     *
     * @param  string $baseUrl
     * @return self
     * @throws InvalidArgumentException
     */
    public function baseUrl(string $baseUrl): self
    {
        if (!filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('Invalid base URL');
        }

        $this->baseUrl = rtrim($baseUrl, '/');
        return $this;
    }

    /**
     * Sets a single request header.
     *
     * @param  string $name
     * @param  string $value
     * @return self
     */
    public function withHeader(string $name, string $value): self
    {
        $this->headers[trim($name)] = trim($value);
        return $this;
    }

    /**
     * Sets multiple request headers at once.
     *
     * @param  array $headers
     * @return self
     */
    public function withHeaders(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->withHeader((string) $name, (string) $value);
        }
        return $this;
    }

    /**
     * Removes a request header.
     *
     * @param  string $name
     * @return self
     */
    public function withoutHeader(string $name): self
    {
        unset($this->headers[trim($name)]);
        return $this;
    }

    /**
     * Sets authentication for the request (Basic Auth, Bearer Token, Digest).
     *
     * @param  string       $type        Authentication type ('basic', 'bearer', 'digest')
     * @param  string|array $credentials Credentials (username/password array or token string)
     * @return self
     * @throws InvalidArgumentException
     */
    public function withAuth(string $type, string|array $credentials): self
    {
        $type = strtolower($type);

        if (!in_array($type, ['basic', 'bearer', 'digest'], true)) {
            throw new InvalidArgumentException("Unsupported authentication type: '{$type}'");
        }

        if ($type === 'basic' && (!is_array($credentials) || count($credentials) !== 2)) {
            throw new InvalidArgumentException('Basic credentials must be an array [username, password]');
        }

        if (in_array($type, ['bearer', 'digest']) && !is_string($credentials)) {
            throw new InvalidArgumentException("'{$type}' credentials must be a string token");
        }

        $this->auth = ['type' => $type, 'credentials' => $credentials];
        return $this;
    }

    /**
     * Sets the request body as JSON.
     *
     * @param  array|object $data
     * @return self
     */
    public function asJson(array|object $data): self
    {
        $this->body = $data;
        $this->withHeader('Content-Type', 'application/json');
        return $this;
    }

    /**
     * Sets the request body as multipart form data.
     *
     * @param  array $data
     * @return self
     */
    public function asForm(array $data): self
    {
        $this->body = $data;
        $this->withHeader('Content-Type', 'multipart/form-data');
        return $this;
    }

    /**
     * Sets the request body as URL-encoded form data.
     *
     * @param  array $data
     * @return self
     */
    public function asFormUrlEncoded(array $data): self
    {
        $this->body = $data;
        $this->withHeader('Content-Type', 'application/x-www-form-urlencoded');
        return $this;
    }

    /**
     * Sets the request body as XML.
     *
     * @param  string $xml
     * @return self
     */
    public function asXml(string $xml): self
    {
        $this->body = $xml;
        $this->withHeader('Content-Type', 'application/xml');
        return $this;
    }

    /**
     * Sets the request body as plain text.
     *
     * @param  string $text
     * @return self
     */
    public function asText(string $text): self
    {
        $this->body = $text;
        $this->withHeader('Content-Type', 'text/plain');
        return $this;
    }

    /**
     * Sets the number of retry attempts on failure.
     *
     * @param  int  $retries Number of retries
     * @param  int  $delay   Delay between retries in milliseconds
     * @return self
     * @throws InvalidArgumentException
     */
    public function withRetries(int $retries, int $delay = 1000): self
    {
        if ($retries < 0) {
            throw new InvalidArgumentException('Number of retries cannot be negative');
        }

        if ($delay < 0) {
            throw new InvalidArgumentException('Retry delay cannot be negative');
        }

        $this->options['retries']     = $retries;
        $this->options['retry_delay'] = $delay;
        return $this;
    }

    /**
     * Sets the request timeout in seconds.
     *
     * @param  int  $timeout
     * @return self
     * @throws InvalidArgumentException
     */
    public function timeout(int $timeout): self
    {
        if ($timeout <= 0) {
            throw new InvalidArgumentException('Timeout must be greater than 0');
        }

        $this->options['timeout'] = $timeout;
        return $this;
    }

    /**
     * Sets the User-Agent header.
     *
     * @param  string $userAgent
     * @return self
     */
    public function withUserAgent(string $userAgent): self
    {
        $this->options['user_agent'] = $userAgent;
        return $this;
    }

    /**
     * Registers a callback for a lifecycle event (before, after, error).
     *
     * - 'before' receives: (string $method, string $url, mixed $body)
     * - 'after'  receives: (Response $response)
     * - 'error'  receives: (Exception $e)
     *
     * @param  string   $event    Event name: 'before', 'after', or 'error'
     * @param  callable $callback
     * @return self
     * @throws InvalidArgumentException
     */
    public function on(string $event, callable $callback): self
    {
        if (!in_array($event, ['before', 'after', 'error'], true)) {
            throw new InvalidArgumentException("Unsupported event '{$event}'. Allowed: before, after, error");
        }

        $this->events[$event][] = $callback;
        return $this;
    }

    /**
     * Executes a GET request.
     *
     * @param  string $url
     * @param  array  $query Query string parameters
     * @return Response
     */
    public function get(string $url, array $query = []): Response
    {
        return $this->request('GET', $url, ['query' => $query]);
    }

    /**
     * Executes a POST request.
     *
     * @param  string $url
     * @param  mixed  $data
     * @return Response
     */
    public function post(string $url, mixed $data = []): Response
    {
        return $this->request('POST', $url, ['body' => $data]);
    }

    /**
     * Executes a PUT request.
     *
     * @param  string $url
     * @param  mixed  $data
     * @return Response
     */
    public function put(string $url, mixed $data = []): Response
    {
        return $this->request('PUT', $url, ['body' => $data]);
    }

    /**
     * Executes a PATCH request.
     *
     * @param  string $url
     * @param  mixed  $data
     * @return Response
     */
    public function patch(string $url, mixed $data = []): Response
    {
        return $this->request('PATCH', $url, ['body' => $data]);
    }

    /**
     * Executes a DELETE request.
     *
     * @param  string $url
     * @return Response
     */
    public function delete(string $url): Response
    {
        return $this->request('DELETE', $url);
    }

    /**
     * Executes a HEAD request.
     *
     * @param  string $url
     * @return Response
     */
    public function head(string $url): Response
    {
        return $this->request('HEAD', $url);
    }

    /**
     * Executes an OPTIONS request.
     *
     * @param  string $url
     * @return Response
     */
    public function options(string $url): Response
    {
        return $this->request('OPTIONS', $url);
    }

    /**
     * Generic method to execute any HTTP request.
     *
     * @param  string $method  HTTP method (GET, POST, PUT, etc.)
     * @param  string $url
     * @param  array  $options Additional options: 'query' and/or 'body'
     * @return Response
     */
    public function request(string $method, string $url, array $options = []): Response
    {
        $this->method = strtoupper($method);
        $this->url    = $this->buildUrl($url, $options['query'] ?? []);

        if (isset($options['body'])) {
            $this->body = $options['body'];
        }

        return $this->send();
    }

    /**
     * Builds the full URL by combining baseUrl, path, and query parameters.
     *
     * @param  string $url
     * @param  array  $query
     * @return string
     */
    protected function buildUrl(string $url, array $query = []): string
    {
        $base = $this->baseUrl ? rtrim($this->baseUrl, '/') . '/' : '';
        $url  = $base . ltrim($url, '/');

        if (!empty($query)) {
            $separator = parse_url($url, PHP_URL_QUERY) ? '&' : '?';
            $url      .= $separator . http_build_query($query);
        }

        return $url;
    }

    /**
     * Sends the HTTP request with retry logic.
     *
     * @return Response
     * @throws Exception
     */
    protected function send(): Response
    {
        $attempts      = 0;
        $maxAttempts   = $this->options['retries'] + 1;
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

                // Wait before the next attempt
                usleep($this->options['retry_delay'] * 1000);
            }
        }

        throw $lastException ?? new RuntimeException('Failed to execute request after all retry attempts.');
    }

    /**
     * Performs the actual cURL request.
     *
     * @return Response
     * @throws RuntimeException
     */
    protected function executeCurlRequest(): Response
    {
        $ch = curl_init();
        curl_setopt_array($ch, $this->buildCurlOptions());

        $responseContent = curl_exec($ch);
        $httpCode        = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType     = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?? '';
        $error           = curl_error($ch);
        $errno           = curl_errno($ch);

        curl_close($ch);

        if ($responseContent === false) {
            throw new RuntimeException("cURL error ({$errno}): {$error}");
        }

        $response = new Response();
        $response->status($httpCode);

        // Auto-decode JSON responses
        if (stripos($contentType, 'application/json') !== false) {
            $decoded = json_decode((string) $responseContent, true);
            $response->setContent(json_last_error() === JSON_ERROR_NONE ? $decoded : $responseContent);
        } else {
            $response->setContent($responseContent);
        }

        return $response;
    }

    /**
     * Builds the cURL options array for the current request.
     *
     * @return array
     */
    protected function buildCurlOptions(): array
    {
        $options = [
            CURLOPT_URL            => $this->url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_TIMEOUT        => $this->options['timeout'],
            CURLOPT_CONNECTTIMEOUT => $this->options['connect_timeout'],
            CURLOPT_SSL_VERIFYPEER => $this->options['verify'],
            CURLOPT_FOLLOWLOCATION => $this->options['follow_redirects'],
            CURLOPT_MAXREDIRS      => $this->options['max_redirects'],
            CURLOPT_USERAGENT      => $this->options['user_agent'],
            CURLOPT_ENCODING       => '', // Accept all supported encodings
        ];

        // Set HTTP method
        switch ($this->method) {
            case 'POST':
                $options[CURLOPT_POST] = true;
                break;
            case 'GET':
            case 'HEAD':
                // No special cURL option needed
                break;
            default:
                $options[CURLOPT_CUSTOMREQUEST] = $this->method;
                break;
        }

        // Attach the request body for non-GET/HEAD methods
        if ($this->body !== null && !in_array($this->method, ['GET', 'HEAD'], true)) {
            $options[CURLOPT_POSTFIELDS] = $this->prepareRequestBody();
        }

        // Apply authentication settings
        $this->applyCurlAuth($options);

        // Attach request headers
        if (!empty($this->headers)) {
            $options[CURLOPT_HTTPHEADER] = array_map(
                fn($name, $value) => "{$name}: {$value}",
                array_keys($this->headers),
                array_values($this->headers)
            );
        }

        return $options;
    }

    /**
     * Prepares the request body based on the Content-Type header.
     *
     * @return mixed String for most types, raw array for multipart/form-data (handled by cURL)
     */
    protected function prepareRequestBody(): mixed
    {
        $contentType = $this->headers['Content-Type'] ?? '';

        if (stripos($contentType, 'application/json') !== false) {
            return json_encode($this->body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        if (stripos($contentType, 'application/xml') !== false) {
            return (string) $this->body;
        }

        if (stripos($contentType, 'multipart/form-data') !== false) {
            // Pass the raw array and let cURL handle multipart encoding
            return $this->body;
        }

        if (is_array($this->body)) {
            return http_build_query($this->body);
        }

        return (string) $this->body;
    }

    /**
     * Applies authentication settings to the cURL options array.
     *
     * @param array &$options cURL options passed by reference
     */
    protected function applyCurlAuth(array &$options): void
    {
        if (!$this->auth) {
            return;
        }

        switch ($this->auth['type']) {
            case 'basic':
                [$username, $password]     = $this->auth['credentials'];
                $options[CURLOPT_USERPWD] = "{$username}:{$password}";
                break;

            case 'bearer':
                $this->headers['Authorization'] = 'Bearer ' . $this->auth['credentials'];
                break;

            case 'digest':
                [$username, $password]     = $this->auth['credentials'];
                $options[CURLOPT_USERPWD]  = "{$username}:{$password}";
                $options[CURLOPT_HTTPAUTH] = CURLAUTH_DIGEST;
                break;
        }
    }

    /**
     * Dispatches all callbacks registered for the given event.
     *
     * Exceptions thrown inside callbacks are caught and logged so they
     * do not interrupt the main request flow.
     *
     * @param string $event
     * @param array  $params Parameters forwarded to each callback
     */
    protected function dispatchEvent(string $event, array $params): void
    {
        foreach ($this->events[$event] ?? [] as $callback) {
            try {
                call_user_func_array($callback, $params);
            } catch (Exception $e) {
                // Log the error without interrupting execution
                error_log("Error in '{$event}' event callback: " . $e->getMessage());
            }
        }
    }

    /**
     * Resets the client state for a new request, keeping base options intact.
     *
     * @return self
     */
    public function reset(): self
    {
        $this->method  = null;
        $this->url     = null;
        $this->body    = null;
        $this->headers = [];
        $this->auth    = null;

        return $this;
    }

    /**
     * Creates a new HttpClient instance (static factory).
     *
     * @param  array $options
     * @return self
     */
    public static function make(array $options = []): self
    {
        return new self($options);
    }

    /**
     * Static shortcut for a quick GET request.
     *
     * @param  string $url
     * @param  array  $options Client options
     * @return Response
     */
    public static function quickGet(string $url, array $options = []): Response
    {
        return self::make($options)->get($url);
    }

    /**
     * Static shortcut for a quick POST request.
     *
     * @param  string $url
     * @param  mixed  $data
     * @param  array  $options Client options
     * @return Response
     */
    public static function quickPost(string $url, mixed $data = [], array $options = []): Response
    {
        return self::make($options)->post($url, $data);
    }
}