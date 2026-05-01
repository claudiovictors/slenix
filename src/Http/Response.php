<?php

/*
 |--------------------------------------------------------------------------
 | Response Class
 |--------------------------------------------------------------------------
 |
 | Manages HTTP responses with integrated security:
 | automatic protection headers, secure cookies, CORS,
 | cache-control, and standardized formats (JSON, HTML, XML, download).
 |
 */

declare(strict_types=1);

namespace Slenix\Http;

use InvalidArgumentException;
use RuntimeException;

class Response
{
    /** @var int Current HTTP status code */
    private int $statusCode = 200;

    /** @var mixed Response body content */
    private mixed $content = '';

    /** @var array<string, string> Custom HTTP headers */
    private array $headers = [];

    /** @var string Character encoding (default UTF-8) */
    private string $charset = 'UTF-8';

    /** @var string|null Explicit Content-Type header */
    private ?string $contentType = null;

    /** @var bool Flag to prevent double-sending response */
    private bool $sent = false;

    /** @var array Queued cookies to be sent */
    private array $cookies = [];

    /** @var array<int, string> */
    private static array $statusTexts = [
        100 => 'Continue',
        101 => 'Switching Protocols',
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        204 => 'No Content',
        206 => 'Partial Content',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        307 => 'Temporary Redirect',
        308 => 'Permanent Redirect',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        408 => 'Request Timeout',
        409 => 'Conflict',
        413 => 'Payload Too Large',
        415 => 'Unsupported Media Type',
        422 => 'Unprocessable Entity',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
    ];

    /**
     * Sets the HTTP status code.
     * @param int $code
     * @throws InvalidArgumentException
     * @return Response
     */
    public function status(int $code = 200): self
    {
        if ($code < 100 || $code > 599) {
            throw new InvalidArgumentException("Invalid status code: {$code}. Must be between 100 and 599.");
        }

        $this->statusCode = $code;

        if (!$this->sent && !headers_sent()) {
            http_response_code($code);
        }

        return $this;
    }

    /**
     * Sends a JSON response.
     * @param mixed $data
     * @param int $statusCode
     * @param int $flags
     * @throws RuntimeException
     * @return void
     */
    public function json(
        mixed $data,
        int $statusCode = 200,
        int $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ): void {
        $json = json_encode($data, $flags);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Failed to encode JSON: ' . json_last_error_msg());
        }

