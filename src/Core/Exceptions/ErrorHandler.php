<?php

/*
 |--------------------------------------------------------------------------
 | Classe ErrorHandler
 |--------------------------------------------------------------------------
 |
 | Gerencia o tratamento centralizado de erros e exceções.
 | Usa Request para detectar o contexto (API vs browser) e
 | Response para formatar e enviar a resposta de erro adequada.
 | Layout da página de erro inspirado no Next.js (dark mode).
 |
 */

declare(strict_types=1);

namespace Slenix\Core\Exceptions;

use Slenix\Http\Request;
use Slenix\Http\Response;

class ErrorHandler
{
    /**
     * Cache interno do modo debug.
     * Null = ainda não resolvido (lazy).
     */
    private ?bool $debugCache = null;

    /** @var array<int, string> */
    private static array $errorTypes = [
        E_ERROR             => 'Fatal Error',
        E_WARNING           => 'Warning',
        E_PARSE             => 'Parse Error',
        E_NOTICE            => 'Notice',
        E_CORE_ERROR        => 'Core Error',
        E_CORE_WARNING      => 'Core Warning',
        E_COMPILE_ERROR     => 'Compile Error',
        E_COMPILE_WARNING   => 'Compile Warning',
        E_USER_ERROR        => 'User Error',
        E_USER_WARNING      => 'User Warning',
        E_USER_NOTICE       => 'User Notice',
        E_STRICT            => 'Strict Standards',
        E_RECOVERABLE_ERROR => 'Recoverable Error',
        E_DEPRECATED        => 'Deprecated',
        E_USER_DEPRECATED   => 'User Deprecated',
    ];

    public function __construct()
    {
        // Não lemos APP_DEBUG aqui pois o .env ainda não foi carregado.
        // A leitura é feita de forma lazy via isDebug().
    }

    /**
     * Lê APP_DEBUG de forma lazy: só resolve após o .env estar carregado.
     * Fallback robusto: verifica $_ENV, $_SERVER e getenv() diretamente
     * caso a função env() ainda não esteja disponível.
     */
    private function isDebug(): bool
    {
        if ($this->debugCache !== null) {
            return $this->debugCache;
        }

        // Tenta via função helper env() (disponível após EnvLoad)
        if (function_exists('env')) {
            $val = env('APP_DEBUG', false);
        } else {
            // Fallback direto nos superglobais / getenv
            $val = $_ENV['APP_DEBUG']
                ?? $_SERVER['APP_DEBUG']
                ?? getenv('APP_DEBUG')
                ?? false;
        }

        $this->debugCache = filter_var($val, FILTER_VALIDATE_BOOLEAN);

        return $this->debugCache;
    }

    /**
     * Converte erros PHP em exceções para tratamento uniforme.
     *
     * @throws \ErrorException
     */
    public function handleError(int $severity, string $message, string $file, int $line): bool
    {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        throw new \ErrorException($message, 0, $severity, $file, $line);
    }

    /**
     * Trata exceções não capturadas detectando o contexto automaticamente
     * via Request (API JSON vs browser HTML).
     */
    public function handleException(\Throwable $exception): void
    {
        $statusCode = $this->resolveStatusCode($exception);
        $errorData  = $this->buildErrorData($exception, $statusCode);

        $this->logException($exception);

        $request  = new Request();
        $response = new Response();

        $response->withoutCache();

        if ($this->isApiRequest($request)) {
            $response->status($statusCode)->json($errorData);
        } else {
            $response->status($statusCode)->html(
                $this->renderErrorPage($exception)
            );
        }
    }

    /**
     * Trata erros de configuração do ambiente (.env).
     */
    public function handleEnvError(\Exception $exception): never
    {
        $response = new Response();
        $response->status(500)->json([
            'error'   => 'Configuration Error',
            'message' => $exception->getMessage(),
        ]);

        exit(1);
    }

    private function isApiRequest(Request $request): bool
    {
        return $request->isJson()
            || $request->expectsJson()
            || $request->isAjax()
            || str_starts_with($request->uri(), '/api/');
    }

    private function resolveStatusCode(\Throwable $exception): int
    {
        return match (true) {
            $exception instanceof \InvalidArgumentException => 400,
            $exception instanceof \RuntimeException        => 500,
            default                                        => 500,
        };
    }

