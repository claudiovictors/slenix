<?php

/*
 |--------------------------------------------------------------------------
 | Luna Engine — Template Engine do Slenix Framework
 |--------------------------------------------------------------------------
 |
 | Inspirado no Blade (Laravel). Suporta layouts, seções, includes,
 | condicionais, loops, stacks e cache inteligente em disco.
 |
 | Ficheiro de template: nome.luna.php
 | Uso:  view('auth.login', ['erro' => 'Credenciais inválidas'])
 |
 | Melhorias v2.3:
 |  - {{ Olá, Mundo! }} agora funciona corretamente (texto literal com vírgulas)
 |  - Cache em disco em storage/views com TTL configurável
 |  - Sistema de logs em storage/logs/luna.log
 |  - Melhor heurística para distinguir expressão PHP de texto literal
 |
 */

declare(strict_types=1);

namespace Slenix\Supports\Template;

use RuntimeException;

class Luna
{
    // =========================================================================
    // Estado da instância
    // =========================================================================

    private string $viewPath       = '';
    private array  $data           = [];
    private array  $sections       = [];
    private array  $stacks         = [];
    private string $layout         = '';
    private string $currentSection = '';

    // =========================================================================
    // Estado estático (partilhado entre instâncias no mesmo request)
    // =========================================================================

    /** Cache de compilação em memória — evita recompilar o mesmo ficheiro duas vezes */
    private static array $memCache   = [];

    /** Variáveis globais disponíveis em todos os templates */
    private static array $globalData = [];

    /** Diretório raiz das views */
    private static string $viewsDir  = '';

    /** Diretório onde os templates compilados são guardados */
    private static string $cacheDir  = '';

    /** Diretório onde os logs são guardados */
    private static string $logsDir   = '';

    /** Cache em disco ativo? */
    private static bool $diskCache   = false;

    /** Logging ativo? */
    private static bool $logging     = false;

    /** TTL do cache em segundos (0 = sem expiração por tempo, usa mtime do ficheiro) */
    private static int $cacheTtl     = 0;

    // =========================================================================
    // Configuração
    // =========================================================================

    public static function configure(
        string $viewsDir = '',
        string $cacheDir = '',
        string $logsDir  = '',
        bool   $cache    = false,
        bool   $logging  = false,
        int    $cacheTtl = 0
    ): void {
        self::$viewsDir  = $viewsDir ?: dirname(__DIR__, 3) . '/views';
        self::$cacheDir  = $cacheDir ?: dirname(__DIR__, 3) . '/storage/views';
        self::$logsDir   = $logsDir  ?: dirname(__DIR__, 3) . '/storage/logs';
        self::$diskCache = $cache;
        self::$logging   = $logging;
        self::$cacheTtl  = $cacheTtl;
    }

    public static function share(string $key, mixed $value): void
    {
        self::$globalData[$key] = $value;
    }

    // =========================================================================
    // LOGGING
    // =========================================================================

    private static function log(string $level, string $message, array $context = []): void
    {
        if (!self::$logging) {
            return;
        }

        $dir = self::$logsDir ?: dirname(__DIR__, 3) . '/storage/logs';

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $logFile = $dir . DIRECTORY_SEPARATOR . 'luna.log';
        $date    = date('Y-m-d H:i:s');
        $ctx     = empty($context) ? '' : ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
        $line    = "[{$date}] LUNA.{$level}: {$message}{$ctx}" . PHP_EOL;

        file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }

    // =========================================================================
    // CACHE
    // =========================================================================

