<?php

/*
 |--------------------------------------------------------------------------
 | Classe Response (aprimorada)
 |--------------------------------------------------------------------------
 |
 | Gerencia respostas HTTP com segurança integrada:
 | headers de proteção automáticos, cookies seguros, CORS,
 | cache-control e formatos padronizados (JSON, HTML, XML, download).
 |
 */

declare(strict_types=1);

namespace Slenix\Http;

use InvalidArgumentException;
use RuntimeException;

class Response
{
    private int    $statusCode   = 200;
    private mixed  $content      = '';
    private array  $headers      = [];
    private string $charset      = 'UTF-8';
    private ?string $contentType = null;
    private bool   $sent        = false;
    private array  $cookies      = [];

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
        422 => 'Unprocessable Entity',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
    ];

    /**
     * Define o código de status HTTP.
     *
     * @throws InvalidArgumentException
     */
    public function status(int $code = 200): self
    {
        if ($code < 100 || $code > 599) {
            throw new InvalidArgumentException("Código de status inválido: {$code}. Deve estar entre 100 e 599.");
        }

        $this->statusCode = $code;

        if (!$this->sent && !headers_sent()) {
            http_response_code($code);
        }

        return $this;
    }

    /**
     * Envia resposta JSON.
     *
     * @param mixed $data       Dados a serializar.
     * @param int   $statusCode Código HTTP.
     * @param int   $flags      Flags do json_encode.
     */
    public function json(
        mixed $data,
        int $statusCode = 200,
        int $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ): void {
        $json = json_encode($data, $flags);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Falha ao codificar JSON: ' . json_last_error_msg());
        }

        $this->status($statusCode)
             ->withContentType('application/json')
             ->send($json);
    }

    /**
     * Envia resposta HTML.
     */
    public function html(string $html, int $statusCode = 200): void
    {
        $this->status($statusCode)
             ->withContentType('text/html')
             ->send($html);
    }

    /**
     * Envia resposta de texto simples.
     */
    public function write(string $text, int $statusCode = 200): void
    {
        $this->status($statusCode)
             ->withContentType('text/plain')
             ->send($text);
    }

    /**
     * Envia resposta XML.
     *
     * @param string|\SimpleXMLElement $xml
     */
    public function xml(mixed $xml, int $statusCode = 200): void
    {
        $content = $xml instanceof \SimpleXMLElement ? $xml->asXML() : $xml;

        $this->status($statusCode)
             ->withContentType('application/xml')
             ->send($content);
    }

    /**
     * Resposta padronizada de sucesso.
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
     * Resposta padronizada de erro.
     */
    public function error(string $message, int $statusCode = 500, array $details = []): void
    {
        $payload = [
            'success'     => false,
            'error'       => true,
            'message'     => $message,
            'status_code' => $statusCode,
            'status_text' => self::$statusTexts[$statusCode] ?? 'Unknown',
        ];

        if (!empty($details)) {
            $payload['details'] = $details;
        }

        $this->json($payload, $statusCode);
    }

    /**
     * Envia arquivo para download (leitura em chunks para economia de memória).
     */
    public function download(
        string $filePath,
        ?string $fileName = null,
        ?string $contentType = null,
        bool $inline = false
    ): void {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            $this->error('Arquivo não encontrado ou inacessível.', 404);
        }

        $fileName    = $fileName ?? basename($filePath);
        $contentType = $contentType ?? (mime_content_type($filePath) ?: 'application/octet-stream');
        $disposition = $inline ? 'inline' : 'attachment';

        $this->withHeaders([
            'Content-Type'        => $contentType,
            'Content-Disposition' => "{$disposition}; filename=\"" . addslashes($fileName) . '"',
            'Content-Length'      => (string) filesize($filePath),
            'Cache-Control'       => 'no-store, no-cache, must-revalidate, private',
            'Pragma'              => 'no-cache',
            'Expires'             => '0',
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
     * Renderiza template PHP.
     *
     * @throws RuntimeException Se o template não for encontrado.
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
            throw new RuntimeException("Template '{$template}' não encontrado.");
        }

        extract($data, EXTR_SKIP);
        ob_start();
        include $path;
        $content = ob_get_clean();

        $this->html($content, $statusCode);
    }

    /**
     * Redireciona para outra URL.
     *
     * @throws InvalidArgumentException Para código de status inválido.
     */
    public function redirect(string $url, int $statusCode = 302): void
    {
        $allowed = [301, 302, 303, 307, 308];
        if (!in_array($statusCode, $allowed, true)) {
            throw new InvalidArgumentException(
                "Código de redirecionamento inválido: {$statusCode}. Use um de: " . implode(', ', $allowed)
            );
        }

        // Sanitiza URL de redirecionamento (evita header injection)
        $url = str_replace(["\r", "\n", "\0"], '', $url);

        $this->status($statusCode)
             ->withHeader('Location', $url)
             ->send();
    }

    /**
     * Redireciona de volta usando HTTP_REFERER.
     */
    public function redirectBack(string $fallback = '/', int $statusCode = 302): void
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? $fallback;
        $this->redirect($referer, $statusCode);
    }

    /**
     * Define um header.
     */
    public function withHeader(string $name, string $value): self
    {
        // Sanitiza nome e valor para evitar header injection
        $name  = preg_replace('/[^\w\-]/', '', $name) ?? '';
        $value = str_replace(["\r", "\n", "\0"], '', $value);

        $this->headers[$name] = $value;

        return $this;
    }

    /**
     * Define múltiplos headers.
     */
    public function withHeaders(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->withHeader((string) $name, (string) $value);
        }

        return $this;
    }

    /**
     * Remove um header.
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
     * Obtém um header definido.
     */
    public function getHeader(string $name, mixed $default = null): mixed
    {
        return $this->headers[$name] ?? $default;
    }

    /**
     * Retorna todos os headers definidos.
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Define Content-Type.
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
     * Define um cookie.
     */
    public function withCookie(
        string $name,
        string $value,
        int $expire = 0,
        array $options = []
    ): self {
        $defaults = [
            'path'     => '/',
            'domain'   => '',
            'secure'   => false,
            'httponly' => false,
            'samesite' => 'Lax',
        ];

        $opts = array_merge($defaults, $options);

        $this->cookies[$name] = compact('value', 'expire', 'opts');

        if (!$this->sent && !headers_sent()) {
            setcookie($name, $value, [
                'expires'  => $expire,
                'path'     => $opts['path'],
                'domain'   => $opts['domain'],
                'secure'   => $opts['secure'],
                'httponly' => $opts['httponly'],
                'samesite' => $opts['samesite'],
            ]);
        }

        return $this;
    }

    /**
     * Define um cookie com configurações de segurança máxima.
     */
    public function withSecureCookie(
        string $name,
        string $value,
        int $expire = 0,
        array $options = []
    ): self {
        return $this->withCookie($name, $value, $expire, array_merge($options, [
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]));
    }

    /**
     * Remove um cookie.
     */
    public function withoutCookie(string $name, array $options = []): self
    {
        unset($this->cookies[$name]);

        $opts = array_merge(['path' => '/', 'domain' => ''], $options);

        if (!$this->sent && !headers_sent()) {
            setcookie($name, '', [
                'expires'  => time() - 3600,
                'path'     => $opts['path'],
                'domain'   => $opts['domain'],
            ]);
        }

        return $this;
    }

    /**
     * Retorna todos os cookies definidos.
     */
    public function getCookies(): array
    {
        return $this->cookies;
    }

    /**
     * Define headers de cache.
     */
    public function withCache(int $maxAge = 3600, bool $public = true): self
    {
        $directive = $public ? 'public' : 'private';

        return $this->withHeaders([
            'Cache-Control' => "{$directive}, max-age={$maxAge}",
            'Expires'       => gmdate('D, d M Y H:i:s', time() + $maxAge) . ' GMT',
            'Last-Modified' => gmdate('D, d M Y H:i:s') . ' GMT',
        ]);
    }

    /**
     * Desabilita cache completamente.
     */
    public function withoutCache(): self
    {
        return $this->withHeaders([
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma'        => 'no-cache',
            'Expires'       => '0',
        ]);
    }

    /**
     * Define headers CORS.
     */
    public function withCors(array $options = []): self
    {
        $defaults = [
            'origin'      => '*',
            'methods'     => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
            'headers'     => 'Content-Type, Authorization, X-Requested-With, X-CSRF-Token',
            'credentials' => false,
            'max_age'     => 86400,
        ];

        $opts = array_merge($defaults, $options);

        $headers = [
            'Access-Control-Allow-Origin'  => $opts['origin'],
            'Access-Control-Allow-Methods' => $opts['methods'],
            'Access-Control-Allow-Headers' => $opts['headers'],
            'Access-Control-Max-Age'       => (string) $opts['max_age'],
        ];

        if ($opts['credentials']) {
            $headers['Access-Control-Allow-Credentials'] = 'true';
        }

        return $this->withHeaders($headers);
    }

    /**
     * Envia apenas os headers.
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
     * Envia a resposta completa (headers + corpo).
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

    public function setContent(mixed $content): self
    {
        $this->content = $content;
        return $this;
    }

    public function setHtml(string $html): self
    {
        return $this->withContentType('text/html')->setContent($html);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getStatusText(): string
    {
        return self::$statusTexts[$this->statusCode] ?? 'Unknown';
    }

    public function getBody(): mixed
    {
        return $this->content;
    }

    public function isSent(): bool
    {
        return $this->sent;
    }

    /**
     * Cria uma nova instância de Response.
     */
    public static function make(mixed $content = '', int $statusCode = 200): self
    {
        return (new self())->setContent($content)->status($statusCode);
    }

    /**
     * Localiza um arquivo de template nas pastas configuradas.
     */
    private function resolveTemplate(string $template): ?string
    {
        $paths = [
            'views/' . $template . '.php',
            'views/' . $template . '.luna.php',
            'resources/views/' . $template . '.php',
        ];

        foreach ($paths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }
}