    private function buildErrorData(\Throwable $exception, int $statusCode): array
    {
        $base = [
            'success'     => false,
            'error'       => true,
            'status_code' => $statusCode,
            'message'     => $this->isDebug()
                ? $exception->getMessage()
                : 'An unexpected error occurred.',
        ];

        if ($this->isDebug()) {
            $base['debug'] = [
                'exception' => get_class($exception),
                'file'      => $exception->getFile(),
                'line'      => $exception->getLine(),
                'trace'     => $exception->getTraceAsString(),
            ];
        }

        return $base;
    }

    private function logException(\Throwable $exception): void
    {
        error_log(sprintf(
            "[%s] [%s] %s in %s:%d\n%s",
            date('Y-m-d H:i:s'),
            get_class($exception),
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            $exception->getTraceAsString()
        ));
    }

    /**
     * Extrai trecho de código ao redor da linha do erro.
     */
    private function getCodeSnippet(string $file, int $errorLine, int $context = 6): array
    {
        if (!is_readable($file)) {
            return ['lines' => [], 'start_line' => 0];
        }

        $allLines  = file($file, FILE_IGNORE_NEW_LINES) ?: [];
        $startLine = max(1, $errorLine - $context);
        $endLine   = min(count($allLines), $errorLine + $context);
        $snippet   = [];

        for ($i = $startLine - 1; $i < $endLine; $i++) {
            if (!isset($allLines[$i])) {
                continue;
            }

            $snippet[] = [
                'number'   => $i + 1,
                'code'     => $allLines[$i],
                'is_error' => ($i + 1 === $errorLine),
            ];
        }

        return ['lines' => $snippet, 'start_line' => $startLine];
    }

    /**
     * Aplica highlight de sintaxe PHP via regex (sem dependências externas).
     */
    private function highlight(string $raw): string
    {
        $code = htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Comentários
        $code = preg_replace(
            '/(\/\/[^\n]*)/',
            '<span class="hl-comment">$1</span>',
            $code
        );

        // Strings
        $code = preg_replace(
            '/(&quot;(?:[^&]|&(?!quot;))*&quot;|&#039;(?:[^&]|&(?!#039;))*&#039;)/U',
            '<span class="hl-string">$1</span>',
            $code
        );

        // Palavras-chave
        $keywords = [
            'function','return','if','else','elseif','foreach','while','for',
            'do','switch','case','break','continue','try','catch','finally',
            'throw','new','class','interface','trait','extends','implements',
            'namespace','use','public','private','protected','static','abstract',
            'final','readonly','match','fn','echo','print','require','include',
            'require_once','include_once','true','false','null','self','parent',
            'declare','default','void',
        ];
        $code = preg_replace(
            '/\b(' . implode('|', $keywords) . ')\b(?![^<]*<\/span>)/',
            '<span class="hl-kw">$1</span>',
            $code
        );

        // Variáveis
        $code = preg_replace('/(\$[a-zA-Z_]\w*)(?![^<]*<\/span>)/', '<span class="hl-var">$1</span>', $code);

        // Chamadas de função
        $code = preg_replace('/\b([a-zA-Z_]\w*)\s*(?=\()(?![^<]*<\/span>)/', '<span class="hl-fn">$1</span>', $code);

        // Números
        $code = preg_replace('/\b(\d+\.?\d*)\b(?![^<]*<\/span>)/', '<span class="hl-num">$1</span>', $code);

        return $code;
    }

    /**
     * Encurta o caminho do arquivo para exibição.
     */
    private function shortenPath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $root = defined('BASE_PATH')
            ? rtrim(str_replace('\\', '/', ROOT_PATH), '/') . '/'
            : rtrim(str_replace('\\', '/', dirname(__DIR__, 3)), '/') . '/';

        if (str_starts_with($path, $root)) {
            return ltrim(substr($path, strlen($root)), '/');
        }

