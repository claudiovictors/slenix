<?php
/*  
|--------------------------------------------------------------------------  
| Classe Router  
|--------------------------------------------------------------------------  
|  
| Esta classe é responsável por gerenciar as rotas da aplicação, mapeando  
| URIs para seus respectivos handlers (funções ou métodos de classes) e  
| aplicando middlewares quando necessário. Ela oferece métodos para definir  
| rotas para diferentes verbos HTTP e agrupar rotas com prefixos e middlewares.  
|  
*/    

declare(strict_types=1);

namespace Slenix\Http\Message;

class Response {
    private int $codeStatus = 200;
    private string|array|object $content;
    private array $headers = [];
    private array $cookies = [];
    private ?string $template = null;
    private array $templateData = [];
    private string $charset = 'utf-8';
    private ?string $contentType = null;
    private bool $hasBeenSent = false;

    /**
     * Define o código de status HTTP da resposta
     * @param int $codeStatus Código de status HTTP
     * @return self
     */
    public function status(int $codeStatus = 200): self {
        $this->codeStatus = $codeStatus;
        http_response_code($this->codeStatus);
        return $this;
    }

    /**
     * Envia uma resposta em formato JSON
     * @param array $data Os dados a serem convertidos para JSON
     * @param int $codeStatus Código de status HTTP
     * @return void
     */
    public function json(array $data, int $codeStatus = 200): void {
        $this->status($codeStatus);
        $this->content = $data;
        header('Content-Type: application/json; charset=utf8');
        echo json_encode($this->content, JSON_FORCE_OBJECT, JSON_HEX_QUOT);
        exit;
    }

    /**
     * Envia uma resposta em texto
     * @param string $text O texto a ser enviado
     * @param int $codeStatus Código de status HTTP
     * @return void
     */
    public function write(string $text, int $codeStatus = 200): void {
        $this->status($codeStatus);
        $this->content = $text;
        header('Content-Type: text/html; charset=utf8');
        echo $this->content;
        exit;
    }

    /**
     * Define o corpo da resposta como HTML.
     *
     * @param string $html
     * @return self
     */
    public function html(string $html): self {
        header('Content-Type: text/html; charset=utf8');
        $this->content = $html;
        return $this;
    }

    /**
     * Define um cookie na resposta
     * @param string $name Nome do cookie
     * @param string $value Valor do cookie
     * @param int $expire Tempo de expiração
     * @param int $codeStatus Código de status HTTP
     * @return self
     */
    public function withCookie(string $name, string $value, int $expire = 0, int $codeStatus = 200): self {
        $this->status($codeStatus);
        setcookie($name, $value, $expire, '/');
        return $this;
    }

    /**
     * Redireciona para outro caminho
     * @param string $path Caminho para redirecionamento
     * @param int $codeStatus Código de status HTTP
     * @return self
     */
    public function redirect(string $path, int $codeStatus = 302): self {
        $this->status($codeStatus);
        $this->content = $path;
        header('Location: '. $this->content);
        return $this;
    }

    /**
     * Define um header na resposta
     * @param string $name Nome do header
     * @param string $value Valor do header
     * @return self
     */
    public function withHeader(string $name, string $value): self {
        $this->headers[$name] = $value;
        return $this;
    }

    /**
     * Define múltiplos headers na resposta
     * @param array $headers Array associativo de headers
     * @return self
     */
    public function withHeaders(array $headers): self {
        foreach ($headers as $name => $value) {
            $this->withHeader($name, $value);
        }
        return $this;
    }

    /**
     * Remove um header da resposta
     * @param string $name Nome do header
     * @return self
     */
    public function withoutHeader(string $name): self {
        if (isset($this->headers[$name])) {
            unset($this->headers[$name]);
        }
        return $this;
    }

    /**
     * Envia uma resposta em XML
     * @param string|SimpleXMLElement $xml Conteúdo XML
     * @param int $codeStatus Código de status HTTP
     * @return void
     */
    public function xml($xml, int $codeStatus = 200): void {
        $this->status($codeStatus);
        $this->content = is_string($xml) ? $xml : $xml->asXML();
        header('Content-Type: application/xml; charset=' . $this->charset);
        echo $this->content;
        exit;
    }

    /**
     * Envia um arquivo como download
     * @param string $filePath Caminho do arquivo
     * @param string|null $fileName Nome do arquivo para download
     * @param string|null $contentType Tipo de conteúdo
     * @return void
     */
    public function download(string $filePath, ?string $fileName = null, ?string $contentType = null): void {
        if (!file_exists($filePath)) {
            $this->status(404)->json(['error' => 'File not found']);
        }

        $fileName = $fileName ?? basename($filePath);
        $contentType = $contentType ?? mime_content_type($filePath);

        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        readfile($filePath);
        exit;
    }

    /**
     * Renderiza um template com dados
     * @param string $template Caminho do template
     * @param array $data Dados para o template
     * @param int $codeStatus Código de status HTTP
     * @return void
     */
    public function render(string $template, array $data = [], int $codeStatus = 200): mixed {
        $this->status($codeStatus);
        return view($template, $data);
    }

    /**
     * Define um cookie seguro na resposta
     * @param string $name Nome do cookie
     * @param string $value Valor do cookie
     * @param int $expire Tempo de expiração
     * @param array $options Opções adicionais para o cookie
     * @return self
     */
    public function withSecureCookie(string $name, string $value, int $expire = 0, array $options = []): self {
        $defaultOptions = [
            'path' => '/',
            'domain' => '',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict'
        ];
        
        $options = array_merge($defaultOptions, $options);
        setcookie($name, $value, [
            'expires' => $expire,
            'path' => $options['path'],
            'domain' => $options['domain'],
            'secure' => $options['secure'],
            'httponly' => $options['httponly'],
            'samesite' => $options['samesite']
        ]);
        
        return $this;
    }

    /**
     * Remove um cookie
     * @param string $name Nome do cookie
     * @return self
     */
    public function withoutCookie(string $name): self {
        setcookie($name, '', time() - 3600, '/');
        return $this;
    }

    /**
     * Envia uma resposta em formato JSONP
     * @param string $callback Nome da função de callback
     * @param array $data Dados a serem enviados
     * @param int $codeStatus Código de status HTTP
     * @return void
     */
    public function jsonp(string $callback, array $data, int $codeStatus = 200): void {
        $this->status($codeStatus);
        $json = json_encode($data);
        header('Content-Type: application/javascript; charset=' . $this->charset);
        echo "{$callback}({$json});";
        exit;
    }

    /**
     * Envia todas as cabeçalhos definidos
     * @return self
     */
    public function sendHeaders(): self {
        if ($this->contentType !== null) {
            header('Content-Type: ' . $this->contentType . '; charset=' . $this->charset);
        }
        
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        
        return $this;
    }

    /**
     * Envia a resposta com os cabeçalhos definidos
     * @param mixed $content Conteúdo a ser enviado
     * @param int $codeStatus Código de status HTTP
     * @return void
     */
    public function send($content = null, ?int $codeStatus = null): void {
        if ($this->hasBeenSent) {
            return;
        }
        
        if ($codeStatus !== null) {
            $this->status($codeStatus);
        }
        
        if ($content !== null) {
            $this->content = $content;
        }
        
        $this->sendHeaders();
        
        if (is_array($this->content) || is_object($this->content)) {
            echo json_encode($this->content);
        } else {
            echo $this->content;
        }
        
        $this->hasBeenSent = true;
        exit;
    }
}