<?php
/*
 |--------------------------------------------------------------------------
 | Classe AppFactory
 |--------------------------------------------------------------------------
 |
 | Esta classe atua como uma fábrica para a aplicação, inicializando os
 | componentes principais como o roteador e despachando a requisição
 | para a rota correspondente. Ela também gerencia o tempo de início
 | da aplicação.
 |
 */
declare(strict_types=1);

namespace Slenix\Core;

use Slenix\Builds\EnvLoad;
use Slenix\Http\Message\Router;
use Slenix\Http\Message\Response;

class AppFactory
{
    /**
     * @var float Armazena o timestamp de quando a aplicação foi iniciada.
     */
    private float $startTime;

    /**
     * Construtor da classe AppFactory.
     *
     * @param float $startTime O timestamp de quando a aplicação foi iniciada.
     */
    public function __construct(float $startTime)
    {
        $this->startTime = $startTime;
        // Inicia a sessão para CSRF, Flash, etc.
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        // Carrega as variáveis de ambiente
        try {
            EnvLoad::load(__DIR__ . '/../../.env');
        } catch (\Exception $e) {
            $this->handleEnvError($e);
        }
        // Executa a aplicação
        $this->run();
    }

    /**
     * Cria uma nova instância da aplicação.
     *
     * @param float $startTime O timestamp de quando a aplicação foi iniciada.
     * @return self
     */
    public static function create(float $startTime): self
    {
        return new self($startTime);
    }

    /**
     * Manipula erros ao carregar o arquivo .env.
     *
     * @param \Exception $exception
     * @return void
     */
    private function handleEnvError(\Exception $exception): void
    {
        $response = new Response();
        $response->status(500)->json([
            'error' => 'Configuration Error',
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ]);
        exit;
    }