        $this->status($statusCode)
            ->withContentType('application/json')
            ->send($json);
    }

    /**
     * Sends an HTML response.
     * @param string $html
     * @param int $statusCode
     * @return void
     */
    public function html(string $html, int $statusCode = 200): void
    {
        $this->status($statusCode)
            ->withContentType('text/html')
            ->send($html);
    }

    /**
     * Sends a plain text response.
     * @param string $text
     * @param int $statusCode
     * @return void
     */
    public function write(string $text, int $statusCode = 200): void
    {
        $this->status($statusCode)
            ->withContentType('text/plain')
            ->send($text);
    }

    /**
     * Sends an XML response.
     * @param mixed $xml
     * @param int $statusCode
     * @return void
     */
    public function xml(mixed $xml, int $statusCode = 200): void
    {
        $content = $xml instanceof \SimpleXMLElement ? $xml->asXML() : $xml;

        $this->status($statusCode)
            ->withContentType('application/xml')
            ->send($content);
    }

    /**
     * Standardized success response.
     * @param mixed $data
     * @param string $message
     * @param int $statusCode
     * @return void
     */
    public function success(mixed $data = null, string $message = 'OK', int $statusCode = 200): void
    {
        $payload = ['success' => true, 'message' => $message];

        if ($data !== null) {
            $payload['data'] = $data;
        }

        $this->json($payload, $statusCode);
    }

    /**
     * Standardized error response.
     * @param string $message
     * @param int $statusCode
     * @param array $details
     * @return void
     */
    public function error(string $message, int $statusCode = 500, array $details = []): void
    {
        $payload = [
            'success' => false,
            'error' => true,
            'message' => $message,
            'status_code' => $statusCode,
            'status_text' => self::$statusTexts[$statusCode] ?? 'Unknown',
        ];

        if (!empty($details)) {
            $payload['details'] = $details;
        }

        $this->json($payload, $statusCode);
    }

    /** Sends a 400 Bad Request response. */
    public function badRequest(string $message = 'Bad Request.'): void
    {
        $this->error($message, 400);
    }

    /** Sends a 202 Accepted response (for async tasks). */
    public function accepted(mixed $data = null, string $message = 'Accepted.'): void
    {
        $this->success($data, $message, 202);
    }

    /** Sends a 415 Unsupported Media Type response. */
    public function unsupportedMediaType(string $message = 'Unsupported Media Type.'): void
    {
        $this->error($message, 415);
    }

    /**
     * Clears any previous output buffers to ensure a clean response.
     * @return Response
     */
    public function cleanBuffer(): self
    {
        if (ob_get_level() > 0) {
            ob_clean();
        }
        return $this;
    }

    /**
     * 201 Created response with Location header.
     * @param mixed $data
     * @param mixed $location
     * @param string $message
     * @return void
     */
    public function created(mixed $data = null, ?string $location = null, string $message = 'Created successfully.'): void
    {
        if ($location) {
            $this->withHeader('Location', $location);
        }
        $this->success($data, $message, 201);
    }

    /**
     * 204 No Content response.
     * @return void
     */
    public function noContent(): void
    {
        $this->status(204)->send('');
    }

    /**
     * 401 Unauthorized response.
     * @param string $message
     * @return void
     */
    public function unauthorized(string $message = 'Unauthorized.'): void
    {
        $this->error($message, 401);
    }

    /**
     * 403 Forbidden response.
     * @param string $message
     * @return void
     */
    public function forbidden(string $message = 'Forbidden.'): void
    {
        $this->error($message, 403);
    }

    /**
     * 404 Not Found response.
     * @param string $message
     * @return void
     */
    public function notFound(string $message = 'Not Found.'): void
    {
        $this->error($message, 404);
    }

    /**
     * 422 Unprocessable Entity response with validation errors.
     * @param array $errors
     * @param string $message
     * @return void
     */
    public function validationError(array $errors, string $message = 'Invalid data.'): void
    {
        $this->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ], 422);
    }

    /**
     * 429 Too Many Requests response.
     * @param int $retryAfter
     * @param string $message
     * @return void
     */
    public function tooManyRequests(int $retryAfter = 60, string $message = 'Too many requests.'): void
    {
        $this->withHeader('Retry-After', (string) $retryAfter);
        $this->error($message, 429, ['retry_after' => $retryAfter]);
    }

    /**
     * Sends JSON response with pagination metadata.
     * @param mixed $items
     * @param int $total
     * @param int $perPage
     * @param int $currentPage
     * @param array $meta
     * @return void
     */
    public function paginate(
        mixed $items,
        int $total,
        int $perPage = 15,
        int $currentPage = 1,
        array $meta = []
    ): void {
        $items = is_array($items) ? $items : iterator_to_array($items);
        $lastPage = (int) ceil($total / max(1, $perPage));
        $from = $total > 0 ? (($currentPage - 1) * $perPage) + 1 : null;
        $to = $total > 0 ? min($currentPage * $perPage, $total) : null;

        $pagination = [
            'success' => true,
            'data' => $items,
            'meta' => array_merge([
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $currentPage,
                'last_page' => $lastPage,
                'from' => $from,
                'to' => $to,
                'has_more' => $currentPage < $lastPage,
            ], $meta),
            'links' => [
                'first' => $this->paginationUrl(1),
                'last' => $this->paginationUrl($lastPage),
                'prev' => $currentPage > 1 ? $this->paginationUrl($currentPage - 1) : null,
                'next' => $currentPage < $lastPage ? $this->paginationUrl($currentPage + 1) : null,
            ],
        ];

        $this->json($pagination, 200);
    }

    /**
     * Pagination from a complete array (in-memory pagination).
     * @param array $allItems
     * @param int $perPage
     * @param mixed $currentPage
     * @return void
     */
    public function paginateArray(array $allItems, int $perPage = 15, ?int $currentPage = null): void
    {
        $currentPage = $currentPage ?? max(1, (int) ($_GET['page'] ?? 1));
        $total = count($allItems);
        $offset = ($currentPage - 1) * $perPage;
        $items = array_slice($allItems, $offset, $perPage);

        $this->paginate($items, $total, $perPage, $currentPage);
    }

    /**
     * Applies automatic security headers.
     * @param array $options
     * @return Response
     */
    public function withSecurityHeaders(array $options = []): self
    {
        $defaults = [
            'csp' => true,
            'hsts' => true,
            'hsts_max_age' => 31_536_000,
            'hsts_subdomains' => true,
            'hsts_preload' => false,
            'xfo' => 'SAMEORIGIN',    // X-Frame-Options
            'xcto' => true,            // X-Content-Type-Options
            'referrer' => 'strict-origin-when-cross-origin',
            'permissions' => true,
            'xss_protection' => true,
        ];

        $opts = array_merge($defaults, $options);

        if ($opts['csp']) {
            $csp = $opts['csp_value'] ?? "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self';";
            $this->withHeader('Content-Security-Policy', $csp);
        }

        if ($opts['hsts']) {
            $hsts = "max-age={$opts['hsts_max_age']}";
            if ($opts['hsts_subdomains'])
                $hsts .= '; includeSubDomains';
            if ($opts['hsts_preload'])
                $hsts .= '; preload';
            $this->withHeader('Strict-Transport-Security', $hsts);
        }

        if ($opts['xfo']) {
            $this->withHeader('X-Frame-Options', $opts['xfo']);
        }

        if ($opts['xcto']) {
            $this->withHeader('X-Content-Type-Options', 'nosniff');
        }

        if ($opts['referrer']) {
            $this->withHeader('Referrer-Policy', $opts['referrer']);
        }

        if ($opts['permissions']) {
            $pp = $opts['permissions_value']
                ?? 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), fullscreen=(self)';
            $this->withHeader('Permissions-Policy', $pp);
        }

        if ($opts['xss_protection']) {
            $this->withHeader('X-XSS-Protection', '1; mode=block');
        }

        $this->withoutHeader('X-Powered-By');
        $this->withoutHeader('Server');

        return $this;
    }

    /**
     * Lightweight basic security headers for APIs.
     * @return Response
     */
    public function withBasicSecurityHeaders(): self
    {
        return $this->withHeaders([
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
        ])->withoutHeader('X-Powered-By');
    }

    /**
     * Configures custom CSP directives.
     * @param array $directives
     * @return Response
     */
    public function withCsp(array $directives): self
    {
        $csp = implode('; ', array_map(
            fn($dir, $val) => trim("{$dir} {$val}"),
            array_keys($directives),
            $directives
        ));

        return $this->withHeader('Content-Security-Policy', $csp);
    }

    /**
     * Sends file for download using chunks for memory efficiency.
     * @param string $filePath
     * @param mixed $fileName
     * @param mixed $contentType
     * @param bool $inline
     * @return never
     */
    public function download(
        string $filePath,
        ?string $fileName = null,
        ?string $contentType = null,
        bool $inline = false
    ): void {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            $this->error('File not found or inaccessible.', 404);
        }

        $fileName = $fileName ?? basename($filePath);
        $contentType = $contentType ?? (mime_content_type($filePath) ?: 'application/octet-stream');
        $disposition = $inline ? 'inline' : 'attachment';

        $this->withHeaders([
            'Content-Type' => $contentType,
            'Content-Disposition' => "{$disposition}; filename=\"" . addslashes($fileName) . '"',
            'Content-Length' => (string) filesize($filePath),
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);

        $this->sendHeaders();

        $handle = fopen($filePath, 'rb');
        if ($handle) {
            while (!feof($handle)) {
                echo fread($handle, 8192);
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();
            }
            fclose($handle);
        }

        $this->sent = true;
        exit;
    }

    /**
     * Renders a PHP template.
     * @param string $template
     * @param array $data
     * @param int $statusCode
     * @throws RuntimeException
     * @return void
     */
    public function render(string $template, array $data = [], int $statusCode = 200): void
    {
        $this->status($statusCode);

        if (function_exists('view')) {
            view($template, $data);
            return;
        }

        $path = $this->resolveTemplate($template);
        if (!$path) {
            throw new RuntimeException("Template '{$template}' not found.");
        }

        extract($data, EXTR_SKIP);
        ob_start();
        include $path;
        $content = ob_get_clean();

        $this->html($content, $statusCode);
    }

    /**
     * Redirects to another URL.
     * @param string $url
     * @param int $statusCode
     * @throws InvalidArgumentException
     * @return void
     */
    public function redirect(string $url, int $statusCode = 302): void
    {
        $allowed = [301, 302, 303, 307, 308];
        if (!in_array($statusCode, $allowed, true)) {
            throw new InvalidArgumentException(
                "Invalid redirect code: {$statusCode}. Use one of: " . implode(', ', $allowed)
            );
        }

        $url = str_replace(["\r", "\n", "\0"], '', $url);

        $this->status($statusCode)
            ->withHeader('Location', $url)
            ->send();
    }

    /**
     * Redirects back using HTTP_REFERER.
     * @param string $fallback
     * @param int $statusCode
     * @return void
     */
    public function redirectBack(string $fallback = '/', int $statusCode = 302): void
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? $fallback;
        $this->redirect($referer, $statusCode);
    }

    /**
     * Sets a header.
     * @param string $name
     * @param string $value
     * @return Response
     */
    public function withHeader(string $name, string $value): self
    {
        $name = preg_replace('/[^\w\-]/', '', $name) ?? '';
        $value = str_replace(["\r", "\n", "\0"], '', $value);
        $this->headers[$name] = $value;

        return $this;
    }

    /**
     * Sets multiple headers.
     * @param array $headers
     * @return Response
     */
    public function withHeaders(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->withHeader((string) $name, (string) $value);
        }

        return $this;
    }

    /**
     * Removes a header.
     * @param string $name
     * @return Response
     */
    public function withoutHeader(string $name): self
    {
        unset($this->headers[$name]);

        if (!headers_sent()) {
            header_remove($name);
        }

        return $this;
    }

    /**
     * Sets Content-Type.
     * @param string $contentType
     * @param mixed $charset
     * @return Response
     */
    public function withContentType(string $contentType, ?string $charset = null): self
    {
        $this->contentType = $contentType;

        if ($charset) {
            $this->charset = strtoupper($charset);
        }

        return $this;
    }

    /**
     * Sets a cookie.
     * @param string $name
     * @param string $value
     * @param int $expire
     * @param array $options
     * @return Response
     */
    public function withCookie(
        string $name,
        string $value,
        int $expire = 0,
        array $options = []
    ): self {
        $defaults = [
            'path' => '/',
            'domain' => '',
            'secure' => false,
            'httponly' => false,
            'samesite' => 'Lax',
        ];

        $opts = array_merge($defaults, $options);

        $this->cookies[$name] = compact('value', 'expire', 'opts');

        if (!$this->sent && !headers_sent()) {
            setcookie($name, $value, [
                'expires' => $expire,
                'path' => $opts['path'],
                'domain' => $opts['domain'],
                'secure' => $opts['secure'],
                'httponly' => $opts['httponly'],
                'samesite' => $opts['samesite'],
            ]);
        }

        return $this;
    }

    /**
     * Sets a cookie with maximum security settings.
     * @param string $name
     * @param string $value
     * @param int $expire
     * @param array $options
     * @return Response
     */
    public function withSecureCookie(
        string $name,
        string $value,
        int $expire = 0,
        array $options = []
    ): self {
        return $this->withCookie($name, $value, $expire, array_merge($options, [
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]));
    }

    /**
     * Removes a cookie.
     * @param string $name
     * @param array $options
     * @return Response
     */
    public function withoutCookie(string $name, array $options = []): self
    {
        unset($this->cookies[$name]);

        $opts = array_merge(['path' => '/', 'domain' => ''], $options);

        if (!$this->sent && !headers_sent()) {
            setcookie($name, '', [
                'expires' => time() - 3600,
                'path' => $opts['path'],
                'domain' => $opts['domain'],
            ]);
        }

        return $this;
    }

    /**
     * Sets cache headers.
     * @param int $maxAge
     * @param bool $public
     * @return Response
     */
    public function withCache(int $maxAge = 3600, bool $public = true): self
    {
        $directive = $public ? 'public' : 'private';

        return $this->withHeaders([
            'Cache-Control' => "{$directive}, max-age={$maxAge}",
            'Expires' => gmdate('D, d M Y H:i:s', time() + $maxAge) . ' GMT',
            'Last-Modified' => gmdate('D, d M Y H:i:s') . ' GMT',
        ]);
    }

    /**
     * Disables cache completely.
     * @return Response
     */
    public function withoutCache(): self
    {
        return $this->withHeaders([
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    /**
     * Configures CORS headers.
     * @param array $options
     * @return Response
     */
    public function withCors(array $options = []): self
    {
        $defaults = [
            'origin' => '*',
            'methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
            'headers' => 'Content-Type, Authorization, X-Requested-With, X-CSRF-Token',
            'credentials' => false,
            'max_age' => 86400,
        ];

        $opts = array_merge($defaults, $options);

        $headers = [
            'Access-Control-Allow-Origin' => $opts['origin'],
            'Access-Control-Allow-Methods' => $opts['methods'],
            'Access-Control-Allow-Headers' => $opts['headers'],
            'Access-Control-Max-Age' => (string) $opts['max_age'],
        ];

        if ($opts['credentials']) {
            $headers['Access-Control-Allow-Credentials'] = 'true';
        }

        return $this->withHeaders($headers);
    }

    /**
     * Sends only headers.
     * @return Response
     */
    public function sendHeaders(): self
    {
        if ($this->sent || headers_sent()) {
            return $this;
        }

        if ($this->contentType !== null) {
            header("Content-Type: {$this->contentType}; charset={$this->charset}", true);
        }

        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}", true);
        }

        return $this;
    }

    /**
     * Sends the complete response (headers + body).
     * @param mixed $content
     * @param mixed $statusCode
     * @return void
     */
    public function send(mixed $content = null, ?int $statusCode = null): void
    {
        if ($this->sent) {
            return;
        }

        if ($statusCode !== null) {
            $this->status($statusCode);
        }

        if ($content !== null) {
            $this->content = $content;
        }

        $this->sendHeaders();

        if (is_array($this->content) || is_object($this->content)) {
            echo json_encode($this->content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            echo (string) $this->content;
        }

        $this->sent = true;

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        exit;
    }


    /**
     * Sends a file stream response.
     * @param callable $callback
     * @param string $contentType
     * @param int $statusCode
     * @return void
     */
    public function stream(callable $callback, string $contentType = 'text/event-stream', int $statusCode = 200): void
    {
        $this->status($statusCode)
            ->withContentType($contentType)
            ->withHeader('X-Accel-Buffering', 'no') // Disable buffering for Nginx
            ->sendHeaders();

        $callback();
        $this->sent = true;
    }

    /**
     * Sends a JSONP response.
     * @param string $callback
     * @param mixed $data
     * @param int $statusCode
     * @return void
     */
    public function jsonp(string $callback, mixed $data, int $statusCode = 200): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->status($statusCode)
            ->withContentType('application/javascript')
            ->send("/**/ {$callback}({$json});");
    }

    /**
     * Refreshes the page after X seconds.
     * @param int $seconds
     * @param mixed $url
     * @return Response
     */
    public function refresh(int $seconds, ?string $url = null): self
    {
        $value = (string) $seconds;
        if ($url)
            $value .= ";url={$url}";
        return $this->withHeader('Refresh', $value);
    }

    /**
     * Sets the ETag header for browser caching.
     * @param string $etag
     * @param bool $weak
     * @return Response
     */
    public function withEtag(string $etag, bool $weak = false): self
    {
        $etag = '"' . str_replace('"', '', $etag) . '"';
        return $this->withHeader('ETag', $weak ? "W/{$etag}" : $etag);
    }

    /**
     * Returns the current status text.
     * @return string
     */
    public function getStatusText(): string
    {
        return self::$statusTexts[$this->statusCode] ?? 'Unknown';
    }

    /**
     * Sets the response content.
     * @param mixed $content
     * @return Response
     */
    public function setContent(mixed $content): self
    {
        $this->content = $content;
        return $this;
    }

    /**
     * Factory method to create a Response instance.
     * @param mixed $content
     * @param int $statusCode
     * @return Response
     */
    public static function make(mixed $content = '', int $statusCode = 200): self
    {
        return (new self())->setContent($content)->status($statusCode);
    }

    /**
     * Sends response as CSV download.
     * @param array $rows
     * @param array $headers
     * @param string $filename
     * @return never
     */
    public function csv(array $rows, array $headers = [], string $filename = 'export.csv'): void
    {
        $this->withHeaders([
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control' => 'no-store',
            'Pragma' => 'no-cache',
        ]);

        $this->sendHeaders();
        $output = fopen('php://output', 'w');
        fwrite($output, "\xEF\xBB\xBF");

        if (!empty($headers))
            fputcsv($output, $headers);
        foreach ($rows as $row)
            fputcsv($output, (array) $row);

        fclose($output);
        $this->sent = true;
        exit;
    }

    /**
     * Automatically formats the response based on the request's "Accept" header.
     */
    public function auto(mixed $data): void
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';

        if (str_contains($accept, 'application/json')) {
            $this->json($data);
            return;
        }

        if (str_contains($accept, 'application/xml')) {
            $this->xml($data);
            return;
        }

        // Default to HTML/String if it's a simple type, or JSON for safety
        is_scalar($data) ? $this->html((string) $data) : $this->json($data);
    }

    /**
     * Checks if the resource was modified based on ETag.
     * If not modified, it sends a 304 status and terminates.
     */
    public function isNotModified(string $seed): bool
    {
        $etag = md5($seed);
        $this->withEtag($etag);

        $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        if ($ifNoneMatch === $etag || $ifNoneMatch === "W/{$etag}") {
            $this->status(304)->send('');
            return true;
        }

        return false;
    }

    /** 
     * Generates a full URL for a specific pagination page.
     * Preserves existing query string parameters.
     */
    private function paginationUrl(int $page): string
    {
        $query = array_merge($_GET, ['page' => $page]);
        $uri = strtok($_SERVER['REQUEST_URI'] ?? '/', '?') ?? '/';
        return $uri . '?' . http_build_query($query);
    }

    /**
     * Resolves the physical path of a template file based on predefined directories.
     * Supports .php and .luna.php (Slenix view engine) extensions.
     */
    private function resolveTemplate(string $template): ?string
    {
        $paths = [
            'views/' . $template . '.php',
            'views/' . $template . '.luna.php',
            'resources/views/' . $template . '.php',
        ];

        foreach ($paths as $path) {
            if (file_exists($path))
                return $path;
        }
        return null;
    }
}