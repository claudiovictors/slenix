<?php
/*
 |--------------------------------------------------------------------------
 | Classe Kernel
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

use Exception;
use Slenix\Libraries\EnvLoad;
use Slenix\Http\Message\Router;
use Slenix\Http\Message\Response;

class Kernel {

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
        try {
            $this->startTime = $startTime;
            self::initialSession();
            self::loadEnv();
            $this->run();
        }catch (Exception $error){
            self::handleEnvError($error);
        }
    }

    protected function execute()

    /**
     * Método que inicializa as sessões globais
     *
     * @return void
     */
    protected static function initialSession(): void {
        class_alias(\Slenix\Libraries\Session::class, 'Session');
        
        if(session_status() === PHP_SESSION_NONE):
            session_start();
        endif;
    }

    /**
     * Carrega o arquivo .env 
     *
     * @return void
     */
    private static function loadEnv(): void {
        try {
            EnvLoad::load(__DIR__ . '/../../.env');   
        }catch(Exception $error){
            self::handleEnvError($error);
        }
    }
    
    /**
     * Manipula erros ao carregar o arquivo .env.
     *
     * @param \Exception $exception
     * @return void
     */
    private static function handleEnvError(\Exception $exception): void
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
    private static function run(): void {
        
        set_error_handler(function ($severity, $message, $file, $line) {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler(function (\Throwable $exception) {
            $response = new Response();
            $isDebug = filter_var(env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN);

            $codeSnippet = $this->getCodeSnippet($exception->getFile(), $exception->getLine());

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
                
                $errorData = [
                    'error' => 'Internal Server Error',
                    'message' => 'An unexpected error occurred.',
                ];
            }

            $isApiRequest = isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;

            if ($isApiRequest) {
                $response->status(500)->json($errorData);
            } else {
                $response->status(500)->write($this->renderErrorPage($errorData, $isDebug));
            }

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

        $startLine = max(1, $line - 5);
        $endLine = min(count($lines), $line + 5);
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
    private function renderErrorPage(array $errorData, bool $isDebug): string {        
        $html = '<!DOCTYPE html>
        <html lang="pt-AO">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Internal Error - Slenix Framework</title>
            <link rel="preconnect" href="https://fonts.googleapis.com">
            <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
            <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
            <link rel="shortcut icon" href="/logo.svg" type="image/x-icon">
            <link rel="stylesheet" href="'.CSS_ERROR.'">
            <title>Slenix Error -  Page</title>
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
                    $code = $line['code']; 
    
                    $code = preg_replace('/(\/\/.*$)|(\/\*.*?\*\/)/', '<span class="comment">$1$2</span>', $code);
    
                    $code = preg_replace('/\'([^\']*)\'/', '\'<span class="string">$1</span>\'', $code);
                    $code = preg_replace('/"([^"]*)"/', '"<span class="string">$1</span>"', $code);
    
                    $keywords = ['function', 'return', 'if', 'else', 'foreach', 'while', 'for', 'try', 'catch', 'throw', 'new', 'class', 'extends', 'implements', 'namespace', 'use', 'public', 'private', 'protected', 'static'];
                    $keywordPattern = '/\b(' . implode('|', $keywords) . ')\b(?![^<]*<\/span>)/';
                    $code = preg_replace($keywordPattern, '<span class="keyword">$1</span>', $code);
    
                    $code = preg_replace('/\b(\w+)(?=\s*\()(?![^<]*<\/span>)/', '<span class="function">$1</span>', $code);
    
                    $code = preg_replace('/(\$\w+)(?![^<]*<\/span>)/', '<span class="variable">$1</span>', $code);
    
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