    /**
     * Inclui o arquivo de rotas e despacha a requisição para o roteador.
     *
     * @return void
     */
    private function run(): void
    {
        // Configura manipuladores de erro globais
        set_error_handler(function ($severity, $message, $file, $line) {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler(function (\Throwable $exception) {
            $response = new Response();
            $isDebug = filter_var(env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN);

            // Coleta o trecho de código onde o erro ocorreu
            $codeSnippet = $this->getCodeSnippet($exception->getFile(), $exception->getLine());

            // Resposta detalhada para desenvolvimento
            if ($isDebug) {
                $errorData = [
                    'error' => 'Internal Server Error',
                    'message' => $exception->getMessage(),
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                    'code' => $codeSnippet,
                    'trace' => $exception->getTraceAsString(),
                ];
            } else {
                // Resposta genérica para produção
                $errorData = [
                    'error' => 'Internal Server Error',
                    'message' => 'An unexpected error occurred.',
                ];
            }

            // Verifica se a requisição espera JSON
            $isApiRequest = isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;

            if ($isApiRequest) {
                $response->status(500)->json($errorData);
            } else {
                $response->status(500)->write($this->renderErrorPage($errorData, $isDebug));
            }

            // Log do erro para depuração
            error_log(sprintf(
                "[%s] %s in %s:%d\n%s",
                date('Y-m-d H:i:s'),
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine(),
                $exception->getTraceAsString()
            ));
        });

        require_once __DIR__ . '/../../routes/web.php';
        Router::dispatch();
    }

    /**
     * Extrai um trecho do código ao redor da linha do erro.
     *
     * @param string $file Caminho do arquivo onde o erro ocorreu.
     * @param int $line Linha do erro.
     * @return array Trecho de código com linhas numeradas.
     */
    private function getCodeSnippet(string $file, int $line): array
    {
        if (!is_readable($file)) {
            return ['lines' => [], 'start_line' => 0, 'error' => 'Unable to read file: ' . $file];
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return ['lines' => [], 'start_line' => 0, 'error' => 'Failed to read file contents'];
        }

        $startLine = max(1, $line - 5); // 5 linhas antes
        $endLine = min(count($lines), $line + 5); // 5 linhas depois
        $snippet = [];

        for ($i = $startLine - 1; $i < $endLine; $i++) {
            if (isset($lines[$i])) {
                $snippet[] = [
                    'number' => $i + 1,
                    'code' => htmlspecialchars($lines[$i]),
                    'is_error' => ($i + 1 === $line),
                ];
            }
        }

        return [
            'lines' => $snippet,
            'start_line' => $startLine,
        ];
    }

    /**
     * Renderiza uma página de erro em HTML.
     *
     * @param array $errorData Dados do erro.
     * @param bool $isDebug Indica se está em modo debug.
     * @return string
     */
    private function renderErrorPage(array $errorData, bool $isDebug): string
    {
        $html = '<!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Internal Error - Slenix Framework</title>
            <link rel="preconnect" href="https://fonts.googleapis.com">
            <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
            <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
            <style>
                :root {
                    --bg-color: #f8fafc;
                    --surface-color: #ffffff;
                    --border-color: #e2e8f0;
                    --text-color: #334155;
                    --text-muted: #64748b;
                    --blue-light: #e0f2fe;
                    --blue-lighter: #f0f9ff;
                    --blue-border: #bae6fd;
                    --blue-text: #0369a1;
                    --error-text: #b91c1c;
                    --error-light: #fee2e2;
                    --header-bg: #f1f5f9;
                    --code-bg: #dbeafe;
                    --error-line-bg: #fecaca;
                }
                
                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }
                
                body {
                    font-family: "Inter", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                    line-height: 1.5;
                    color: var(--text-color);
                    background-color: var(--bg-color);
                    padding: 0;
                    margin: 0;
                }
                
                .top-bar {
                    background-color: #1e293b;
                    color: white;
                    padding: 0.5rem 1rem;
                    display: flex;
                    align-items: center;
                    font-size: 0.875rem;
                }
                
                .top-bar-title {
                    font-weight: 500;
                }
    
                .error-container {
                    max-width: 1200px;
                    margin: 2rem auto;
                    background: var(--surface-color);
                    border-radius: 0.5rem;
                    box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
                    overflow: hidden;
                    border: 1px solid var(--border-color);
                }
                
                .error-header {
                    background: var(--header-bg);
                    padding: 1.5rem;
                    border-bottom: 1px solid var(--border-color);
                }
                
                .error-title {
                    font-size: 1.25rem;
                    font-weight: 600;
                    color: var(--text-color);
                    margin-bottom: 0.5rem;
                }
                
                .error-message {
                    color: var(--error-text);
                    font-weight: 500;
                }
                
                .error-body {
                    padding: 1.5rem;
                }
                
                .detail-section {
                    margin-bottom: 1.5rem;
                    border: 1px solid var(--border-color);
                    border-radius: 0.375rem;
                    overflow: hidden;
                }
                
                .detail-section:last-child {
                    margin-bottom: 0;
                }
                
                .detail-row {
                    display: flex;
                    padding: 0.75rem 1.5rem;
                    border-bottom: 1px solid var(--border-color);
                }
                
                .detail-row:last-child {
                    border-bottom: none;
                }
                
                .detail-label {
                    font-weight: 500;
                    width: 120px;
                    flex-shrink: 0;
                }
                
                .detail-value {
                    color: var(--text-muted);
                }
                
                .section-header {
                    display: flex;
                    align-items: center;
                    gap: 0.5rem;
                    padding: 0.75rem 1.5rem;
                    background: var(--header-bg);
                    border-bottom: 1px solid var(--border-color);
                    font-weight: 600;
                    color: var(--blue-text);
                }
                
                .section-header svg {
                    width: 1.25rem;
                    height: 1.25rem;
                    color: var(--blue-text);
                }
                
                .section-content {
                    padding: 0;
                }
                
                .code-container {
                    background: var(--blue-lighter);
                    padding: 0;
                    overflow-x: auto;
                    font-family: "SFMono-Regular", Menlo, Monaco, Consolas, monospace;
                    font-size: 0.875rem;
                    line-height: 1.7;
                }
                
                .code-line {
                    display: flex;
                    white-space: pre;
                }
                
                .code-line.error {
                    background: var(--error-line-bg);
                }
                
                .line-number {
                    color: var(--text-muted);
                    text-align: right;
                    padding: 0 0.75rem;
                    min-width: 3rem;
                    user-select: none;
                    border-right: 1px solid var(--blue-border);
                    background: rgba(219, 234, 254, 0.3);
                }
                
                .line-content {
                    padding: 0 1rem 0 0.75rem;
                    flex: 1;
                }
                
                .stack-trace {
                    font-family: "SFMono-Regular", Menlo, Monaco, Consolas, monospace;
                    font-size: 0.8125rem;
                    line-height: 1.5;
                    color: var(--text-muted);
                    padding: 1rem 1.5rem;
                    white-space: pre-wrap;
                    overflow-x: auto;
                    background: var(--blue-lighter);
                }
                
                .stack-line {
                    margin-bottom: 0.25rem;
                }
                
                .keyword { color: #8250df; }
                .function { color: #0550ae; }
                .string { color: #0a3622; }
                .variable { color: #953800; }
                .comment { color: #6a737d; }
                
                @media (max-width: 768px) {
                    .error-container {
                        margin: 1rem;
                    }
                    
                    .detail-row {
                        flex-direction: column;
                    }
                    
                    .detail-label {
                        width: 100%;
                        margin-bottom: 0.25rem;
                    }
                }
            </style>
        </head>
        <body>
            <div class="top-bar">
                <div class="top-bar-title">Internal Error - Slenix Framework</div>
            </div>
            
            <div class="error-container">
                <div class="error-header">
                    <div class="error-title">Internal Server Error</div>
                    <div class="error-message">' . ($isDebug ? htmlspecialchars($errorData["message"]) : "An unexpected error has occurred.") . '</div>
                </div>
                
                <div class="error-body">';
        
        if (!$isDebug) {
            $html .= '
                    <div style="padding: 1rem; background: var(--error-light); border-radius: 0.375rem;">
                        An internal error has occurred. Please try again later or contact the system administrator.
                    </div>';
        } else {
            // File and Line details
            $html .= '
                    <div class="detail-section">
                        <div class="detail-row">
                            <div class="detail-label">File:</div>
                            <div class="detail-value">' . htmlspecialchars($errorData["file"] ?? "Unknown") . '</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Line:</div>
                            <div class="detail-value">' . htmlspecialchars((string)($errorData["line"] ?? "Unknown")) . '</div>
                        </div>
                    </div>';
                    
            // Code Snippet
            if (!empty($errorData['code']['lines'])) {
                $html .= '
                    <div class="detail-section" style="margin-top: 1.5rem;">
                        <div class="section-header">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="16 18 22 12 16 6"></polyline>
                                <polyline points="8 6 2 12 8 18"></polyline>
                            </svg>
                            Code Snippet
                        </div>
                        <div class="section-content">
                            <div class="code-container">';
                            
                foreach ($errorData['code']['lines'] as $line) {
                    $class = $line['is_error'] ? ' error' : '';
                    $code = $line['code']; // Código bruto (sem htmlspecialchars)
    
                    // Highlighting: Aplicamos a coloração de sintaxe
                    // 1. Comentários
                    $code = preg_replace('/(\/\/.*$)|(\/\*.*?\*\/)/', '<span class="comment">$1$2</span>', $code);
    
                    // 2. Strings (devem ser processadas antes para evitar que aspas sejam afetadas por outras substituições)
                    $code = preg_replace('/\'([^\']*)\'/', '\'<span class="string">$1</span>\'', $code);
                    $code = preg_replace('/"([^"]*)"/', '"<span class="string">$1</span>"', $code);
    
                    // 3. Palavras-chave (após strings para evitar substituições dentro de strings)
                    $keywords = ['function', 'return', 'if', 'else', 'foreach', 'while', 'for', 'try', 'catch', 'throw', 'new', 'class', 'extends', 'implements', 'namespace', 'use', 'public', 'private', 'protected', 'static'];
                    $keywordPattern = '/\b(' . implode('|', $keywords) . ')\b(?![^<]*<\/span>)/';
                    $code = preg_replace($keywordPattern, '<span class="keyword">$1</span>', $code);
    
                    // 4. Funções (após strings e palavras-chave)
                    $code = preg_replace('/\b(\w+)(?=\s*\()(?![^<]*<\/span>)/', '<span class="function">$1</span>', $code);
    
                    // 5. Variáveis (após strings, palavras-chave e funções)
                    $code = preg_replace('/(\$\w+)(?![^<]*<\/span>)/', '<span class="variable">$1</span>', $code);
    
                    // Agora codificamos apenas o texto que não deve ser interpretado como HTML
                    // (as tags <span> inseridas acima serão preservadas)
                    $html .= '<div class="code-line' . $class . '">
                                <div class="line-number">' . sprintf('%d', $line['number']) . '</div>
                                <div class="line-content">' . $code . '</div>
                              </div>';
                }
                            
                $html .= '
                            </div>
                        </div>
                    </div>';
            } elseif (isset($errorData['code']['error'])) {
                $html .= '
                    <div class="detail-section" style="margin-top: 1.5rem;">
                        <div class="section-header">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10"></circle>
                                <line x1="12" y1="8" x2="12" y2="12"></line>
                                <line x1="12" y1="16" x2="12.01" y2="16"></line>
                            </svg>
                            Code Loading Error
                        </div>
                        <div class="section-content">
                            <div style="padding: 1rem 1.5rem; color: var(--error-text);">
                                ' . htmlspecialchars($errorData['code']['error']) . '
                            </div>
                        </div>
                    </div>';
            }
                    
            // Stack Trace
            $html .= '
                    <div class="detail-section" style="margin-top: 1.5rem;">
                        <div class="section-header">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="12" y1="20" x2="12" y2="10"></line>
                                <line x1="18" y1="20" x2="18" y2="4"></line>
                                <line x1="6" y1="20" x2="6" y2="16"></line>
                            </svg>
                            Stack Trace
                        </div>
                        <div class="section-content">
                            <div class="stack-trace">';
            
            if (!empty($errorData['trace'])) {
                $traceLines = explode("\n", $errorData['trace']);
                foreach ($traceLines as $index => $line) {
                    if (trim($line) !== '') {
                        $html .= '<div class="stack-line">#' . $index . ' ' . htmlspecialchars($line) . '</div>';
                    }
                }
            } else {
                $html .= 'No stack trace information available';
            }
                            
            $html .= '
                            </div>
                        </div>
                    </div>';
        }
                
        $html .= '
                </div>
            </div>
        </body>
        </html>';
        
        return $html;
    }
}