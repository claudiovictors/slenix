<?php

/*
 |--------------------------------------------------------------------------
 | Luna Engine — Template Engine do Slenix Framework
 |--------------------------------------------------------------------------
 |
 | Inspirado no Blade (Laravel). Suporta layouts, seções, includes,
 | condicionais, loops, stacks e cache inteligente em disco.
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

    /** @var string */
    private $viewPath = '';

    /** @var array<string, mixed> */
    private $data = [];

    /** @var array<string, string> */
    private $sections = [];

    /** @var array<string, array<int, string>> */
    private $stacks = [];

    /** @var string */
    private $layout = '';

    /** @var string */
    private $currentSection = '';

    /** @var array<string, mixed> */
    private $slots = [];

    /** @var string */
    private $currentSlot = '';

    // =========================================================================
    // Estado estático (partilhado entre instâncias no mesmo request)
    // =========================================================================

    /** @var array<string, string> Cache de compilação em memória */
    private static $memCache = [];

    /** @var array<string, mixed> Variáveis globais disponíveis em todos os templates */
    private static $globalData = [];

    /** @var string Diretório raiz das views */
    private static $viewsDir = '';

    /** @var string Diretório onde os templates compilados são guardados */
    private static $cacheDir = '';

    /** @var string Diretório onde os logs são guardados */
    private static $logsDir = '';

    /** @var bool Cache em disco ativo? */
    private static $diskCache = false;

    /** @var bool Logging ativo? */
    private static $logging = false;

    /** @var int TTL do cache em segundos (0 = sem expiração por tempo, usa mtime) */
    private static $cacheTtl = 0;

    /** @var array<string, bool> Controlo de blocos @once já renderizados */
    private static $onceSections = [];

    /** @var array<string, callable> Diretivas personalizadas registadas */
    private static $customDirectives = [];

    /** @var array<string, string> Traduções registadas */
    private static $translations = [];

    /** @var string Locale ativo para traduções */
    private static $locale = 'pt';

    /** @var callable|null Resolver de dependências para @inject */
    private static $container = null;

    // =========================================================================
    // Configuração
    // =========================================================================

    /**
     * Configura o motor Luna.
     *
     * @param string $viewsDir Diretório das views
     * @param string $cacheDir Diretório do cache
     * @param string $logsDir  Diretório dos logs
     * @param bool   $cache    Ativar cache em disco
     * @param bool   $logging  Ativar logging
     * @param int    $cacheTtl TTL do cache em segundos
     */
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

    /**
     * Partilha uma variável global com todos os templates.
     *
     * @param string $key
     * @param mixed  $value
     */
    public static function share(string $key, $value): void
    {
        self::$globalData[$key] = $value;
    }

    /**
     * Regista uma diretiva personalizada.
     *
     * Exemplo:
     *   Luna::directive('money', fn($expr) => "<?php echo number_format($expr, 2, ',', '.'); ?>");
     *   No template: @money($preco)
     *
     * @param string   $name     Nome da diretiva (sem @)
     * @param callable $callback Recebe o conteúdo entre parênteses, retorna PHP
     */
    public static function directive(string $name, callable $callback): void
    {
        self::$customDirectives[$name] = $callback;
    }

    /**
     * Define as traduções disponíveis.
     *
     * @param array<string, string> $translations Mapa chave → texto
     * @param string                $locale       Locale (ex: 'pt', 'en')
     */
    public static function setTranslations(array $translations, string $locale = 'pt'): void
    {
        self::$translations = $translations;
        self::$locale       = $locale;
    }

    /**
     * Define o container de injeção de dependências para @inject.
     *
     * @param callable $resolver fn(string $abstract): mixed
     */
    public static function setContainer(callable $resolver): void
    {
        self::$container = $resolver;
    }

    // =========================================================================
    // LOGGING
    // =========================================================================

    /**
     * @param array<string, mixed> $context
     */
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
        self::$memCache    = [];
        self::$onceSections = [];

        if (!is_dir(self::$cacheDir)) {
            return 0;
        }

        $deleted = 0;
        $pattern = self::$cacheDir . DIRECTORY_SEPARATOR . '*.php';
        foreach (glob($pattern) ?: [] as $file) {
            unlink($file);
            $deleted++;
        }

        self::log('INFO', "Cache limpo: {$deleted} ficheiro(s) removido(s)");
        return $deleted;
    }

    /**
     * Retorna estatísticas do cache em disco.
     *
     * @return array<string, mixed>
     */
    public static function cacheStats(): array
    {
        $files = glob(self::$cacheDir . DIRECTORY_SEPARATOR . '*.php') ?: [];
        $size  = 0;

        foreach ($files as $file) {
            $size += (int) filesize($file);
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
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' B';
    }

    // =========================================================================
    // Construtor
    // =========================================================================

    /**
     * @param string               $template Caminho com pontos (ex: 'auth.login')
     * @param array<string, mixed> $data     Dados passados ao template
     */
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
        $isDebug = (isset($_ENV['APP_DEBUG']) && $_ENV['APP_DEBUG'] === 'true');

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
     */
    private function isCacheValid(string $cachePath, string $viewPath): bool
    {
        $cacheMtime = (int) filemtime($cachePath);
        $viewMtime  = (int) filemtime($viewPath);

        if ($cacheMtime < $viewMtime) {
            return false;
        }

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

    /**
     * @param array<string, mixed> $data
     */
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

            $hint = '';
            if (isset($_ENV['APP_DEBUG']) && $_ENV['APP_DEBUG'] === 'true') {
                $lines    = explode("\n", $compiled);
                $numbered = array_map(
                    function ($l, $i) { return sprintf('%4d | %s', $i + 1, $l); },
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
         * ORDEM CRÍTICA:
         *  1. Extrai blocos @verbatim (para não compilar o seu interior)
         *  2. Remove comentários {{-- --}} e {# #}
         *  3. Compila diretivas @ → blocos <?php ... ?>
         *  4. Compila {!! !!} → echo sem escape
         *  5. Compila {{ }} → echo com htmlspecialchars
         *  6. Restaura blocos @verbatim
         */
        [$source, $verbatimPlaceholders] = $this->extractVerbatim($source);
        $source = $this->compileComments($source);
        $source = $this->compileDirectives($source);
        $source = $this->compileRawEchos($source);
        $source = $this->compileEscapedEchos($source);
        $source = $this->restoreVerbatim($source, $verbatimPlaceholders);

        return $source;
    }

    // =========================================================================
    // VERBATIM
    // =========================================================================

    /**
     * Extrai blocos @verbatim preservando o seu conteúdo original.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function extractVerbatim(string $source): array
    {
        $placeholders = [];
        $index        = 0;

        $source = preg_replace_callback(
            '/@verbatim([\s\S]*?)@endverbatim/s',
            function (array $m) use (&$placeholders, &$index): string {
                $key                = '__VERBATIM_' . $index++ . '__';
                $placeholders[$key] = $m[1];
                return $key;
            },
            $source
        ) ?? $source;

        return [$source, $placeholders];
    }

    /**
     * @param array<string, string> $placeholders
     */
    private function restoreVerbatim(string $source, array $placeholders): string
    {
        return str_replace(array_keys($placeholders), array_values($placeholders), $source);
    }

    // =========================================================================
    // COMENTÁRIOS
    // =========================================================================

    private function compileComments(string $source): string
    {
        // Comentários Blade-style: {{-- ... --}} (inline e multiline)
        $source = preg_replace('/\{\{--[\s\S]*?--\}\}/s', '', $source) ?? $source;

        // Comentários Luna-style: {# ... #} (retrocompatibilidade)
        $source = preg_replace('/\{#[\s\S]*?#\}/s', '', $source) ?? $source;

        return $source;
    }

    // =========================================================================
    // ECHOS
    // =========================================================================

    /** {!! expr !!} — sem escape HTML */
    private function compileRawEchos(string $source): string
    {
        return preg_replace_callback(
            '/\{!!\s*(.+?)\s*!!\}/s',
            function (array $m): string {
                return '<?php echo ' . trim($m[1]) . '; ?>';
            },
            $source
        ) ?? $source;
    }

    /**
     * {{ expr }} — com htmlspecialchars
     *
     * Distingue expressão PHP de texto literal.
     *
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
     */
    private function isPhpExpression(string $expr): bool
    {
        $trimmed = ltrim($expr);

        // Variável PHP: $foo, $foo->bar, etc.
        if ($trimmed !== '' && $trimmed[0] === '$') {
            return true;
        }

        // String PHP com aspas — "texto" ou 'texto'
        $len = strlen($trimmed);
        if ($len >= 2) {
            if (
                ($trimmed[0] === '"' && $trimmed[$len - 1] === '"') ||
                ($trimmed[0] === "'" && $trimmed[$len - 1] === "'")
            ) {
                return true;
            }
        }

        // Chamada de função/método: algo(
        if (preg_match('/\b[a-zA-Z_]\w*\s*\(/', $expr)) {
            return true;
        }

        // Acesso estático (::), propriedade (->), índice de array ([)
        if (strpos($expr, '::') !== false || strpos($expr, '->') !== false || strpos($expr, '[') !== false) {
            return true;
        }

        // Remove hífens entre palavras, pontuação de frase e espaços para
        // verificar operadores que sobram
        $stripped = preg_replace([
            '/(?<=[\w\x{00C0}-\x{024F}])-(?=[\w\x{00C0}-\x{024F}])/u',
            '/!(?=$|\s)/u',
            '/,/',
            '/\s+/',
        ], '', $expr);

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
        $source = $this->compileComponents($source);
        $source = $this->compileI18n($source);
        $source = $this->compileOnce($source);
        $source = $this->compileAttributes($source);
        $source = $this->compileProps($source);
        $source = $this->compileInject($source);
        $source = $this->compileMisc($source);
        $source = $this->compileCustomDirectives($source);
        return $source;
    }

    // ---- @extends -----------------------------------------------------------

    private function compileLayouts(string $source): string
    {
        return preg_replace_callback(
            '/@extends\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/',
            function (array $m): string {
                return "<?php \$__engine->setLayout('" . addslashes($m[1]) . "'); ?>";
            },
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
            function (array $m): string {
                return "<?php \$__engine->startSection('" . addslashes($m[1]) . "'); ?>";
            },
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
            function (array $m): string {
                return "<?php if(\$__engine->hasSection('" . addslashes($m[1]) . "')): ?>";
            },
            $source
        ) ?? $source;

        $source = preg_replace_callback(
            '/@sectionMissing\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/',
            function (array $m): string {
                return "<?php if(!\$__engine->hasSection('" . addslashes($m[1]) . "')): ?>";
            },
            $source
        ) ?? $source;

        return $source;
    }

    // ---- Condicionais --------------------------------------------------------

    private function compileConditionals(string $source): string
    {
        $source = preg_replace_callback(
            '/@if\s*\((.+?)\)\s*$/m',
            function (array $m): string { return '<?php if(' . trim($m[1]) . '): ?>'; },
            $source
        ) ?? $source;

        $source = preg_replace_callback(
            '/@elseif\s*\((.+?)\)\s*$/m',
            function (array $m): string { return '<?php elseif(' . trim($m[1]) . '): ?>'; },
            $source
        ) ?? $source;

        $source = preg_replace('/@else\b/',  '<?php else: ?>',  $source) ?? $source;
        $source = preg_replace('/@endif\b/', '<?php endif; ?>', $source) ?? $source;

        // @isset / @endisset
        $source = preg_replace_callback(
            '/@isset\s*\((.+?)\)\s*$/m',
            function (array $m): string { return '<?php if(isset(' . trim($m[1]) . ')): ?>'; },
            $source
        ) ?? $source;
        $source = preg_replace('/@endisset\b/', '<?php endif; ?>', $source) ?? $source;

        // @empty / @endempty
        $source = preg_replace_callback(
            '/@empty\s*\((.+?)\)\s*$/m',
            function (array $m): string { return '<?php if(empty(' . trim($m[1]) . ')): ?>'; },
            $source
        ) ?? $source;
        $source = preg_replace('/@endempty\b/', '<?php endif; ?>', $source) ?? $source;

        // @unless / @endunless
        $source = preg_replace_callback(
            '/@unless\s*\((.+?)\)\s*$/m',
            function (array $m): string { return '<?php if(!(' . trim($m[1]) . ')): ?>'; },
            $source
        ) ?? $source;
        $source = preg_replace('/@endunless\b/', '<?php endif; ?>', $source) ?? $source;

        // @switch / @case / @default / @endswitch
        $source = preg_replace_callback(
            '/@switch\s*\((.+?)\)\s*$/m',
            function (array $m): string { return '<?php switch(' . trim($m[1]) . '): ?>'; },
            $source
        ) ?? $source;
        $source = preg_replace_callback(
            '/@case\s*\((.+?)\)\s*$/m',
            function (array $m): string { return '<?php case ' . trim($m[1]) . ': ?>'; },
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
            function (array $m): string {
                return "<?php if((isset(\$_ENV['APP_ENV']) ? \$_ENV['APP_ENV'] : '') === '" . addslashes($m[1]) . "'): ?>";
            },
            $source
        ) ?? $source;
        $source = preg_replace('/@endenv\b/', '<?php endif; ?>', $source) ?? $source;

        $source = preg_replace(
            '/@production\b/',
            "<?php if((isset(\$_ENV['APP_ENV']) ? \$_ENV['APP_ENV'] : '') === 'production'): ?>",
            $source
        ) ?? $source;
        $source = preg_replace('/@endproduction\b/', '<?php endif; ?>', $source) ?? $source;

        $source = preg_replace(
            '/@debug\b/',
            "<?php if((isset(\$_ENV['APP_DEBUG']) ? \$_ENV['APP_DEBUG'] : '') === 'true'): ?>",
            $source
        ) ?? $source;
        $source = preg_replace('/@enddebug\b/', '<?php endif; ?>', $source) ?? $source;

        return $source;
    }

    // ---- Loops ---------------------------------------------------------------

    private function compileLoops(string $source): string
    {
        /*
         * @foreach melhorado — $loop agora inclui:
         *   ->index       (0-based)
         *   ->iteration   (1-based)
         *   ->count       total de itens
         *   ->first       bool
         *   ->last        bool
         *   ->depth       profundidade de aninhamento (1 = raiz)
         *   ->parent      referência ao $loop pai (ou null)
         */
        $source = preg_replace_callback(
            '/@foreach\s*\((.+?)\)\s*$/m',
            function (array $m): string {
                $expr = trim($m[1]);
                return
                    '<?php ' .
                    '$__items = ' . $this->extractIterableFromForeach($expr) . '; ' .
                    '$__parentLoop = isset($loop) ? $loop : null; ' .
                    '$__depth = isset($__depth) ? $__depth + 1 : 1; ' .
                    '$loop = (object)["index"=>0,"iteration"=>1,"count"=>count((array)$__items),"first"=>true,"last"=>false,"depth"=>$__depth,"parent"=>$__parentLoop]; ' .
                    'foreach(' . $expr . '): ' .
                    '$loop->first = ($loop->index === 0); ' .
                    '$loop->last  = ($loop->index === $loop->count - 1); ' .
                    '?>';
            },
            $source
        ) ?? $source;

        $source = preg_replace(
            '/@endforeach\b/',
            '<?php $loop->index++; $loop->iteration++; $loop->first = false; endforeach; $__depth = max(1, $__depth - 1); $loop = $__parentLoop; ?>',
            $source
        ) ?? $source;

        // @for / @while
        $source = preg_replace_callback(
            '/@for\s*\((.+?)\)\s*$/m',
            function (array $m): string { return '<?php for(' . trim($m[1]) . '): ?>'; },
            $source
        ) ?? $source;
        $source = preg_replace('/@endfor\b/', '<?php endfor; ?>', $source) ?? $source;

        $source = preg_replace_callback(
            '/@while\s*\((.+?)\)\s*$/m',
            function (array $m): string { return '<?php while(' . trim($m[1]) . '): ?>'; },
            $source
        ) ?? $source;
        $source = preg_replace('/@endwhile\b/', '<?php endwhile; ?>', $source) ?? $source;

        // @forelse / @empty / @endforelse
        $source = preg_replace_callback(
            '/@forelse\s*\((.+?)\s+as\s+(.+?)\)\s*$/m',
            function (array $m): string {
                return '<?php $__items=' . trim($m[1]) . '; if(!empty($__items)): foreach($__items as ' . trim($m[2]) . '): ?>';
            },
            $source
        ) ?? $source;
        $source = preg_replace('/@empty\s*$/m',   '<?php endforeach; else: ?>', $source) ?? $source;
        $source = preg_replace('/@endforelse\b/', '<?php endif; ?>',            $source) ?? $source;

        // @continue / @break
        $source = preg_replace_callback(
            '/@continue\s*\((.+?)\)/',
            function (array $m): string { return '<?php if(' . trim($m[1]) . ') continue; ?>'; },
            $source
        ) ?? $source;
        $source = preg_replace('/@continue\b/', '<?php continue; ?>', $source) ?? $source;

        $source = preg_replace_callback(
            '/@break\s*\((.+?)\)/',
            function (array $m): string { return '<?php if(' . trim($m[1]) . ') break; ?>'; },
            $source
        ) ?? $source;

        return $source;
    }

    /**
     * Extrai a parte iterável de uma expressão foreach.
     * Ex: "$items as $item" → "$items"
     * Ex: "$users as $key => $user" → "$users"
     */
    private function extractIterableFromForeach(string $expr): string
    {
        if (preg_match('/^(.+?)\s+as\s+/i', $expr, $m)) {
            return trim($m[1]);
        }
        return $expr;
    }

    // ---- Includes ------------------------------------------------------------

    private function compileIncludes(string $source): string
    {
        $source = preg_replace_callback(
            '/@include\s*\(\s*[\'"]([^\'"]+)[\'"]\s*(?:,\s*(\[[\s\S]*?\]))?\s*\)/',
            function (array $m): string {
                return "<?php echo \$__engine->renderInclude('" . addslashes($m[1]) . "', " . (isset($m[2]) ? $m[2] : '[]') . "); ?>";
            },
            $source
        ) ?? $source;

        $source = preg_replace_callback(
            '/@includeIf\s*\(\s*[\'"]([^\'"]+)[\'"]\s*(?:,\s*(\[[\s\S]*?\]))?\s*\)/',
            function (array $m): string {
                return "<?php echo \$__engine->renderIncludeIf('" . addslashes($m[1]) . "', " . (isset($m[2]) ? $m[2] : '[]') . "); ?>";
            },
            $source
        ) ?? $source;

        $source = preg_replace_callback(
            '/@includeWhen\s*\(\s*(.+?)\s*,\s*[\'"]([^\'"]+)[\'"]\s*(?:,\s*(\[[\s\S]*?\]))?\s*\)/',
            function (array $m): string {
                return "<?php if({$m[1]}): echo \$__engine->renderInclude('" . addslashes($m[2]) . "', " . (isset($m[3]) ? $m[3] : '[]') . "); endif; ?>";
            },
            $source
        ) ?? $source;

        $source = preg_replace_callback(
            '/@includeUnless\s*\(\s*(.+?)\s*,\s*[\'"]([^\'"]+)[\'"]\s*(?:,\s*(\[[\s\S]*?\]))?\s*\)/',
            function (array $m): string {
                return "<?php if(!({$m[1]})): echo \$__engine->renderInclude('" . addslashes($m[2]) . "', " . (isset($m[3]) ? $m[3] : '[]') . "); endif; ?>";
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
            function (array $m): string {
                return "<?php \$__engine->startPush('" . addslashes($m[1]) . "'); ?>";
            },
            $source
        ) ?? $source;
        $source = preg_replace('/@endpush\b/', '<?php $__engine->endPush(); ?>', $source) ?? $source;

        $source = preg_replace_callback(
            '/@prepend\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/',
            function (array $m): string {
                return "<?php \$__engine->startPrepend('" . addslashes($m[1]) . "'); ?>";
            },
            $source
        ) ?? $source;
        $source = preg_replace('/@endprepend\b/', '<?php $__engine->endPrepend(); ?>', $source) ?? $source;

        $source = preg_replace_callback(
            '/@stack\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/',
            function (array $m): string {
                return "<?php echo \$__engine->renderStack('" . addslashes($m[1]) . "'); ?>";
            },
            $source
        ) ?? $source;

        return $source;
    }

    // ---- @component / @slot / @endcomponent ----------------------------------

    /**
     * Suporte a componentes anónimos:
     *
     *   @component('components.alert', ['type' => 'success'])
     *       @slot('title')Sucesso!@endslot
     *       Operação concluída com êxito.
     *   @endcomponent
     *
     * No ficheiro do componente (components/alert.luna.php):
     *   <div class="alert alert-{{ $type }}">
     *       <strong>{!! $__slots['title'] ?? '' !!}</strong>
     *       {!! $slot !!}
     *   </div>
     */
    private function compileComponents(string $source): string
    {
        // @slot('name') ... @endslot
        $source = preg_replace_callback(
            '/@slot\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/',
            function (array $m): string {
                return "<?php \$__engine->startSlot('" . addslashes($m[1]) . "'); ?>";
            },
            $source
        ) ?? $source;
        $source = preg_replace('/@endslot\b/', '<?php $__engine->endSlot(); ?>', $source) ?? $source;

        // @component('view', [...]) ... @endcomponent
        $source = preg_replace_callback(
            '/@component\s*\(\s*[\'"]([^\'"]+)[\'"]\s*(?:,\s*(\[[\s\S]*?\]))?\s*\)([\s\S]*?)@endcomponent/s',
            function (array $m): string {
                $view = addslashes($m[1]);
                $data = isset($m[2]) ? $m[2] : '[]';
                $body = $m[3];
                return
                    "<?php \$__engine->startComponent('{$view}', {$data}); ?>" .
                    $body .
                    "<?php echo \$__engine->renderComponent(); ?>";
            },
            $source
        ) ?? $source;

        return $source;
    }

    // ---- @translate / @lang --------------------------------------------------

    /**
     * @translate('chave') ou @lang('chave')
     *
     * Exemplo:
     *   Luna::setTranslations(['welcome' => 'Bem-vindo!'], 'pt');
     *   No template: @translate('welcome')  →  Bem-vindo!
     */
    private function compileI18n(string $source): string
    {
        $pattern = '/@(?:translate|lang)\s*\(\s*[\'"]([^\'"]+)[\'"]\s*(?:,\s*(\[[\s\S]*?\]))?\s*\)/';

        return preg_replace_callback(
            $pattern,
            function (array $m): string {
                $key  = addslashes($m[1]);
                $args = isset($m[2]) ? $m[2] : '[]';
                return "<?php echo \$__engine->translate('{$key}', {$args}); ?>";
            },
            $source
        ) ?? $source;
    }

    // ---- @once / @endonce ----------------------------------------------------

    /**
     * O bloco dentro de @once só é renderizado uma vez por request,
     * independentemente de quantas vezes o template for incluído.
     *
     * Uso típico: scripts ou estilos embutidos em componentes reutilizáveis.
     */
    private function compileOnce(string $source): string
    {
        return preg_replace_callback(
            '/@once([\s\S]*?)@endonce/s',
            function (array $m): string {
                $key = '__once_' . md5($m[1]);
                return
                    "<?php if(\$__engine->shouldRenderOnce('{$key}')): ?>" .
                    $m[1] .
                    "<?php endif; ?>";
            },
            $source
        ) ?? $source;
    }

    // ---- Atributos de formulário HTML ----------------------------------------

    /**
     * @checked($expr)    → checked="checked" se truthy
     * @selected($expr)   → selected="selected" se truthy
     * @disabled($expr)   → disabled="disabled" se truthy
     * @required($expr)   → required="required" se truthy
     * @readonly($expr)   → readonly="readonly" se truthy
     * @multiple($expr)   → multiple="multiple" se truthy
     */
    private function compileAttributes(string $source): string
    {
        $attrs = [
            'checked'  => 'checked',
            'selected' => 'selected',
            'disabled' => 'disabled',
            'required' => 'required',
            'readonly' => 'readonly',
            'multiple' => 'multiple',
        ];

        foreach ($attrs as $directive => $attrName) {
            $source = preg_replace_callback(
                '/@' . $directive . '\s*\((.+?)\)/',
                function (array $m) use ($attrName): string {
                    return "<?php echo ({$m[1]}) ? '{$attrName}=\"{$attrName}\"' : ''; ?>";
                },
                $source
            ) ?? $source;
        }

        return $source;
    }

    // ---- @props --------------------------------------------------------------

    /**
     * @props(['name' => 'default', 'type' => 'text'])
     *
     * Define propriedades com valores padrão para componentes.
     * As propriedades passadas na chamada @component() sobrepõem os defaults.
     */
    private function compileProps(string $source): string
    {
        return preg_replace_callback(
            '/@props\s*\(\s*(\[[\s\S]*?\])\s*\)/',
            function (array $m): string {
                return "<?php foreach({$m[1]} as \$__propKey => \$__propDefault): " .
                       "if(!isset(\$\$__propKey)): \$\$__propKey = \$__propDefault; endif; endforeach; ?>";
            },
            $source
        ) ?? $source;
    }

    // ---- @inject -------------------------------------------------------------

    /**
     * @inject('variavel', 'App\Services\MeuServico')
     *
     * Resolve uma dependência do container e atribui-a a uma variável.
     * Requer que Luna::setContainer() tenha sido configurado.
     */
    private function compileInject(string $source): string
    {
        return preg_replace_callback(
            '/@inject\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]\s*\)/',
            function (array $m): string {
                $var      = addslashes($m[1]);
                $abstract = addslashes($m[2]);
                return "<?php \${$var} = \$__engine->resolveInject('{$abstract}'); ?>";
            },
            $source
        ) ?? $source;
    }

    // ---- Miscelânea ----------------------------------------------------------

    private function compileMisc(string $source): string
    {
        // @php ... @endphp
        $source = preg_replace('/@php\b([\s\S]*?)@endphp\b/', '<?php $1 ?>', $source) ?? $source;

        // @csrf
        $source = str_replace('@csrf', '<?php echo csrf_field(); ?>', $source);

         // @csrf meta
        $source = str_replace('@csrf_meta', '<?php echo csrf_meta(); ?>', $source);

        // @method('PUT')
        $source = preg_replace_callback(
            '/@method\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/',
            function (array $m): string {
                return '<input type="hidden" name="_method" value="' . strtoupper($m[1]) . '">';
            },
            $source
        ) ?? $source;

        // @json($var) / @json($var, JSON_PRETTY_PRINT)
        $source = preg_replace_callback(
            '/@json\s*\(\s*(.+?)\s*\)/',
            function (array $m): string {
                return "<?php echo json_encode({$m[1]}, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>";
            },
            $source
        ) ?? $source;

        // @class(['active' => $bool])
        $source = preg_replace_callback(
            '/@class\s*\(\s*(\[[\s\S]*?\])\s*\)/',
            function (array $m): string {
                return "<?php echo implode(' ', array_keys(array_filter({$m[1]}))); ?>";
            },
            $source
        ) ?? $source;

        // @style(['color:red' => $bool])
        $source = preg_replace_callback(
            '/@style\s*\(\s*(\[[\s\S]*?\])\s*\)/',
            function (array $m): string {
                return "<?php echo implode(';', array_keys(array_filter({$m[1]}))); ?>";
            },
            $source
        ) ?? $source;

        // @dump($var) / @dd($var)
        $source = preg_replace_callback(
            '/@dump\s*\(\s*(.+?)\s*\)/',
            function (array $m): string { return "<?php dump({$m[1]}); ?>"; },
            $source
        ) ?? $source;
        $source = preg_replace_callback(
            '/@dd\s*\(\s*(.+?)\s*\)/',
            function (array $m): string { return "<?php dd({$m[1]}); ?>"; },
            $source
        ) ?? $source;

        // @asset('img/logo.png')
        $source = preg_replace_callback(
            '/@asset\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/',
            function (array $m): string {
                return "<?php echo asset('" . addslashes($m[1]) . "'); ?>";
            },
            $source
        ) ?? $source;

        // @route('name') / @route('name', ['id' => 1])
        $source = preg_replace_callback(
            '/@route\s*\(\s*[\'"]([^\'"]+)[\'"]\s*(?:,\s*(\[[\s\S]*?\]))?\s*\)/',
            function (array $m): string {
                $name   = addslashes($m[1]);
                $params = isset($m[2]) ? $m[2] : '[]';
                return "<?php echo route('{$name}', {$params}); ?>";
            },
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
            function (array $m): string {
                return '<script type="module" src="/' . $m[1] . '"></script>';
            },
            $source
        ) ?? $source;

        // @url('path') — constrói URL absoluta
        $source = preg_replace_callback(
            '/@url\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/',
            function (array $m): string {
                return "<?php echo url('" . addslashes($m[1]) . "'); ?>";
            },
            $source
        ) ?? $source;

        // @livewire('component') — integração com Livewire
        $source = preg_replace_callback(
            '/@livewire\s*\(\s*[\'"]([^\'"]+)[\'"]\s*(?:,\s*(\[[\s\S]*?\]))?\s*\)/',
            function (array $m): string {
                $name   = addslashes($m[1]);
                $params = isset($m[2]) ? $m[2] : '[]';
                return "<?php echo livewire_component('{$name}', {$params}); ?>";
            },
            $source
        ) ?? $source;

        return $source;
    }

    // ---- Diretivas personalizadas -------------------------------------------

    private function compileCustomDirectives(string $source): string
    {
        foreach (self::$customDirectives as $name => $callback) {
            $source = preg_replace_callback(
                '/@' . preg_quote($name, '/') . '\s*(?:\((.+?)\))?/',
                function (array $m) use ($callback): string {
                    $expr = isset($m[1]) ? trim($m[1]) : '';
                    return (string) $callback($expr);
                },
                $source
            ) ?? $source;
        }

        return $source;
    }

    // =========================================================================
    // API PÚBLICA — chamada pelo código compilado via $__engine
    // =========================================================================

    public function setLayout(string $layout): void
    {
        $this->layout = $layout;
    }

    public function hasSection(string $name): bool
    {
        return isset($this->sections[$name]) && $this->sections[$name] !== '';
    }

    public function yieldSection(string $name, string $default = ''): string
    {
        return isset($this->sections[$name]) ? $this->sections[$name] : $default;
    }

    public function startSection(string $name): void
    {
        $this->currentSection = $name;
        ob_start();
    }

    public function endSection(): void
    {
        $content = ob_get_clean() ?: '';
        if ($this->currentSection === '') {
            return;
        }

        if (isset($this->sections[$this->currentSection])) {
            $content = str_replace('@parent', $this->sections[$this->currentSection], $content);
        }

        $this->sections[$this->currentSection] = $content;
        $this->currentSection = '';
    }

    public function startPush(string $name): void
    {
        $this->currentSection = '__push__' . $name;
        ob_start();
    }

    public function endPush(): void
    {
        $content = ob_get_clean() ?: '';
        $name    = substr($this->currentSection, 8);
        $this->stacks[$name][] = $content;
        $this->currentSection  = '';
    }

    public function startPrepend(string $name): void
    {
        $this->currentSection = '__prepend__' . $name;
        ob_start();
    }

    public function endPrepend(): void
    {
        $content = ob_get_clean() ?: '';
        $name    = substr($this->currentSection, 11);
        array_unshift($this->stacks[$name], $content);
        $this->currentSection = '';
    }

    public function renderStack(string $name): string
    {
        return implode('', isset($this->stacks[$name]) ? $this->stacks[$name] : []);
    }

    // ---- Componentes --------------------------------------------------------

    /** @var array<int, array{view: string, data: array<string, mixed>, slots: array<string, string>}> */
    private $componentStack = [];

    /**
     * @param array<string, mixed> $data
     */
    public function startComponent(string $view, array $data = []): void
    {
        $this->componentStack[] = [
            'view'  => $view,
            'data'  => $data,
            'slots' => [],
        ];
        ob_start();
    }

    public function renderComponent(): string
    {
        $content   = ob_get_clean() ?: '';
        $component = array_pop($this->componentStack);

        if ($component === null) {
            return '';
        }

        $data             = array_merge($this->data, $component['data']);
        $data['slot']     = $content;
        $data['__slots']  = $component['slots'];

        $engine           = new self($component['view'], $data);
        $engine->sections = $this->sections;
        $engine->stacks   = $this->stacks;
        return $engine->render();
    }

    public function startSlot(string $name): void
    {
        $this->currentSlot = $name;
        ob_start();
    }

    public function endSlot(): void
    {
        $content = ob_get_clean() ?: '';

        if ($this->currentSlot === '') {
            return;
        }

        $last = count($this->componentStack) - 1;
        if ($last >= 0) {
            $this->componentStack[$last]['slots'][$this->currentSlot] = $content;
        }

        $this->currentSlot = '';
    }

    // ---- @once --------------------------------------------------------------

    public function shouldRenderOnce(string $key): bool
    {
        if (isset(self::$onceSections[$key])) {
            return false;
        }
        self::$onceSections[$key] = true;
        return true;
    }

    // ---- i18n ---------------------------------------------------------------

    /**
     * @param array<string, string> $replacements
     */
    public function translate(string $key, array $replacements = []): string
    {
        $text = isset(self::$translations[$key]) ? self::$translations[$key] : $key;

        foreach ($replacements as $search => $replace) {
            $text = str_replace(':' . $search, (string) $replace, $text);
        }

        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    // ---- @inject ------------------------------------------------------------

    /**
     * @return mixed
     */
    public function resolveInject(string $abstract)
    {
        if (self::$container === null) {
            throw new RuntimeException(
                "Luna: @inject requer um container configurado via Luna::setContainer()."
            );
        }

        return (self::$container)($abstract);
    }

    // ---- Includes -----------------------------------------------------------

    /**
     * @param array<string, mixed> $data
     */
    public function renderInclude(string $template, array $data = []): string
    {
        $engine           = new self($template, array_merge($this->data, $data));
        $engine->sections = $this->sections;
        $engine->stacks   = $this->stacks;
        return $engine->render();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function renderIncludeIf(string $template, array $data = []): string
    {
        $path = self::$viewsDir . DIRECTORY_SEPARATOR . str_replace('.', DIRECTORY_SEPARATOR, $template) . '.luna.php';
        return file_exists($path) ? $this->renderInclude($template, $data) : '';
    }

    /**
     * @param iterable<mixed> $items
     */
    public function renderEach(string $template, $items, string $variable, string $empty = ''): string
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