        $parts = explode('/', $path);
        return implode('/', array_slice($parts, -3));
    }

    /**
     * Constrói linhas de código HTML para um frame.
     */
    private function buildCodeRows(string $file, int $line, int $context = 5): string
    {
        $snippet = $this->getCodeSnippet($file, $line, $context);
        $html    = '';

        foreach ($snippet['lines'] as $ln) {
            $isErr    = $ln['is_error'];
            $rowCls   = $isErr ? ' row-error' : '';
            $arrow    = $isErr
                ? '<span class="arrow-icon">&gt;</span>'
                : '<span class="arrow-icon"></span>';
            $code     = $this->highlight($ln['code']);
            $num      = $ln['number'];

            $html .= "<div class=\"code-row{$rowCls}\">"
                   . "<span class=\"col-arrow\">{$arrow}</span>"
                   . "<span class=\"col-ln\">{$num}</span>"
                   . "<span class=\"col-code\">{$code}</span>"
                   . "</div>";
        }

        return $html;
    }

    private function renderErrorPage(\Throwable $exception): string
    {
        if (!$this->isDebug()) {
            return $this->renderProductionPage();
        }

        $appName  = htmlspecialchars(env('APP_NAME', 'Slenix'), ENT_QUOTES, 'UTF-8');
        $exClass  = htmlspecialchars(get_class($exception), ENT_QUOTES, 'UTF-8');
        $message  = htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8');
        $file     = $exception->getFile();
        $line     = $exception->getLine();
        $short    = htmlspecialchars($this->shortenPath($file), ENT_QUOTES, 'UTF-8');

        // Source code block
        $sourceRows = $this->buildCodeRows($file, $line, 6);

        $logosvg = public_path('logo.svg');

        // Raw trace
        $rawTrace = htmlspecialchars($exception->getTraceAsString(), ENT_QUOTES, 'UTF-8');

        return <<<HTML
        <!DOCTYPE html>
        <html lang="pt-AO">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <link rel="shortcut icon" href={$logosvg} type="image/x-icon">
            <title>Unhandled Error — {$appName}</title>
            <style>
                *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

                :root {
                    --bg:         #0a0a0a;
                    --surface:    #111;
                    --surface2:   #1a1a1a;
                    --border:     #262626;
                    --border2:    #333;
                    --red:        #e5484d;
                    --red-glow:   rgba(229,72,77,.18);
                    --green:      #3dd68c;
                    --text:       #ededed;
                    --muted:      #888;
                    --dim:        #555;
                    --mono:       "Geist Mono","SFMono-Regular",Menlo,Consolas,monospace;
                    --sans:       -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
                    --r:          6px;
                    /* syntax */
                    --kw:         #ff79c6;
                    --var:        #f1fa8c;
                    --fn:         #50fa7b;
                    --str:        #8be9fd;
                    --num:        #bd93f9;
                    --cmt:        #5a6272;
                }

                html,body { background:var(--bg); color:var(--text); font-family:var(--sans);
                            font-size:14px; line-height:1.5; min-height:100vh; }

                /* red topbar */
                .topbar { position:fixed; top:0; left:0; right:0; height:3px;
                          background:var(--red); z-index:999; }

                .wrapper { max-width:900px; margin:0 auto; padding:3.5rem 1.5rem 5rem; }

                /* ---- nav ---- */
                .nav { display:flex; align-items:center; justify-content:space-between;
                       margin-bottom:1.5rem; font-size:12px; color:var(--muted); }
                .nav-left  { display:flex; align-items:center; gap:.4rem; }
                .nav-right { display:flex; align-items:center; gap:.6rem; }
                .nav-btn { background:var(--surface2); border:1px solid var(--border2);
                           color:var(--text); width:24px; height:24px; border-radius:var(--r);
                           cursor:pointer; font-size:13px; display:inline-flex;
                           align-items:center; justify-content:center; }
                .dot { width:8px; height:8px; background:var(--green); border-radius:50%; }
                .badge-ex { font-size:11px; font-weight:600; background:var(--red-glow);
                            color:var(--red); border:1px solid rgba(229,72,77,.5);
                            border-radius:4px; padding:1px 7px; font-family:var(--mono); }

                /* ---- heading ---- */
                .err-heading { font-size:1.65rem; font-weight:700; letter-spacing:-.025em;
                               color:var(--text); margin-bottom:.3rem; }
                .err-msg { font-family:var(--mono); font-size:.875rem; color:var(--red);
                           margin-bottom:2rem; word-break:break-all; }

                /* ---- section label ---- */
                .section-lbl { font-size:.7rem; font-weight:700; text-transform:uppercase;
                               letter-spacing:.1em; color:var(--muted); margin:2rem 0 .6rem; }

                /* ---- code card ---- */
                .code-card { background:var(--surface); border:1px solid var(--border);
                             border-radius:var(--r); overflow:hidden; }
                .code-card-hdr { display:flex; align-items:center; justify-content:space-between;
                                 padding:.55rem 1rem; border-bottom:1px solid var(--border);
                                 font-family:var(--mono); font-size:.75rem; color:var(--muted); }
                .code-body { overflow-x:auto; padding:.4rem 0; }

                /* ---- code rows ---- */
                .code-row { display:flex; align-items:flex-start; font-family:var(--mono);
                            font-size:.8rem; line-height:1.8; padding:0 .75rem; }
                .code-row.row-error { background:var(--red-glow); }
                .col-arrow { width:1.25rem; flex-shrink:0; color:var(--red);
                             font-weight:700; user-select:none; }
                .arrow-icon { display:inline-block; }
                .col-ln { width:2.25rem; flex-shrink:0; text-align:right; padding-right:.75rem;
                          color:var(--dim); user-select:none; }
                .col-code { flex:1; white-space:pre; }

                /* ---- syntax ---- */
                .hl-kw  { color:var(--kw); }
                .hl-var { color:var(--var); }
                .hl-fn  { color:var(--fn); }
                .hl-string  { color:var(--str); }
                .hl-num { color:var(--num); }
                .hl-comment { color:var(--cmt); font-style:italic; }

                /* ---- frames ---- */
                .frames { display:flex; flex-direction:column; gap:.35rem; }
                .frame { background:var(--surface); border:1px solid var(--border);
                         border-radius:var(--r); overflow:hidden; }
                .frame-hdr { display:flex; align-items:center; justify-content:space-between;
                             padding:.6rem 1rem; cursor:pointer; gap:.75rem; user-select:none; }
                .frame-hdr:hover { background:var(--surface2); }
                .frame-fn { font-family:var(--mono); font-size:.78rem; color:var(--text);
                            flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
                .frame-file { font-family:var(--mono); font-size:.72rem; color:var(--muted);
                              white-space:nowrap; }
                .frame-chev { color:var(--dim); font-size:.65rem; transition:transform .15s;
                              flex-shrink:0; }
                .frame.open .frame-chev { transform:rotate(90deg); }
                .frame-body { display:none; border-top:1px solid var(--border);
                              padding:.35rem 0; overflow-x:auto; }
                .frame.open .frame-body { display:block; }

                /* ---- show collapsed / raw trace ---- */
                .link-btn { background:none; border:none; color:var(--muted); font-size:.78rem;
                            cursor:pointer; padding:.5rem 0; text-decoration:underline;
                            text-underline-offset:3px; }
                .link-btn:hover { color:var(--text); }

                pre.raw { background:var(--surface); border:1px solid var(--border);
                          border-radius:var(--r); padding:.9rem 1rem; font-family:var(--mono);
                          font-size:.72rem; color:var(--muted); white-space:pre-wrap;
                          overflow-x:auto; max-height:300px; overflow-y:auto;
                          line-height:1.65; margin-top:.4rem; }

                /* scrollbar */
                ::-webkit-scrollbar { width:5px; height:5px; }
                ::-webkit-scrollbar-track { background:transparent; }
                ::-webkit-scrollbar-thumb { background:var(--border2); border-radius:3px; }
            </style>
        </head>
        <body>
        <div class="topbar"></div>

        <div class="wrapper">
            <!-- nav bar -->
            <div class="nav">
                <div class="nav-left">
                    <button class="nav-btn">&#8592;</button>
                    <button class="nav-btn">&#8594;</button>
                    <span>1 of 1 unhandled error</span>
                </div>
                <div class="nav-right">
                    <span class="dot"></span>
                    <span>{$appName} is up to date</span>
                    <span class="badge-ex">{$exClass}</span>
                </div>
            </div>

            <!-- heading -->
            <h1 class="err-heading">Unhandled Runtime Error</h1>
            <div class="err-msg">Error: {$message}</div>

            <!-- source -->
            <div class="section-lbl">Source</div>
            <div class="code-card">
                <div class="code-card-hdr">
                    <span>{$short} ({$line}) @ {$exClass}</span>
                    <span>&#x2197;</span>
                </div>
                <div class="code-body">{$sourceRows}</div>
            </div>


            <button class="link-btn" style="margin-top:.75rem"
                onclick="var el=document.getElementById('rt');var h=el.style.display==='none';el.style.display=h?'block':'none';this.textContent=h?'Hide raw trace':'Show collapsed frames';">
                Show collapsed frames
            </button>
            <pre id="rt" class="raw" style="display:none">{$rawTrace}</pre>
        </div>

        <script>
        document.querySelectorAll('.frame-hdr').forEach(function(h){
            h.addEventListener('click',function(){
                h.closest('.frame').classList.toggle('open');
            });
        });
        </script>
        </body>
        </html>
        HTML;
    }

    /**
     * Renderiza os frames do call stack como cards colapsáveis.
     */
    private function renderFrames(\Throwable $exception): string
    {
        $html    = '';
        $exClass = get_class($exception);
        $file    = $exception->getFile();
        $line    = $exception->getLine();

        // Frame 0 — ponto exato do erro (aberto por padrão)
        $html .= $this->makeFrame(
            fn: $exClass,
            file: $this->shortenPath($file),
            line: $line,
            rows: $this->buildCodeRows($file, $line, 4),
            open: true
        );

        foreach (array_slice($exception->getTrace(), 0, 10) as $frame) {
            $fn   = trim(($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? ''));
            $f    = $frame['file'] ?? '';
            $l    = (int) ($frame['line'] ?? 0);
            $rows = ($f && $l) ? $this->buildCodeRows($f, $l, 3) : '';

            $html .= $this->makeFrame(
                fn: $fn ?: '[anonymous]',
                file: $f ? $this->shortenPath($f) : '[internal]',
                line: $l,
                rows: $rows,
                open: false
            );
        }

        return $html;
    }

    private function makeFrame(string $fn, string $file, int $line, string $rows, bool $open): string
    {
        $cls      = $open ? ' open' : '';
        $fnE      = htmlspecialchars($fn,   ENT_QUOTES, 'UTF-8');
        $fileE    = htmlspecialchars($file, ENT_QUOTES, 'UTF-8');
        $lineStr  = $line > 0 ? ":{$line}" : '';
        $body     = $rows
            ? "<div class=\"code-body\">{$rows}</div>"
            : '<div style="padding:.6rem 1rem;font-size:.72rem;color:var(--muted)">[internal PHP function]</div>';

        return <<<HTML
        <div class="frame{$cls}">
            <div class="frame-hdr">
                <span class="frame-fn">{$fnE}</span>
                <span class="frame-file">{$fileE}{$lineStr}</span>
                <span class="frame-chev">&#9658;</span>
            </div>
            <div class="frame-body">{$body}</div>
        </div>
        HTML;
    }

    /**
     * Página genérica para produção (sem detalhes de erro).
     */
    private function renderProductionPage(): string
    {
        $appName = htmlspecialchars(env('APP_NAME', 'Slenix'), ENT_QUOTES, 'UTF-8');

        return <<<HTML
        <!DOCTYPE html>
        <html lang="pt-AO">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <title>500 — {$appName}</title>
            <style>
                *{box-sizing:border-box;margin:0;padding:0}
                body{background:#0a0a0a;color:#ededed;font-family:-apple-system,BlinkMacSystemFont,sans-serif;
                     display:flex;align-items:center;justify-content:center;min-height:100vh;text-align:center}
                h1{font-size:6rem;font-weight:800;color:#e5484d;line-height:1;letter-spacing:-.04em}
                p{margin-top:.75rem;color:#666;font-size:.9rem}
            </style>
        </head>
        <body>
            <div>
                <h1>500</h1>
                <p>Internal Server Error &mdash; something went wrong.</p>
            </div>
        </body>
        </html>
        HTML;
    }
}