    /**
     * Apaga o cache em memória e em disco.
     * Chamado por: php celestial view:clear
     */
    public static function clearCache(): int
    {
        self::$memCache = [];

        if (!is_dir(self::$cacheDir)) {
            return 0;
        }

        $deleted = 0;
        foreach (glob(self::$cacheDir . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
            unlink($file);
            $deleted++;
        }

        self::log('INFO', "Cache limpo: {$deleted} ficheiro(s) removido(s)");
        return $deleted;
    }

    /**
     * Retorna estatísticas do cache em disco.
     */
    public static function cacheStats(): array
    {
        $files = glob(self::$cacheDir . DIRECTORY_SEPARATOR . '*.php') ?: [];
        $size  = 0;

        foreach ($files as $file) {
            $size += filesize($file) ?: 0;
        }

        return [
            'files'      => count($files),
            'size_bytes' => $size,
            'size_human' => self::formatBytes($size),
            'directory'  => self::$cacheDir,
        ];
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
        if ($bytes >= 1024)    return round($bytes / 1024, 2) . ' KB';
        return $bytes . ' B';
    }

    // =========================================================================
    // Construtor
    // =========================================================================

    public function __construct(string $template, array $data = [])
    {
        if (self::$viewsDir === '') {
            self::configure();
        }

        $relativePath   = str_replace('.', DIRECTORY_SEPARATOR, $template) . '.luna.php';
        $this->viewPath = self::$viewsDir . DIRECTORY_SEPARATOR . $relativePath;
        $this->data     = array_merge(self::$globalData, $data);
    }

    // =========================================================================
    // Render
    // =========================================================================

    public function render(): string
    {
        if (!file_exists($this->viewPath)) {
            self::log('ERROR', "View não encontrada: {$this->viewPath}");
            throw new RuntimeException(
                "View não encontrada: [{$this->viewPath}]\n" .
                "Verifica se o ficheiro existe e tem extensão .luna.php"
            );
        }

        $compiled = $this->getCompiled($this->viewPath);
        $output   = $this->evaluate($compiled, $this->data);

        // Se o template declarou @extends, renderiza o layout agora
        if ($this->layout !== '') {
            $layoutEngine           = new self($this->layout, $this->data);
            $layoutEngine->sections = $this->sections;
            $layoutEngine->stacks   = $this->stacks;
            return $layoutEngine->render();
        }

        return $output;
    }

    // =========================================================================
    // GESTÃO DE CACHE
    // =========================================================================

    private function getCompiled(string $viewPath): string
    {
        // Em APP_DEBUG=true nunca usa cache — template sempre fresco
        $isDebug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';

        // 1) Cache em memória (sem I/O, mesmo request)
        if (!$isDebug && isset(self::$memCache[$viewPath])) {
            self::log('DEBUG', "Cache memória HIT: {$viewPath}");
            return self::$memCache[$viewPath];
        }

        // 2) Cache em disco — válido se mais recente que o ficheiro fonte
        if (!$isDebug && self::$diskCache) {
            $cachePath = $this->buildCachePath($viewPath);

            if (file_exists($cachePath) && $this->isCacheValid($cachePath, $viewPath)) {
                $compiled = (string) file_get_contents($cachePath);
                self::$memCache[$viewPath] = $compiled;
                self::log('DEBUG', "Cache disco HIT: {$cachePath}");
                return $compiled;
            }
        }

        // 3) Compilar do zero
        self::log('DEBUG', "Compilando template: {$viewPath}");
        $source   = (string) file_get_contents($viewPath);
        $compiled = $this->compile($source);

        if (!$isDebug && self::$diskCache) {
            $cachePath = $this->buildCachePath($viewPath);
            $this->saveCacheToDisk($cachePath, $compiled, $viewPath);
        }

        self::$memCache[$viewPath] = $compiled;
        return $compiled;
    }

    /**
     * Verifica se o cache em disco ainda é válido.
     * Considera mtime do ficheiro fonte e TTL configurado.
     */
    private function isCacheValid(string $cachePath, string $viewPath): bool
    {
        $cacheMtime = (int) filemtime($cachePath);
        $viewMtime  = (int) filemtime($viewPath);

        // Cache mais antigo que o template fonte → inválido
        if ($cacheMtime < $viewMtime) {
            return false;
        }

        // TTL configurado → verifica expiração por tempo
        if (self::$cacheTtl > 0) {
            return (time() - $cacheMtime) < self::$cacheTtl;
        }

        return true;
    }

    private function buildCachePath(string $viewPath): string
    {
        return self::$cacheDir . DIRECTORY_SEPARATOR . sha1($viewPath) . '.php';
    }

    private function saveCacheToDisk(string $cachePath, string $compiled, string $viewPath): void
    {
        $dir = dirname($cachePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Cabeçalho com metadados para diagnóstico
        $header = "<?php /* Luna Cache | Source: {$viewPath} | Generated: " . date('Y-m-d H:i:s') . " */ ?>\n";
        $result = file_put_contents($cachePath, $header . $compiled, LOCK_EX);

        if ($result === false) {
            self::log('WARNING', "Falha ao gravar cache em disco: {$cachePath}");
        } else {
            self::log('INFO', "Cache gravado: {$cachePath}");
        }
    }

    // =========================================================================
    // AVALIAÇÃO
    // =========================================================================

    private function evaluate(string $compiled, array $data): string
    {
        extract($data, EXTR_SKIP);
        $__engine = $this;

        ob_start();
        try {
            // phpcs:ignore Squiz.PHP.Eval.Discouraged
            eval('?>' . $compiled);
        } catch (\Throwable $e) {
            ob_end_clean();

            // Em debug, mostra o template compilado com número de linhas
            $hint = '';
            if (($_ENV['APP_DEBUG'] ?? 'false') === 'true') {
                $lines    = explode("\n", $compiled);
                $numbered = array_map(
                    fn($l, $i) => sprintf('%4d | %s', $i + 1, $l),
                    $lines,
                    array_keys($lines)
                );
                $hint = "\n\n--- Template compilado ---\n" . implode("\n", $numbered);
            }

            self::log('ERROR', "Erro ao avaliar template [{$this->viewPath}]: " . $e->getMessage());

            throw new RuntimeException(
                "Erro ao avaliar template [{$this->viewPath}]: " . $e->getMessage() . $hint,
                0,
                $e
            );
        }

        return ob_get_clean() ?: '';
    }

    // =========================================================================
    // COMPILADOR
    // =========================================================================

    private function compile(string $source): string
    {
        /*
         * ORDEM CRÍTICA — as diretivas @ são compiladas ANTES dos echos {{ }}.
         *
         * Fluxo correto:
         *  1. Remove comentários {# #}
         *  2. Compila diretivas @ → blocos <?php ... ?>
         *  3. Compila {!! !!} → echo sem escape
         *  4. Compila {{ }} → echo com htmlspecialchars
         */
        $source = $this->compileComments($source);
        $source = $this->compileDirectives($source);
        $source = $this->compileRawEchos($source);
        $source = $this->compileEscapedEchos($source);

        return $source;
    }

    // =========================================================================
    // COMENTÁRIOS
    // =========================================================================

    private function compileComments(string $source): string
    {
        return preg_replace('/\{#[\s\S]*?#\}/s', '', $source) ?? $source;
    }

    // =========================================================================
    // ECHOS
    // =========================================================================

    /** {!! expr !!} — sem escape HTML */
    private function compileRawEchos(string $source): string
    {
        return preg_replace_callback(
            '/\{!!\s*(.+?)\s*!!\}/s',
            fn(array $m) => '<?php echo ' . trim($m[1]) . '; ?>',
            $source
        ) ?? $source;
    }

    /**
     * {{ expr }} — com htmlspecialchars
     *
     * Distingue expressão PHP de texto literal.
     *
     * ✅ {{ $variavel }}           → PHP expression
     * ✅ {{ strtoupper($nome) }}   → PHP expression
     * ✅ {{ Olá, Mundo! }}         → texto literal
     * ✅ {{ Login - Form }}        → texto literal
     * ✅ {{ "string literal" }}    → string PHP quoted
     * ✅ {{ 'string literal' }}    → string PHP quoted
     */
    private function compileEscapedEchos(string $source): string
    {
        return preg_replace_callback(
            '/\{\{\s*(.+?)\s*\}\}/s',
            function (array $m): string {
                $expr = trim($m[1]);

                if ($this->isPhpExpression($expr)) {
                    return "<?php echo htmlspecialchars((string)(({$expr}) ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>";
                }

                // Texto literal — escapa e emite como string estática
                $safe = addslashes($expr);
                return "<?php echo htmlspecialchars('{$safe}', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>";
            },
            $source
        ) ?? $source;
    }

    /**
     * Heurística robusta para distinguir expressão PHP de texto literal dentro de {{ }}.
     *
     * Regras (em ordem de prioridade):
     *
     * PHP:
     *  - Começa com $ → variável ($foo, $foo->bar, etc.)
     *  - Começa com aspas simples ou duplas → string PHP quoted
     *  - Contém chamada de função → algo(
     *  - Contém :: → acesso estático
     *  - Contém -> → acesso de propriedade/método
     *  - Contém [ → acesso de array
     *  - Contém operadores aritméticos/lógicos EXCETO hífen entre palavras
     *  - É um literal PHP puro: true, false, null
     *  - É um número puro
     *
     * TEXTO LITERAL (não PHP):
     *  - Qualquer outra coisa: "Olá, Mundo!", "Login - Form", etc.
     */
    private function isPhpExpression(string $expr): bool
    {
        $trimmed = ltrim($expr);

        // Variável PHP: $foo, $foo->bar, etc.
        if (str_starts_with($trimmed, '$')) {
            return true;
        }

        // String PHP com aspas — "texto" ou 'texto'
        if (
            (str_starts_with($trimmed, '"') && str_ends_with($trimmed, '"')) ||
            (str_starts_with($trimmed, "'") && str_ends_with($trimmed, "'"))
        ) {
            return true;
        }

        // Chamada de função/método: algo(
        if (preg_match('/\b[a-zA-Z_]\w*\s*\(/', $expr)) {
            return true;
        }

        // Acesso estático (::), propriedade (->), índice de array ([)
        if (str_contains($expr, '::') || str_contains($expr, '->') || str_contains($expr, '[')) {
            return true;
        }

        // Operadores aritméticos/lógicos — ignora:
        //  - Hífen entre letras/dígitos (ex: "Login-Form", "bem-vindo")
        //  - Ponto de exclamação no início/fim de palavras (ex: "Olá, Mundo!")
        //  - Vírgula (ex: "Olá, Mundo!")
        $stripped = preg_replace([
            '/(?<=[\w\x{00C0}-\x{024F}])-(?=[\w\x{00C0}-\x{024F}])/u', // hífen entre palavras (incluindo acentuados)
            '/!(?=$|\s)/u',                                               // ! no fim de frase
            '/,/',                                                        // vírgulas
            '/\s+/',                                                      // espaços
        ], '', $expr);

        // Agora verifica operadores que sobram
        if (preg_match('/[+\*\/%=<>&|^~?]/', $stripped ?? $expr)) {
            return true;
        }

        // Literais PHP puros
        if (preg_match('/^(true|false|null)$/i', trim($expr))) {
            return true;
        }

        // Número puro
        if (is_numeric(trim($expr))) {
            return true;
        }

        return false;
    }

    // =========================================================================
    // DIRETIVAS
    // =========================================================================

    private function compileDirectives(string $source): string
    {
        $source = $this->compileLayouts($source);
        $source = $this->compileSections($source);
        $source = $this->compileConditionals($source);
        $source = $this->compileLoops($source);
        $source = $this->compileIncludes($source);
        $source = $this->compileStacks($source);
        $source = $this->compileMisc($source);
        return $source;
    }

    // ---- @extends -----------------------------------------------------------

    private function compileLayouts(string $source): string
    {
        return preg_replace_callback(
            '/@extends\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/',
            fn(array $m) => "<?php \$__engine->setLayout('" . addslashes($m[1]) . "'); ?>",
            $source
        ) ?? $source;
    }

    // ---- @section / @yield / @endsection ------------------------------------

    private function compileSections(string $source): string
    {
        // @section('name', 'valor inline')
        $source = preg_replace_callback(
            '/@section\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"]([^\'"]*)[\'"]\s*\)/',
            function (array $m): string {
                $name    = addslashes($m[1]);
                $content = addslashes($m[2]);
                return "<?php \$__engine->startSection('{$name}'); echo '{$content}'; \$__engine->endSection(); ?>";
            },
            $source
        ) ?? $source;

        // @section('name')
        $source = preg_replace_callback(
            '/@section\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/',
            fn(array $m) => "<?php \$__engine->startSection('" . addslashes($m[1]) . "'); ?>",
            $source
        ) ?? $source;

        // @endsection / @show
        $source = preg_replace('/@endsection\b|@show\b/', '<?php $__engine->endSection(); ?>', $source) ?? $source;

        // @yield('name') ou @yield('name', 'default')
        $source = preg_replace_callback(
            '/@yield\s*\(\s*[\'"]([^\'"]+)[\'"]\s*(?:,\s*[\'"]([^\'"]*)[\'"]\s*)?\)/',
            function (array $m): string {
                $name    = addslashes($m[1]);
                $default = addslashes($m[2] ?? '');
                return "<?php echo \$__engine->yieldSection('{$name}', '{$default}'); ?>";
            },
            $source
        ) ?? $source;

        // @hasSection / @sectionMissing
        $source = preg_replace_callback(
            '/@hasSection\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/',
            fn(array $m) => "<?php if(\$__engine->hasSection('" . addslashes($m[1]) . "')): ?>",
            $source
        ) ?? $source;

        $source = preg_replace_callback(
            '/@sectionMissing\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/',
            fn(array $m) => "<?php if(!\$__engine->hasSection('" . addslashes($m[1]) . "')): ?>",
            $source
        ) ?? $source;

        return $source;
    }

    // ---- Condicionais --------------------------------------------------------

    private function compileConditionals(string $source): string
    {
        $source = preg_replace_callback(
            '/@if\s*\((.+?)\)\s*$/m',
            fn(array $m) => '<?php if(' . trim($m[1]) . '): ?>',
            $source
        ) ?? $source;

        $source = preg_replace_callback(
            '/@elseif\s*\((.+?)\)\s*$/m',
            fn(array $m) => '<?php elseif(' . trim($m[1]) . '): ?>',
            $source
        ) ?? $source;

        $source = preg_replace('/@else\b/',  '<?php else: ?>',  $source) ?? $source;
        $source = preg_replace('/@endif\b/', '<?php endif; ?>', $source) ?? $source;

        // @isset / @empty / @unless
        $source = preg_replace_callback(
            '/@isset\s*\((.+?)\)\s*$/m',
            fn(array $m) => '<?php if(isset(' . trim($m[1]) . ')): ?>',
            $source
        ) ?? $source;
        $source = preg_replace('/@endisset\b/', '<?php endif; ?>', $source) ?? $source;

        $source = preg_replace_callback(
            '/@unless\s*\((.+?)\)\s*$/m',
            fn(array $m) => '<?php if(!(' . trim($m[1]) . ')): ?>',
            $source
        ) ?? $source;
        $source = preg_replace('/@endunless\b/', '<?php endif; ?>', $source) ?? $source;

        // @switch / @case / @default / @endswitch
        $source = preg_replace_callback(
            '/@switch\s*\((.+?)\)\s*$/m',
            fn(array $m) => '<?php switch(' . trim($m[1]) . '): ?>',
            $source
        ) ?? $source;
        $source = preg_replace_callback(
            '/@case\s*\((.+?)\)\s*$/m',
            fn(array $m) => '<?php case ' . trim($m[1]) . ': ?>',
            $source
        ) ?? $source;
        $source = preg_replace('/@default\b/',   '<?php default: ?>',   $source) ?? $source;
        $source = preg_replace('/@endswitch\b/', '<?php endswitch; ?>', $source) ?? $source;
        $source = preg_replace('/@break\b/',     '<?php break; ?>',     $source) ?? $source;

        // @auth / @guest
        $source = preg_replace('/@auth\b/',     "<?php if(!empty(\$_SESSION['user_id'])): ?>", $source) ?? $source;
        $source = preg_replace('/@endauth\b/',  '<?php endif; ?>',                             $source) ?? $source;
        $source = preg_replace('/@guest\b/',    "<?php if(empty(\$_SESSION['user_id'])): ?>",  $source) ?? $source;
        $source = preg_replace('/@endguest\b/', '<?php endif; ?>',                             $source) ?? $source;

        // @env / @production / @debug
        $source = preg_replace_callback(
            '/@env\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/',
            fn(array $m) => "<?php if((\$_ENV['APP_ENV'] ?? '') === '" . addslashes($m[1]) . "'): ?>",
            $source
        ) ?? $source;
        $source = preg_replace('/@endenv\b/', '<?php endif; ?>', $source) ?? $source;

        $source = preg_replace('/@production\b/',    "<?php if((\$_ENV['APP_ENV'] ?? '') === 'production'): ?>", $source) ?? $source;
        $source = preg_replace('/@endproduction\b/', '<?php endif; ?>',                                         $source) ?? $source;
        $source = preg_replace('/@debug\b/',         "<?php if((\$_ENV['APP_DEBUG'] ?? '') === 'true'): ?>",    $source) ?? $source;
        $source = preg_replace('/@enddebug\b/',      '<?php endif; ?>',                                        $source) ?? $source;

        return $source;
    }

    // ---- Loops ---------------------------------------------------------------

    private function compileLoops(string $source): string
    {
        // @foreach — disponibiliza $loop->index, $loop->first, $loop->iteration
        $source = preg_replace_callback(
            '/@foreach\s*\((.+?)\)\s*$/m',
            fn(array $m) =>
                '<?php $loop = (object)["index"=>0,"iteration"=>1,"first"=>true,"last"=>false]; ' .
                'foreach(' . trim($m[1]) . '): ' .
                '$loop->first = ($loop->index === 0); ?>',
            $source
        ) ?? $source;

        $source = preg_replace(
            '/@endforeach\b/',
            '<?php $loop->index++; $loop->iteration++; $loop->first = false; endforeach; ?>',
            $source
        ) ?? $source;

        // @for / @while
        $source = preg_replace_callback(
            '/@for\s*\((.+?)\)\s*$/m',
            fn(array $m) => '<?php for(' . trim($m[1]) . '): ?>',
            $source
        ) ?? $source;
        $source = preg_replace('/@endfor\b/', '<?php endfor; ?>', $source) ?? $source;

        $source = preg_replace_callback(
            '/@while\s*\((.+?)\)\s*$/m',
            fn(array $m) => '<?php while(' . trim($m[1]) . '): ?>',
            $source
        ) ?? $source;
        $source = preg_replace('/@endwhile\b/', '<?php endwhile; ?>', $source) ?? $source;

        // @forelse / @empty / @endforelse
        $source = preg_replace_callback(
            '/@forelse\s*\((.+?)\s+as\s+(.+?)\)\s*$/m',
            fn(array $m) =>
                '<?php $__items=' . trim($m[1]) . '; ' .
                'if(!empty($__items)): foreach($__items as ' . trim($m[2]) . '): ?>',
            $source
        ) ?? $source;
        $source = preg_replace('/@empty\s*$/m',   '<?php endforeach; else: ?>', $source) ?? $source;
        $source = preg_replace('/@endforelse\b/', '<?php endif; ?>',            $source) ?? $source;

        // @continue / @break
        $source = preg_replace_callback(
            '/@continue\s*\((.+?)\)/',
            fn(array $m) => '<?php if(' . trim($m[1]) . ') continue; ?>',
            $source
        ) ?? $source;
        $source = preg_replace('/@continue\b/', '<?php continue; ?>', $source) ?? $source;

        $source = preg_replace_callback(
            '/@break\s*\((.+?)\)/',
            fn(array $m) => '<?php if(' . trim($m[1]) . ') break; ?>',
            $source
        ) ?? $source;

        return $source;
    }

    // ---- Includes ------------------------------------------------------------

    private function compileIncludes(string $source): string
    {
        $source = preg_replace_callback(
            '/@include\s*\(\s*[\'"]([^\'"]+)[\'"]\s*(?:,\s*(\[[\s\S]*?\]))?\s*\)/',
            function (array $m): string {
                return "<?php echo \$__engine->renderInclude('" . addslashes($m[1]) . "', " . ($m[2] ?? '[]') . "); ?>";
            },
            $source
        ) ?? $source;

        $source = preg_replace_callback(
            '/@includeIf\s*\(\s*[\'"]([^\'"]+)[\'"]\s*(?:,\s*(\[[\s\S]*?\]))?\s*\)/',
            function (array $m): string {
                return "<?php echo \$__engine->renderIncludeIf('" . addslashes($m[1]) . "', " . ($m[2] ?? '[]') . "); ?>";
            },
            $source
        ) ?? $source;

        $source = preg_replace_callback(
            '/@includeWhen\s*\(\s*(.+?)\s*,\s*[\'"]([^\'"]+)[\'"]\s*(?:,\s*(\[[\s\S]*?\]))?\s*\)/',
            function (array $m): string {
                return "<?php if({$m[1]}): echo \$__engine->renderInclude('" . addslashes($m[2]) . "', " . ($m[3] ?? '[]') . "); endif; ?>";
            },
            $source
        ) ?? $source;

        $source = preg_replace_callback(
            '/@each\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*(.+?)\s*,\s*[\'"]([^\'"]+)[\'"]\s*(?:,\s*[\'"]([^\'"]+)[\'"]\s*)?\)/',
            function (array $m): string {
                $empty = isset($m[4]) ? "'" . addslashes($m[4]) . "'" : "''";
                return "<?php echo \$__engine->renderEach('" . addslashes($m[1]) . "', {$m[2]}, '" . addslashes($m[3]) . "', {$empty}); ?>";
            },
            $source
        ) ?? $source;

        return $source;
    }

    // ---- @push / @stack -----------------------------------------------------

    private function compileStacks(string $source): string
    {
        $source = preg_replace_callback(
            '/@push\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/',
            fn(array $m) => "<?php \$__engine->startPush('" . addslashes($m[1]) . "'); ?>",
            $source
        ) ?? $source;
        $source = preg_replace('/@endpush\b/', '<?php $__engine->endPush(); ?>', $source) ?? $source;

        $source = preg_replace_callback(
            '/@prepend\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/',
            fn(array $m) => "<?php \$__engine->startPrepend('" . addslashes($m[1]) . "'); ?>",
            $source
        ) ?? $source;
        $source = preg_replace('/@endprepend\b/', '<?php $__engine->endPrepend(); ?>', $source) ?? $source;

        $source = preg_replace_callback(
            '/@stack\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/',
            fn(array $m) => "<?php echo \$__engine->renderStack('" . addslashes($m[1]) . "'); ?>",
            $source
        ) ?? $source;

        return $source;
    }

    // ---- Miscelânea ----------------------------------------------------------

    private function compileMisc(string $source): string
    {
        // @php ... @endphp
        $source = preg_replace('/@php\b([\s\S]*?)@endphp\b/', '<?php $1 ?>', $source) ?? $source;

        // @csrf — usa helper global csrf_field() definido em Helpers.php
        $source = str_replace('@csrf', '<?php echo csrf_field(); ?>', $source);

        // @method('PUT') — campo oculto para simular métodos HTTP
        $source = preg_replace_callback(
            '/@method\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/',
            fn(array $m) => '<input type="hidden" name="_method" value="' . strtoupper($m[1]) . '">',
            $source
        ) ?? $source;

        // @json($var)
        $source = preg_replace_callback(
            '/@json\s*\(\s*(.+?)\s*\)/',
            fn(array $m) => "<?php echo json_encode({$m[1]}, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>",
            $source
        ) ?? $source;

        // @class(['active' => $bool, 'hidden' => false])
        $source = preg_replace_callback(
            '/@class\s*\(\s*(\[[\s\S]*?\])\s*\)/',
            fn(array $m) => "<?php echo implode(' ', array_keys(array_filter({$m[1]}))); ?>",
            $source
        ) ?? $source;

        // @dump($var) / @dd($var)
        $source = preg_replace_callback(
            '/@dump\s*\(\s*(.+?)\s*\)/',
            fn(array $m) => "<?php dump({$m[1]}); ?>",
            $source
        ) ?? $source;
        $source = preg_replace_callback(
            '/@dd\s*\(\s*(.+?)\s*\)/',
            fn(array $m) => "<?php dd({$m[1]}); ?>",
            $source
        ) ?? $source;

        // @asset('img/logo.png')
        $source = preg_replace_callback(
            '/@asset\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/',
            fn(array $m) => "<?php echo asset('" . addslashes($m[1]) . "'); ?>",
            $source
        ) ?? $source;

        // @route('name')
        $source = preg_replace_callback(
            '/@route\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/',
            fn(array $m) => "<?php echo route('" . addslashes($m[1]) . "'); ?>",
            $source
        ) ?? $source;

        // @old('field') / @old('field', 'default')
        $source = preg_replace_callback(
            '/@old\s*\(\s*[\'"]([^\'"]+)[\'"]\s*(?:,\s*[\'"]([^\'"]*)[\'"]\s*)?\)/',
            function (array $m): string {
                $field   = addslashes($m[1]);
                $default = addslashes($m[2] ?? '');
                return "<?php echo htmlspecialchars((string)(old('{$field}', '{$default}') ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>";
            },
            $source
        ) ?? $source;

        // @error('field') / @enderror
        $source = preg_replace_callback(
            '/@error\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/',
            function (array $m): string {
                $field = addslashes($m[1]);
                return "<?php if(has_error('{$field}')): \$message = errors('{$field}'); ?>";
            },
            $source
        ) ?? $source;
        $source = preg_replace('/@enderror\b/', '<?php endif; ?>', $source) ?? $source;

        // @vite('resources/app.js')
        $source = preg_replace_callback(
            '/@vite\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/',
            fn(array $m) => '<script type="module" src="/' . $m[1] . '"></script>',
            $source
        ) ?? $source;

        return $source;
    }

    // =========================================================================
    // API PÚBLICA — chamada pelo código compilado via $__engine
    // =========================================================================

    public function setLayout(string $layout): void    { $this->layout = $layout; }
    public function hasSection(string $name): bool     { return isset($this->sections[$name]) && $this->sections[$name] !== ''; }
    public function yieldSection(string $name, string $default = ''): string { return $this->sections[$name] ?? $default; }

    public function startSection(string $name): void
    {
        $this->currentSection = $name;
        ob_start();
    }

    public function endSection(): void
    {
        $content = ob_get_clean() ?: '';
        if ($this->currentSection === '') return;

        if (isset($this->sections[$this->currentSection])) {
            $content = str_replace('@parent', $this->sections[$this->currentSection], $content);
        }

        $this->sections[$this->currentSection] = $content;
        $this->currentSection = '';
    }

    public function startPush(string $name): void    { $this->currentSection = '__push__' . $name; ob_start(); }
    public function endPush(): void                  { $content = ob_get_clean() ?: ''; $name = substr($this->currentSection, 8); $this->stacks[$name][] = $content; $this->currentSection = ''; }
    public function startPrepend(string $name): void { $this->currentSection = '__prepend__' . $name; ob_start(); }
    public function endPrepend(): void               { $content = ob_get_clean() ?: ''; $name = substr($this->currentSection, 11); array_unshift($this->stacks[$name], $content); $this->currentSection = ''; }
    public function renderStack(string $name): string { return implode('', $this->stacks[$name] ?? []); }

    public function renderInclude(string $template, array $data = []): string
    {
        $engine           = new self($template, array_merge($this->data, $data));
        $engine->sections = $this->sections;
        $engine->stacks   = $this->stacks;
        return $engine->render();
    }

    public function renderIncludeIf(string $template, array $data = []): string
    {
        $path = self::$viewsDir . DIRECTORY_SEPARATOR . str_replace('.', DIRECTORY_SEPARATOR, $template) . '.luna.php';
        return file_exists($path) ? $this->renderInclude($template, $data) : '';
    }

    public function renderEach(string $template, iterable $items, string $variable, string $empty = ''): string
    {
        if (empty($items)) {
            return $empty !== '' ? $this->renderInclude($empty) : '';
        }
        $output = '';
        foreach ($items as $key => $item) {
            $output .= $this->renderInclude($template, [$variable => $item, $variable . 'Key' => $key]);
        }
        return $output;
    }
}