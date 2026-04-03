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
     * Resposta 201 Created com Location header.
     * @param mixed $data
     * @param mixed $location
     * @param string $message
     * @return void
     */
    public function created(mixed $data = null, ?string $location = null, string $message = 'Criado com sucesso.'): void
    {
        if ($location) {
            $this->withHeader('Location', $location);
        }
        $this->success($data, $message, 201);
    }

    /**
     * Resposta 204 No Content.
     * @return void
     */
    public function noContent(): void
    {
        $this->status(204)->send('');
    }

    /**
     * Resposta 401 Unauthorized.
     * @param string $message
     * @return void
     */
    public function unauthorized(string $message = 'Unauthorized.'): void
    {
        $this->error($message, 401);
    }

    /**
     * Resposta 403 Forbidden.
     * @param string $message
     * @return void
     */
    public function forbidden(string $message = 'Forbidden.'): void
    {
        $this->error($message, 403);
    }

    /**
     * Resposta 404 Not Found
     * @param string $message
     * @return void
     */
    public function notFound(string $message = 'Not Found.'): void
    {
        $this->error($message, 404);
    }

    /**
     * Resposta 422 Unprocessable Entity com erros de validação.
     * @param array $errors
     * @param string $message
     * @return void
     */
    public function validationError(array $errors, string $message = 'Dados inválidos.'): void
    {
        $this->json([
            'success' => false,
            'message' => $message,
            'errors'  => $errors,
        ], 422);
    }

    /**
     * Resposta 429 Too Many Requests.
     * @param int $retryAfter
     * @param string $message
     * @return void
     */
    public function tooManyRequests(int $retryAfter = 60, string $message = 'Muitas requisições.'): void
    {
        $this->withHeader('Retry-After', (string) $retryAfter);
        $this->error($message, 429, ['retry_after' => $retryAfter]);
    }

    /**
     * Envia resposta JSON com metadados de paginação.
     *
     * @param array|iterable $items       Itens da página atual
     * @param int            $total       Total de registros
     * @param int            $perPage     Itens por página
     * @param int            $currentPage Página atual
     * @param array          $meta        Metadados extras
     */
    public function paginate(
        mixed $items,
        int            $total,
        int            $perPage     = 15,
        int            $currentPage = 1,
        array          $meta        = []
    ): void {
        $items     = is_array($items) ? $items : iterator_to_array($items);
        $lastPage  = (int) ceil($total / max(1, $perPage));
        $from      = $total > 0 ? (($currentPage - 1) * $perPage) + 1 : null;
        $to        = $total > 0 ? min($currentPage * $perPage, $total) : null;

        $pagination = [
            'success' => true,
            'data'    => $items,
            'meta'    => array_merge([
                'total'        => $total,
                'per_page'     => $perPage,
                'current_page' => $currentPage,
                'last_page'    => $lastPage,
                'from'         => $from,
                'to'           => $to,
                'has_more'     => $currentPage < $lastPage,
            ], $meta),
            'links'   => [
                'first' => $this->paginationUrl(1),
                'last'  => $this->paginationUrl($lastPage),
                'prev'  => $currentPage > 1 ? $this->paginationUrl($currentPage - 1) : null,
                'next'  => $currentPage < $lastPage ? $this->paginationUrl($currentPage + 1) : null,
            ],
        ];

        $this->json($pagination, 200);
    }

    

    /**
     * Paginação a partir de um array completo (sem DB — pagina em memória).
     * @param array $allItems
     * @param int $perPage
     * @param mixed $currentPage
     * @return void
     */
    public function paginateArray(array $allItems, int $perPage = 15, ?int $currentPage = null): void
    {
        $currentPage = $currentPage ?? max(1, (int) ($_GET['page'] ?? 1));
        $total  = count($allItems);
        $offset = ($currentPage - 1) * $perPage;
        $items  = array_slice($allItems, $offset, $perPage);

        $this->paginate($items, $total, $perPage, $currentPage);
    }

    /**
     * Aplica headers de segurança automáticos (recomendados para produção).
     * @param array $options
     * @return Response
     */
    public function withSecurityHeaders(array $options = []): self
    {
        $defaults = [
            'csp'              => true,
            'hsts'             => true,
            'hsts_max_age'     => 31_536_000,
            'hsts_subdomains'  => true,
            'hsts_preload'     => false,
            'xfo'              => 'SAMEORIGIN',    // X-Frame-Options
            'xcto'             => true,            // X-Content-Type-Options
            'referrer'         => 'strict-origin-when-cross-origin',
            'permissions'      => true,
            'xss_protection'   => true,
        ];

        $opts = array_merge($defaults, $options);

        // Content-Security-Policy
        if ($opts['csp']) {
            $csp = $opts['csp_value'] ?? "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self';";
            $this->withHeader('Content-Security-Policy', $csp);
        }

        // HTTP Strict Transport Security
        if ($opts['hsts']) {
            $hsts = "max-age={$opts['hsts_max_age']}";
            if ($opts['hsts_subdomains']) $hsts .= '; includeSubDomains';
            if ($opts['hsts_preload'])    $hsts .= '; preload';
            $this->withHeader('Strict-Transport-Security', $hsts);
        }

        // X-Frame-Options
        if ($opts['xfo']) {
            $this->withHeader('X-Frame-Options', $opts['xfo']);
        }

        // X-Content-Type-Options
        if ($opts['xcto']) {
            $this->withHeader('X-Content-Type-Options', 'nosniff');
        }

        // Referrer-Policy
        if ($opts['referrer']) {
            $this->withHeader('Referrer-Policy', $opts['referrer']);
        }

        // Permissions-Policy
        if ($opts['permissions']) {
            $pp = $opts['permissions_value']
                ?? 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), fullscreen=(self)';
            $this->withHeader('Permissions-Policy', $pp);
        }

        // X-XSS-Protection (legado, mas ainda útil)
        if ($opts['xss_protection']) {
            $this->withHeader('X-XSS-Protection', '1; mode=block');
        }

        // Remove headers que expõem informações do servidor
        $this->withoutHeader('X-Powered-By');
        $this->withoutHeader('Server');

        return $this;
    }

    /**
     * Headers mínimos de segurança (mais leve, para APIs).
     * @return Response
     */
    public function withBasicSecurityHeaders(): self
    {
        return $this->withHeaders([
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options'        => 'DENY',
            'Referrer-Policy'        => 'no-referrer',
        ])->withoutHeader('X-Powered-By');
    }

    /**
     * Configura headers CSP customizados.
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
     * Cache imutável (para assets com hash no nome).
     * @param int $maxAge
     * @return Response
     */
    public function withImmutableCache(int $maxAge = 31_536_000): self
    {
        return $this->withHeader(
            'Cache-Control',
            "public, max-age={$maxAge}, immutable"
        );
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
     * Envia resposta como CSV para download.
     *
     * @param array  $rows     Array de arrays (linhas)
     * @param array  $headers  Cabeçalho das colunas
     * @param string $filename Nome do arquivo
     */
    public function csv(array $rows, array $headers = [], string $filename = 'export.csv'): void
    {
        $this->withHeaders([
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control'       => 'no-store',
            'Pragma'              => 'no-cache',
        ]);

        $this->sendHeaders();

        $output = fopen('php://output', 'w');

        // BOM para Excel reconhecer UTF-8
        fwrite($output, "\xEF\xBB\xBF");

        if (!empty($headers)) {
            fputcsv($output, $headers);
        }

        foreach ($rows as $row) {
            fputcsv($output, (array) $row);
        }

        fclose($output);

        $this->sent = true;
        exit;
    }

    private function paginationUrl(int $page): string
    {
        $query = array_merge($_GET, ['page' => $page]);
        $uri   = strtok($_SERVER['REQUEST_URI'] ?? '/', '?') ?? '/';
        return $uri . '?' . http_build_query($query);
    }

    /**
     * Localiza um arquivo de template nas pastas configuradas.
     * @param string $template
     * @return string|null
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