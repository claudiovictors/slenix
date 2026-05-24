<?php

/*
 |--------------------------------------------------------------------------
 | Luna Engine — Slenix Framework Template Engine
 |--------------------------------------------------------------------------
 |
 | Inspired by Blade (Laravel). Supports layouts, sections, includes,
 | conditionals, loops, stacks, components, i18n, and smart disk caching.
 |
 | @version 2.6.0
 | @package Slenix\Supports\Template
 |
 */

declare(strict_types=1);

namespace Slenix\Supports\Template;

use RuntimeException;

class Luna
{

    /** @var string Absolute path to the view file */
    private string $viewPath = '';

    /** @var array<string, mixed> Data passed to this template */
    private array $data = [];

    /** @var array<string, string> Named sections (yield targets) */
    private array $sections = [];

    /** @var array<string, array<int, string>> Named stacks (push targets) */
    private array $stacks = [];

    /** @var string Layout template name declared by @extends */
    private string $layout = '';

    /** @var string Name of the section currently being captured */
    private string $currentSection = '';

    /** @var array<string, mixed> Slot data for the current component */
    private array $slots = [];

    /** @var string Name of the slot currently being captured */
    private string $currentSlot = '';

    /** @var array<int, array{view: string, data: array<string, mixed>, slots: array<string, string>}> */
    private array $componentStack = [];

    // =========================================================================
    // Static / Global State
    // =========================================================================

    /** @var array<string, string> In-memory compilation cache (current request) */
    private static array $memCache = [];

    /** @var array<string, mixed> Global variables available in every template */
    private static array $globalData = [];

    /** @var string Root directory for view files */
    private static string $viewsDir = '';

    /** @var string Directory where compiled templates are stored */
    private static string $cacheDir = '';

    /** @var string Directory where log files are stored */
    private static string $logsDir = '';

    /** @var bool Whether disk-based caching is enabled */
    private static bool $diskCache = false;

    /** @var bool Whether logging is enabled */
    private static bool $logging = false;

    /** @var int Cache TTL in seconds (0 = no TTL, rely on mtime) */
    private static int $cacheTtl = 0;

    /** @var array<string, bool> Tracks @once blocks already rendered this request */
    private static array $onceSections = [];

    /** @var array<string, callable> User-registered custom directives */
    private static array $customDirectives = [];

    /** @var array<string, string> Translation key → text map */
    private static array $translations = [];

    /** @var string Active locale for translations */
    private static string $locale = 'en';

    /** @var callable|null Dependency-injection resolver used by @inject */
    private static mixed $container = null;

    /** @var array<string, callable> Named composers executed before rendering a view */
    private static array $composers = [];

    /** @var array<string, string> Alias → view name map */
    private static array $aliases = [];

    /** @var string[] Stack of view paths currently being rendered (cycle detection) */
    private static array $renderStack = [];

    /** @var int Maximum nesting depth to prevent infinite recursion */
    private static int $maxNestingDepth = 50;

    // =========================================================================
    // Configuration
    // =========================================================================

    /**
     * Configures the Luna engine.
     *
     * @param string $viewsDir  Root directory for view files.
     * @param string $cacheDir  Directory for compiled/cached templates.
     * @param string $logsDir   Directory for log output.
     * @param bool   $cache     Enable disk-based template caching.
     * @param bool   $logging   Enable file logging.
     * @param int    $cacheTtl  Cache time-to-live in seconds (0 = mtime-based).
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
     * Shares a global variable available in every template.
     *
     * @param string $key   Variable name.
     * @param mixed  $value Variable value.
     */
    public static function share(string $key, mixed $value): void
    {
        self::$globalData[$key] = $value;
    }

    /**
     * Shares multiple global variables at once.
     *
     * @param array<string, mixed> $data Associative array of variables.
     */
    public static function shareMany(array $data): void
    {
        self::$globalData = array_merge(self::$globalData, $data);
    }

    /**
     * Registers a custom directive.
     *
     * Example:
     *   Luna::directive('money', fn($expr) => "<?php echo number_format($expr, 2, '.', ','); ?>");
     *   In template: @money($price)
     *
     * @param string   $name     Directive name without the leading @.
     * @param callable $callback Receives the expression inside parentheses, returns PHP code.
     */
    public static function directive(string $name, callable $callback): void
    {
        self::$customDirectives[$name] = $callback;
    }

    /**
     * Registers a view alias.
     *
     * Example:
     *   Luna::alias('btn', 'components.button');
     *   In template: @include('btn')
     *
     * @param string $alias    Short alias name.
     * @param string $viewName Full dot-notation view path.
     */
    public static function alias(string $alias, string $viewName): void
    {
        self::$aliases[$alias] = $viewName;
    }

    /**
     * Registers a view composer that runs before a specific view is rendered.
     *
     * Example:
     *   Luna::composer('layouts.app', function(Luna $engine) {
     *       $engine->with('version', '2.6');
     *   });
     *
     * @param string   $view     Dot-notation view path.
     * @param callable $callback Receives the Luna instance.
     */
    public static function composer(string $view, callable $callback): void
    {
        self::$composers[$view] = $callback;
    }

    /**
     * Sets the available translations.
     *
     * @param array<string, string> $translations Key → text map.
     * @param string                $locale       Locale identifier (e.g. 'en', 'pt').
     */
    public static function setTranslations(array $translations, string $locale = 'en'): void
    {
        self::$translations = $translations;
        self::$locale       = $locale;
    }

    /**
     * Sets the dependency-injection container resolver used by @inject.
     *
     * @param callable $resolver fn(string $abstract): mixed
     */
    public static function setContainer(callable $resolver): void
    {
        self::$container = $resolver;
    }

    /**
     * Sets the maximum view-nesting depth (to catch infinite @include loops).
     *
     * @param int $depth Maximum allowed depth (default: 50).
     */
    public static function setMaxNestingDepth(int $depth): self
    {
        self::$maxNestingDepth = max(1, $depth);
        return new self('');
    }

    /**
     * Clears both in-memory and disk caches.
     * Called by: php celestial view:clear
     *
     * @return int Number of disk cache files removed.
     */
    public static function clearCache(): int
    {
        self::$memCache     = [];
        self::$onceSections = [];

        if (!is_dir(self::$cacheDir)) {
            return 0;
        }

        $deleted = 0;
        foreach (glob(self::$cacheDir . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
            unlink($file);
            $deleted++;
        }

        self::log('INFO', "Cache cleared: {$deleted} file(s) removed.");
        return $deleted;
    }

    /**
     * Warms up the disk cache by precompiling all view files.
     *
     * @return int Number of templates compiled.
     */
    public static function warmCache(): int
    {
        if (!is_dir(self::$viewsDir)) {
            return 0;
        }

        $compiled = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::$viewsDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php' && str_ends_with($file->getFilename(), '.luna.php')) {
                $engine = new self('');
                $engine->getCompiled($file->getPathname());
                $compiled++;
            }
        }

        self::log('INFO', "Cache warmed: {$compiled} template(s) compiled.");
        return $compiled;
    }

    /**
     * Returns disk cache statistics.
     *
     * @return array{files: int, size_bytes: int, size_human: string, directory: string}
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

    /**
     * Creates a new Luna template instance.
     *
     * @param string               $template Dot-notation template path (e.g. 'auth.login').
     * @param array<string, mixed> $data     Variables passed to the template.
     */
    public function __construct(string $template, array $data = [])
    {
        if (self::$viewsDir === '' && $template !== '') {
            self::configure();
        }

        if ($template === '') {
            return;
        }

        // Resolve alias
        $template = self::$aliases[$template] ?? $template;

        $relativePath   = str_replace('.', DIRECTORY_SEPARATOR, $template) . '.luna.php';
        $this->viewPath = self::$viewsDir . DIRECTORY_SEPARATOR . $relativePath;
        $this->data     = array_merge(self::$globalData, $data);
    }

    /**
     * Adds (or overwrites) a variable for this template instance.
     *
     * @param string $key   Variable name.
     * @param mixed  $value Variable value.
     * @return self
     */
    public function with(string $key, mixed $value): self
    {
        $this->data[$key] = $value;
        return $this;
    }

    /**
     * Adds multiple variables for this template instance.
     *
     * @param array<string, mixed> $data Associative array of variables.
     * @return self
     */
    public function withMany(array $data): self
    {
        $this->data = array_merge($this->data, $data);
        return $this;
    }

    /**
     * Renders the template and returns the output as a string.
     *
     * @return string Rendered HTML/text output.
     * @throws RuntimeException If the view file is not found or evaluation fails.
     */
    public function render(): string
    {
        if (!file_exists($this->viewPath)) {
            self::log('ERROR', "View not found: {$this->viewPath}");
            throw new RuntimeException(
                "View not found: [{$this->viewPath}]\n" .
                "Make sure the file exists and has the .luna.php extension."
            );
        }

        // Cycle detection
        if (in_array($this->viewPath, self::$renderStack, true)) {
            throw new RuntimeException(
                "Circular view reference detected: [{$this->viewPath}]"
            );
        }

        if (count(self::$renderStack) >= self::$maxNestingDepth) {
            throw new RuntimeException(
                "Maximum view nesting depth (" . self::$maxNestingDepth . ") exceeded."
            );
        }

        // Run view composer if registered
        $viewKey = $this->resolveViewKey($this->viewPath);
        if (isset(self::$composers[$viewKey])) {
            (self::$composers[$viewKey])($this);
        }

        self::$renderStack[] = $this->viewPath;

        try {
            $compiled = $this->getCompiled($this->viewPath);
            $output   = $this->evaluate($compiled, $this->data);
        } finally {
            array_pop(self::$renderStack);
        }

        // If the template declared @extends, render the layout now
        if ($this->layout !== '') {
            $layoutEngine           = new self($this->layout, $this->data);
            $layoutEngine->sections = $this->sections;
            $layoutEngine->stacks   = $this->stacks;
            return $layoutEngine->render();
        }

        return $output;
    }

    /**
     * Renders the template and echoes the output directly.
     *
     * @throws RuntimeException
     */
    public function display(): void
    {
        echo $this->render();
    }

    /**
     * Returns the rendered output (alias for render()).
     *
     * @return string
     */
    public function __toString(): string
    {
        try {
            return $this->render();
        } catch (\Throwable $e) {
            return '<!-- Luna render error: ' . htmlspecialchars($e->getMessage()) . ' -->';
        }
    }

    /**
     * Static factory — renders a template and returns the output string.
     *
     * @param string               $template Dot-notation template path.
     * @param array<string, mixed> $data     Variables passed to the template.
     * @return string
     */
    public static function make(string $template, array $data = []): string
    {
        return (new self($template, $data))->render();
    }

    /**
     * Static factory — renders a template and echoes it immediately.
     *
     * @param string               $template Dot-notation template path.
     * @param array<string, mixed> $data     Variables passed to the template.
     */
    public static function show(string $template, array $data = []): void
    {
        echo (new self($template, $data))->render();
    }

    /**
     * Checks whether a view file exists.
     *
     * @param string $template Dot-notation template path.
     * @return bool
     */
    public static function exists(string $template): bool
    {
        if (self::$viewsDir === '') {
            self::configure();
        }
        $template = self::$aliases[$template] ?? $template;
        $path = self::$viewsDir . DIRECTORY_SEPARATOR
            . str_replace('.', DIRECTORY_SEPARATOR, $template) . '.luna.php';
        return file_exists($path);
    }

    /**
     * Returns the first existing view from a list of candidates.
     *
     * @param string[] $views Ordered list of dot-notation template paths.
     * @param array<string, mixed> $data
     * @return string Rendered output of the first existing view.
     * @throws RuntimeException If none of the views exist.
     */
    public static function first(array $views, array $data = []): string
    {
        foreach ($views as $view) {
            if (self::exists($view)) {
                return self::make($view, $data);
            }
        }
        throw new RuntimeException('None of the specified views exist: ' . implode(', ', $views));
    }

    /**
     * Retrieves compiled template source — from memory cache, disk cache, or compiles fresh.
     *
     * @param string $viewPath Absolute file path.
     * @return string PHP source ready for eval.
     */
    private function getCompiled(string $viewPath): string
    {
        $isDebug = (isset($_ENV['APP_DEBUG']) && $_ENV['APP_DEBUG'] === 'true');

        // 1) In-memory cache (no I/O, same request)
        if (!$isDebug && isset(self::$memCache[$viewPath])) {
            self::log('DEBUG', "Memory cache HIT: {$viewPath}");
            return self::$memCache[$viewPath];
        }

        // 2) Disk cache — valid if newer than source file
        if (!$isDebug && self::$diskCache) {
            $cachePath = $this->buildCachePath($viewPath);

            if (file_exists($cachePath) && $this->isCacheValid($cachePath, $viewPath)) {
                $compiled = (string) file_get_contents($cachePath);
                self::$memCache[$viewPath] = $compiled;
                self::log('DEBUG', "Disk cache HIT: {$cachePath}");
                return $compiled;
            }
        }

        // 3) Compile from source
        self::log('DEBUG', "Compiling template: {$viewPath}");
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
     * Determines whether the disk cache entry is still valid.
     *
     * @param string $cachePath Absolute path to the cached file.
     * @param string $viewPath  Absolute path to the source view.
     * @return bool
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

    /**
     * Builds the cache file path for a given view.
     *
     * @param string $viewPath Absolute path to the source view.
     * @return string Absolute path to the cache file.
     */
    private function buildCachePath(string $viewPath): string
    {
        return self::$cacheDir . DIRECTORY_SEPARATOR . sha1($viewPath) . '.php';
    }

    /**
     * Writes compiled PHP to the disk cache.
     *
     * @param string $cachePath Absolute path to the cache file.
     * @param string $compiled  Compiled PHP source.
     * @param string $viewPath  Source view path (used in header comment).
     */
    private function saveCacheToDisk(string $cachePath, string $compiled, string $viewPath): void
    {
        $dir = dirname($cachePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $header = "<?php /* Luna Cache | v2.7 | Source: {$viewPath} | Generated: " . date('Y-m-d H:i:s') . " */ ?>\n";
        $result = file_put_contents($cachePath, $header . $compiled, LOCK_EX);

        if ($result === false) {
            self::log('WARNING', "Failed to write disk cache: {$cachePath}");
        } else {
            self::log('INFO', "Cache written: {$cachePath}");
        }
    }

    /**
     * Evaluates the compiled PHP template in an isolated scope.
     *
     * @param string               $compiled Compiled PHP source.
     * @param array<string, mixed> $data     Variables to extract into scope.
     * @return string Output buffer contents.
     * @throws RuntimeException On eval error.
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
                    fn($l, $i) => sprintf('%4d | %s', $i + 1, $l),
                    $lines,
                    array_keys($lines)
                );
                $hint = "\n\n--- Compiled template ---\n" . implode("\n", $numbered);
            }

            self::log('ERROR', "Eval error [{$this->viewPath}]: " . $e->getMessage());

            throw new RuntimeException(
                "Error evaluating template [{$this->viewPath}]: " . $e->getMessage() . $hint,
                0,
                $e
            );
        }

        return ob_get_clean() ?: '';
    }

    /**
     * Runs the full compilation pipeline on raw template source.
     *
     * Order is critical:
     *  1. Extract @verbatim blocks (skip their interior)
     *  2. Strip comments
     *  3. Compile @directives → PHP blocks
     *  4. Compile {!! !!} → unescaped echo
     *  5. Compile {{ }} → escaped echo
     *  6. Restore @verbatim blocks
     *
     * @param string $source Raw template source.
     * @return string Compiled PHP source.
     */
    private function compile(string $source): string
    {
        [$source, $verbatimPlaceholders] = $this->extractVerbatim($source);
        $source = $this->compileComments($source);
        $source = $this->compileDirectives($source);
        $source = $this->compileRawEchos($source);
        $source = $this->compileEscapedEchos($source);
        $source = $this->restoreVerbatim($source, $verbatimPlaceholders);

        return $source;
    }

    /**
     * Extracts @verbatim blocks, replacing them with unique placeholders.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function extractVerbatim(string $source): array
    {
        $placeholders = [];
        $index        = 0;

        $source = preg_replace_callback(
            '/@verbatim([\s\S]*?)@endverbatim/s',
            /**
             * @param array<int, string> $m
             */
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
     * Restores @verbatim blocks from their placeholders.
     *
     * @param array<string, string> $placeholders Placeholder → original content map.
     */
    private function restoreVerbatim(string $source, array $placeholders): string
    {
        return str_replace(array_keys($placeholders), array_values($placeholders), $source);
    }

    /**
     * Removes template comments ({{-- ... --}} and {# ... #}).
     */
    private function compileComments(string $source): string
    {
        $source = preg_replace('/\{\{--[\s\S]*?--\}\}/s', '', $source) ?? $source;
        $source = preg_replace('/\{#[\s\S]*?#\}/s', '', $source) ?? $source;
        return $source;
    }

    /**
     * Compiles {!! expr !!} → unescaped echo.
     */
    private function compileRawEchos(string $source): string
    {
        return preg_replace_callback(
            '/\{!!\s*(.+?)\s*!!\}/s',
            fn(array $m): string => '<?php echo ' . trim($m[1]) . '; ?>',
            $source
        ) ?? $source;
    }

    /**
     * Compiles {{ expr }} → htmlspecialchars-escaped echo.
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

                $safe = addslashes($expr);
                return "<?php echo htmlspecialchars('{$safe}', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>";
            },
            $source
        ) ?? $source;
    }

    /**
     * Heuristic to determine whether a {{ }} expression is PHP or a literal string.
     *
     * @param string $expr Expression to test.
     * @return bool True if the expression should be evaluated as PHP.
     */
    private function isPhpExpression(string $expr): bool
    {
        $trimmed = ltrim($expr);

        if ($trimmed !== '' && $trimmed[0] === '$') {
            return true;
        }

        $len = strlen($trimmed);
        if ($len >= 2) {
            if (
                ($trimmed[0] === '"'  && $trimmed[$len - 1] === '"') ||
                ($trimmed[0] === "'" && $trimmed[$len - 1] === "'")
            ) {
                return true;
            }
        }

        if (preg_match('/\b[a-zA-Z_]\w*\s*\(/', $expr)) {
            return true;
        }

        if (str_contains($expr, '::') || str_contains($expr, '->') || str_contains($expr, '[')) {
            return true;
        }

        $stripped = preg_replace([
            '/(?<=[\w\x{00C0}-\x{024F}])-(?=[\w\x{00C0}-\x{024F}])/u',
            '/!(?=$|\s)/u',
            '/,/',
            '/\s+/',
        ], '', $expr);

        if (preg_match('/[+\*\/%=<>&|^~?]/', $stripped ?? $expr)) {
            return true;
        }

        if (preg_match('/^(true|false|null)$/i', trim($expr))) {
            return true;
        }

        if (is_numeric(trim($expr))) {
            return true;
        }

        return false;
    }

    /**
     * Orchestrates all directive compilers.
     */
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
            fn(array $m): string => "<?php \$__engine->setLayout('" . addslashes($m[1]) . "'); ?>",
            $source
        ) ?? $source;
    }

    // ---- @section / @yield / @endsection ------------------------------------

    private function compileSections(string $source): string
    {
        // @section('name', 'inline value')
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
            fn(array $m): string => "<?php \$__engine->startSection('" . addslashes($m[1]) . "'); ?>",
            $source
        ) ?? $source;

        // @endsection / @show
        $source = preg_replace('/@endsection\b|@show\b/', '<?php $__engine->endSection(); ?>', $source) ?? $source;

        // @yield('name') / @yield('name', 'default')
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
            fn(array $m): string => "<?php if(\$__engine->hasSection('" . addslashes($m[1]) . "')): ?>",
            $source
        ) ?? $source;

        $source = preg_replace_callback(
            '/@sectionMissing\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/',
            fn(array $m): string => "<?php if(!\$__engine->hasSection('" . addslashes($m[1]) . "')): ?>",
            $source
        ) ?? $source;

        return $source;
    }

    // ---- Conditionals -------------------------------------------------------

    private function compileConditionals(string $source): string
    {
        $source = preg_replace_callback(
            '/@if\s*\((.+?)\)\s*$/m',
            fn(array $m): string => '<?php if(' . trim($m[1]) . '): ?>',
            $source
        ) ?? $source;

        $source = preg_replace_callback(
            '/@elseif\s*\((.+?)\)\s*$/m',
            fn(array $m): string => '<?php elseif(' . trim($m[1]) . '): ?>',
            $source
        ) ?? $source;

        $source = preg_replace('/@else\b/',  '<?php else: ?>',  $source) ?? $source;
        $source = preg_replace('/@endif\b/', '<?php endif; ?>', $source) ?? $source;

        // @isset / @endisset
        $source = preg_replace_callback(
            '/@isset\s*\((.+?)\)\s*$/m',
            fn(array $m): string => '<?php if(isset(' . trim($m[1]) . ')): ?>',
            $source
        ) ?? $source;
        $source = preg_replace('/@endisset\b/', '<?php endif; ?>', $source) ?? $source;

        // @empty / @endempty
        $source = preg_replace_callback(
            '/@empty\s*\((.+?)\)\s*$/m',
            fn(array $m): string => '<?php if(empty(' . trim($m[1]) . ')): ?>',
            $source
        ) ?? $source;
        $source = preg_replace('/@endempty\b/', '<?php endif; ?>', $source) ?? $source;

        // @unless / @endunless
        $source = preg_replace_callback(
            '/@unless\s*\((.+?)\)\s*$/m',
            fn(array $m): string => '<?php if(!(' . trim($m[1]) . ')): ?>',
            $source
        ) ?? $source;
        $source = preg_replace('/@endunless\b/', '<?php endif; ?>', $source) ?? $source;

        // @switch / @case / @default / @endswitch
        $source = preg_replace_callback(
            '/@switch\s*\((.+?)\)\s*$/m',
            fn(array $m): string => '<?php switch(' . trim($m[1]) . '): ?>',
            $source
        ) ?? $source;
        $source = preg_replace_callback(
            '/@case\s*\((.+?)\)\s*$/m',
            fn(array $m): string => '<?php case ' . trim($m[1]) . ': ?>',
            $source
        ) ?? $source;
        $source = preg_replace('/@default\b/',   '<?php default: ?>',   $source) ?? $source;
        $source = preg_replace('/@endswitch\b/', '<?php endswitch; ?>', $source) ?? $source;
        $source = preg_replace('/@break\b/',     '<?php break; ?>',     $source) ?? $source;

        // @auth / @guest
        $source = preg_replace('/@auth\b/',     "<?php if(!empty(auth()->check())): ?>", $source) ?? $source;
        $source = preg_replace('/@endauth\b/',  '<?php endif; ?>',                             $source) ?? $source;
        $source = preg_replace('/@guest\b/',    "<?php if(empty(auth()->check())): ?>",  $source) ?? $source;
        $source = preg_replace('/@endguest\b/', '<?php endif; ?>',                             $source) ?? $source;

        // @role('admin') — checks $_SESSION['auth_role']
        $source = preg_replace_callback(
            '/@role\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/',
            fn(array $m): string => "<?php if(isset(\$_SESSION['auth_role']) && \$_SESSION['auth_role'] === '" . addslashes($m[1]) . "'): ?>",
            $source
        ) ?? $source;
        $source = preg_replace('/@endrole\b/', '<?php endif; ?>', $source) ?? $source;

        // @can('permission') — hooks into an optional can() helper
        $source = preg_replace_callback(
            '/@can\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/',
            fn(array $m): string => "<?php if(function_exists('can') && can('" . addslashes($m[1]) . "')): ?>",
            $source
        ) ?? $source;
        $source = preg_replace('/@endcan\b/', '<?php endif; ?>', $source) ?? $source;

        // @cannot('permission')
        $source = preg_replace_callback(
            '/@cannot\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/',
            fn(array $m): string => "<?php if(!function_exists('can') || !can('" . addslashes($m[1]) . "')): ?>",
            $source
        ) ?? $source;
        $source = preg_replace('/@endcannot\b/', '<?php endif; ?>', $source) ?? $source;

        // @env / @production / @debug
        $source = preg_replace_callback(
            '/@env\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/',
            fn(array $m): string => "<?php if((isset(\$_ENV['APP_ENV']) ? \$_ENV['APP_ENV'] : '') === '" . addslashes($m[1]) . "'): ?>",
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

        // @feature('flag') — feature-flag support via feature() helper
        $source = preg_replace_callback(
            '/@feature\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/',
            fn(array $m): string => "<?php if(function_exists('feature') && feature('" . addslashes($m[1]) . "')): ?>",
            $source
        ) ?? $source;
        $source = preg_replace('/@endfeature\b/', '<?php endif; ?>', $source) ?? $source;

        return $source;
    }

    // ---- Loops --------------------------------------------------------------

    private function compileLoops(string $source): string
    {
        /*
         * Enhanced @foreach — $loop object now includes:
         *   ->index       (0-based)
         *   ->iteration   (1-based)
         *   ->count       total items
         *   ->first       bool
         *   ->last        bool
         *   ->even        bool (iteration is even)
         *   ->odd         bool (iteration is odd)
         *   ->depth       nesting depth (1 = root)
         *   ->parent      parent $loop reference (or null)
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
                    '$loop = (object)["index"=>0,"iteration"=>1,"count"=>count((array)$__items),' .
                    '"first"=>true,"last"=>false,"even"=>false,"odd"=>true,' .
                    '"depth"=>$__depth,"parent"=>$__parentLoop]; ' .
                    'foreach(' . $expr . '): ' .
                    '$loop->first = ($loop->index === 0); ' .
                    '$loop->last  = ($loop->index === $loop->count - 1); ' .
                    '$loop->even  = ($loop->iteration % 2 === 0); ' .
                    '$loop->odd   = ($loop->iteration % 2 !== 0); ' .
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
            fn(array $m): string => '<?php for(' . trim($m[1]) . '): ?>',
            $source
        ) ?? $source;
        $source = preg_replace('/@endfor\b/', '<?php endfor; ?>', $source) ?? $source;

        $source = preg_replace_callback(
            '/@while\s*\((.+?)\)\s*$/m',
            fn(array $m): string => '<?php while(' . trim($m[1]) . '): ?>',
            $source
        ) ?? $source;
        $source = preg_replace('/@endwhile\b/', '<?php endwhile; ?>', $source) ?? $source;

        // @forelse / @empty / @endforelse
        $source = preg_replace_callback(
            '/@forelse\s*\((.+?)\s+as\s+(.+?)\)\s*$/m',
            fn(array $m): string => '<?php $__items=' . trim($m[1]) . '; if(!empty($__items)): foreach($__items as ' . trim($m[2]) . '): ?>',
            $source
        ) ?? $source;
        $source = preg_replace('/@empty\s*$/m',   '<?php endforeach; else: ?>', $source) ?? $source;
        $source = preg_replace('/@endforelse\b/', '<?php endif; ?>',            $source) ?? $source;

        // @repeat(N) / @endrepeat
        $source = preg_replace_callback(
            '/@repeat\s*\(\s*(.+?)\s*\)\s*$/m',
            fn(array $m): string => '<?php for($__i = 0; $__i < (int)(' . trim($m[1]) . '); $__i++): ?>',
            $source
        ) ?? $source;
        $source = preg_replace('/@endrepeat\b/', '<?php endfor; ?>', $source) ?? $source;

        // @continue / @break
        $source = preg_replace_callback(
            '/@continue\s*\((.+?)\)/',
            fn(array $m): string => '<?php if(' . trim($m[1]) . ') continue; ?>',
            $source
        ) ?? $source;
        $source = preg_replace('/@continue\b/', '<?php continue; ?>', $source) ?? $source;

        $source = preg_replace_callback(
            '/@break\s*\((.+?)\)/',
            fn(array $m): string => '<?php if(' . trim($m[1]) . ') break; ?>',
            $source
        ) ?? $source;

        return $source;
    }

    /**
     * Extracts the iterable portion from a foreach expression.
     *
     * Example: "$items as $item" → "$items"
     *
     * @param string $expr Full foreach expression.
     * @return string The iterable part only.
     */
    private function extractIterableFromForeach(string $expr): string
    {
        if (preg_match('/^(.+?)\s+as\s+/i', $expr, $m)) {
            return trim($m[1]);
        }
        return $expr;
    }

    // ---- Includes -----------------------------------------------------------

    private function compileIncludes(string $source): string
    {
        $source = preg_replace_callback(
            '/@include\s*\(\s*[\'"]([^\'"]+)[\'"]\s*(?:,\s*(\[[\s\S]*?\]))?\s*\)/',
            fn(array $m): string => "<?php echo \$__engine->renderInclude('" . addslashes($m[1]) . "', " . ($m[2] ?? '[]') . "); ?>",
            $source
        ) ?? $source;

        $source = preg_replace_callback(
            '/@includeIf\s*\(\s*[\'"]([^\'"]+)[\'"]\s*(?:,\s*(\[[\s\S]*?\]))?\s*\)/',
            fn(array $m): string => "<?php echo \$__engine->renderIncludeIf('" . addslashes($m[1]) . "', " . ($m[2] ?? '[]') . "); ?>",
            $source
        ) ?? $source;

        $source = preg_replace_callback(
            '/@includeWhen\s*\(\s*(.+?)\s*,\s*[\'"]([^\'"]+)[\'"]\s*(?:,\s*(\[[\s\S]*?\]))?\s*\)/',
            fn(array $m): string => "<?php if({$m[1]}): echo \$__engine->renderInclude('" . addslashes($m[2]) . "', " . ($m[3] ?? '[]') . "); endif; ?>",
            $source
        ) ?? $source;

        $source = preg_replace_callback(
            '/@includeUnless\s*\(\s*(.+?)\s*,\s*[\'"]([^\'"]+)[\'"]\s*(?:,\s*(\[[\s\S]*?\]))?\s*\)/',
            fn(array $m): string => "<?php if(!({$m[1]})): echo \$__engine->renderInclude('" . addslashes($m[2]) . "', " . ($m[3] ?? '[]') . "); endif; ?>",
            $source
        ) ?? $source;

        $source = preg_replace_callback(
            '/@includeFirst\s*\(\s*(\[[\s\S]*?\])\s*(?:,\s*(\[[\s\S]*?\]))?\s*\)/',
            fn(array $m): string => "<?php echo \$__engine->renderIncludeFirst({$m[1]}, " . ($m[2] ?? '[]') . "); ?>",
            $source
        ) ?? $source;

        $source = preg_replace_callback(
            '/@each\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*(.+?)\s*,\s*[\'"]([^\'"]+)[\'"]\s*(?:,\s*[\'"]([^\'"]+)[\'"]\s*)?\)/',
            fn(array $m): string => "<?php echo \$__engine->renderEach('" . addslashes($m[1]) . "', {$m[2]}, '" . addslashes($m[3]) . "', " . (isset($m[4]) ? "'" . addslashes($m[4]) . "'" : "''") . "); ?>",
            $source
        ) ?? $source;

        return $source;
    }

    // ---- @push / @stack -----------------------------------------------------

    private function compileStacks(string $source): string
    {
        $source = preg_replace_callback(
            '/@push\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/',
            fn(array $m): string => "<?php \$__engine->startPush('" . addslashes($m[1]) . "'); ?>",
            $source
        ) ?? $source;
        $source = preg_replace('/@endpush\b/', '<?php $__engine->endPush(); ?>', $source) ?? $source;

        $source = preg_replace_callback(
            '/@prepend\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/',
            fn(array $m): string => "<?php \$__engine->startPrepend('" . addslashes($m[1]) . "'); ?>",
            $source
        ) ?? $source;
        $source = preg_replace('/@endprepend\b/', '<?php $__engine->endPrepend(); ?>', $source) ?? $source;

        $source = preg_replace_callback(
            '/@stack\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/',
            fn(array $m): string => "<?php echo \$__engine->renderStack('" . addslashes($m[1]) . "'); ?>",
            $source
        ) ?? $source;

        return $source;
    }

    // ---- @component / @slot -------------------------------------------------

    private function compileComponents(string $source): string
    {
        $source = preg_replace_callback(
            '/@slot\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/',
            fn(array $m): string => "<?php \$__engine->startSlot('" . addslashes($m[1]) . "'); ?>",
            $source
        ) ?? $source;
        $source = preg_replace('/@endslot\b/', '<?php $__engine->endSlot(); ?>', $source) ?? $source;

        $source = preg_replace_callback(
            '/@component\s*\(\s*[\'"]([^\'"]+)[\'"]\s*(?:,\s*(\[[\s\S]*?\]))?\s*\)([\s\S]*?)@endcomponent/s',
            function (array $m): string {
                $view = addslashes($m[1]);
                $data = $m[2] ?? '[]';
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

    // ---- @translate / @lang -------------------------------------------------

    private function compileI18n(string $source): string
    {
        return preg_replace_callback(
            '/@(?:translate|lang|t)\s*\(\s*[\'"]([^\'"]+)[\'"]\s*(?:,\s*(\[[\s\S]*?\]))?\s*\)/',
            function (array $m): string {
                $key  = addslashes($m[1]);
                $args = $m[2] ?? '[]';
                return "<?php echo \$__engine->translate('{$key}', {$args}); ?>";
            },
            $source
        ) ?? $source;
    }

    // ---- @once / @endonce ---------------------------------------------------

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

    // ---- HTML Form Attributes -----------------------------------------------

    private function compileAttributes(string $source): string
    {
        $attrs = [
            'checked'  => 'checked',
            'selected' => 'selected',
            'disabled' => 'disabled',
            'required' => 'required',
            'readonly' => 'readonly',
            'multiple' => 'multiple',
            'autofocus' => 'autofocus',
            'open'     => 'open',
        ];

        foreach ($attrs as $directive => $attrName) {
            $source = preg_replace_callback(
                '/@' . $directive . '\s*\((.+?)\)/',
                fn(array $m) => "<?php echo ({$m[1]}) ? '{$attrName}=\"{$attrName}\"' : ''; ?>",
                $source
            ) ?? $source;
        }

        return $source;
    }

    // ---- @props -------------------------------------------------------------

    private function compileProps(string $source): string
    {
        return preg_replace_callback(
            '/@props\s*\(\s*(\[[\s\S]*?\])\s*\)/',
            fn(array $m): string =>
                "<?php foreach({$m[1]} as \$__propKey => \$__propDefault): " .
                "if(!isset(\$\$__propKey)): \$\$__propKey = \$__propDefault; endif; endforeach; ?>",
            $source
        ) ?? $source;
    }

    // ---- @inject ------------------------------------------------------------

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

    // ---- Miscellaneous ------------------------------------------------------

    private function compileMisc(string $source): string
    {
        // @php ... @endphp
        $source = preg_replace('/@php\b([\s\S]*?)@endphp\b/', '<?php $1 ?>', $source) ?? $source;

        // @csrf / @csrf_meta
        $source = str_replace('@csrf',      '<?php echo csrf_field(); ?>', $source);
        $source = str_replace('@csrf_meta', '<?php csrf_meta(); ?>',  $source);

        // @method('PUT')
        $source = preg_replace_callback(
            '/@method\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/',
            fn(array $m): string => '<input type="hidden" name="_method" value="' . strtoupper($m[1]) . '">',
            $source
        ) ?? $source;

        // @json($var)
        $source = preg_replace_callback(
            '/@json\s*\(\s*(.+?)\s*\)/',
            fn(array $m): string => "<?php echo json_encode({$m[1]}, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>",
            $source
        ) ?? $source;

        // @class(['active' => $bool])
        $source = preg_replace_callback(
            '/@class\s*\(\s*(\[[\s\S]*?\])\s*\)/',
            fn(array $m): string => "<?php echo implode(' ', array_keys(array_filter({$m[1]}))); ?>",
            $source
        ) ?? $source;

        // @style(['color:red' => $bool])
        $source = preg_replace_callback(
            '/@style\s*\(\s*(\[[\s\S]*?\])\s*\)/',
            fn(array $m): string => "<?php echo implode(';', array_keys(array_filter({$m[1]}))); ?>",
            $source
        ) ?? $source;

        // @dump / @dd
        $source = preg_replace_callback(
            '/@dump\s*\(\s*(.+?)\s*\)/',
            fn(array $m): string => "<?php dump({$m[1]}); ?>",
            $source
        ) ?? $source;
        $source = preg_replace_callback(
            '/@dd\s*\(\s*(.+?)\s*\)/',
            fn(array $m): string => "<?php dd({$m[1]}); ?>",
            $source
        ) ?? $source;

        // @asset('img/logo.png')
        $source = preg_replace_callback(
            '/@asset\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/',
            fn(array $m): string => "<?php echo asset('" . addslashes($m[1]) . "'); ?>",
            $source
        ) ?? $source;

        // @route('name') / @route('name', ['id' => 1])
        $source = preg_replace_callback(
            '/@route\s*\(\s*[\'"]([^\'"]+)[\'"]\s*(?:,\s*(\[[\s\S]*?\]))?\s*\)/',
            fn(array $m): string => "<?php echo route('" . addslashes($m[1]) . "', " . ($m[2] ?? '[]') . "); ?>",
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
            fn(array $m): string => "<?php if(has_error('" . addslashes($m[1]) . "')): \$message = errors('" . addslashes($m[1]) . "'); ?>",
            $source
        ) ?? $source;
        $source = preg_replace('/@enderror\b/', '<?php endif; ?>', $source) ?? $source;

        // @vite('resources/app.js')
        $source = preg_replace_callback(
            '/@vite\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/',
            fn(array $m): string => '<script type="module" src="/' . $m[1] . '"></script>',
            $source
        ) ?? $source;

        // @url('path')
        $source = preg_replace_callback(
            '/@url\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/',
            fn(array $m): string => "<?php echo url('" . addslashes($m[1]) . "'); ?>",
            $source
        ) ?? $source;

        // @livewire('component')
        $source = preg_replace_callback(
            '/@livewire\s*\(\s*[\'"]([^\'"]+)[\'"]\s*(?:,\s*(\[[\s\S]*?\]))?\s*\)/',
            fn(array $m): string => "<?php echo livewire_component('" . addslashes($m[1]) . "', " . ($m[2] ?? '[]') . "); ?>",
            $source
        ) ?? $source;

        // @truncate($text, 100, '...')
        $source = preg_replace_callback(
            '/@truncate\s*\(\s*(.+?)\s*,\s*(\d+)\s*(?:,\s*[\'"]([^\'"]*)[\'"])?\s*\)/',
            function (array $m): string {
                $suffix = addslashes($m[3] ?? '...');
                return "<?php echo \$__engine->truncate({$m[1]}, {$m[2]}, '{$suffix}'); ?>";
            },
            $source
        ) ?? $source;

        // @nl2br($text)
        $source = preg_replace_callback(
            '/@nl2br\s*\(\s*(.+?)\s*\)/',
            fn(array $m): string => "<?php echo nl2br(htmlspecialchars((string)({$m[1]}), ENT_QUOTES, 'UTF-8')); ?>",
            $source
        ) ?? $source;

        // @number($value, 2)
        $source = preg_replace_callback(
            '/@number\s*\(\s*(.+?)\s*(?:,\s*(\d+))?\s*\)/',
            fn(array $m): string => "<?php echo number_format((float)({$m[1]}), " . ($m[2] ?? '0') . ", '.', ','); ?>",
            $source
        ) ?? $source;

        // @date($value, 'Y-m-d')
        $source = preg_replace_callback(
            '/@date\s*\(\s*(.+?)\s*(?:,\s*[\'"]([^\'"]+)[\'"])?\s*\)/',
            function (array $m): string {
                $format = addslashes($m[2] ?? 'Y-m-d');
                return "<?php echo date('{$format}', is_numeric({$m[1]}) ? (int){$m[1]} : strtotime((string)({$m[1]}))); ?>";
            },
            $source
        ) ?? $source;

        // @spaceless ... @endspaceless — strips whitespace between HTML tags
        $source = preg_replace_callback(
            '/@spaceless([\s\S]*?)@endspaceless/s',
            fn(array $m): string =>
                '<?php ob_start(); ?>' .
                $m[1] .
                '<?php echo preg_replace(\'/>\s+</\', \'><\', ob_get_clean()); ?>',
            $source
        ) ?? $source;

        // @markdown($text) — basic markdown conversion (no external deps)
        $source = preg_replace_callback(
            '/@markdown\s*\(\s*(.+?)\s*\)/',
            fn(array $m): string => "<?php echo \$__engine->parseMarkdown({$m[1]}); ?>",
            $source
        ) ?? $source;

        return $source;
    }

    // ---- Custom Directives --------------------------------------------------

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
    // Public API — called by compiled code via $__engine
    // =========================================================================

    /** @internal */
    public function setLayout(string $layout): void
    {
        $this->layout = $layout;
    }

    /** @internal */
    public function hasSection(string $name): bool
    {
        return isset($this->sections[$name]) && $this->sections[$name] !== '';
    }

    /** @internal */
    public function yieldSection(string $name, string $default = ''): string
    {
        return $this->sections[$name] ?? $default;
    }

    /** @internal */
    public function startSection(string $name): void
    {
        $this->currentSection = $name;
        ob_start();
    }

    /** @internal */
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

    /** @internal */
    public function startPush(string $name): void
    {
        $this->currentSection = '__push__' . $name;
        ob_start();
    }

    /** @internal */
    public function endPush(): void
    {
        $content = ob_get_clean() ?: '';
        $name    = substr($this->currentSection, 8);
        $this->stacks[$name][] = $content;
        $this->currentSection  = '';
    }

    /** @internal */
    public function startPrepend(string $name): void
    {
        $this->currentSection = '__prepend__' . $name;
        ob_start();
    }

    /** @internal */
    public function endPrepend(): void
    {
        $content = ob_get_clean() ?: '';
        $name    = substr($this->currentSection, 11);
        array_unshift($this->stacks[$name], $content);
        $this->currentSection = '';
    }

    /** @internal */
    public function renderStack(string $name): string
    {
        return implode('', $this->stacks[$name] ?? []);
    }

    // ---- Component API ------------------------------------------------------

    /**
     * @internal
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

    /** @internal */
    public function renderComponent(): string
    {
        $content   = ob_get_clean() ?: '';
        $component = array_pop($this->componentStack);

        if ($component === null) {
            return '';
        }

        $data            = array_merge($this->data, $component['data']);
        $data['slot']    = $content;
        $data['__slots'] = $component['slots'];

        $engine           = new self($component['view'], $data);
        $engine->sections = $this->sections;
        $engine->stacks   = $this->stacks;
        return $engine->render();
    }

    /** @internal */
    public function startSlot(string $name): void
    {
        $this->currentSlot = $name;
        ob_start();
    }

    /** @internal */
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

    /** @internal */
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
     * Translates a key using the registered translation table.
     *
     * @param string               $key          Translation key.
     * @param array<string, string> $replacements Named placeholders (e.g. ['name' => 'John']).
     * @return string Translated and HTML-escaped string.
     */
    public function translate(string $key, array $replacements = []): string
    {
        $text = self::$translations[$key] ?? $key;

        foreach ($replacements as $search => $replace) {
            $text = str_replace(':' . $search, (string) $replace, $text);
        }

        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    // ---- @inject ------------------------------------------------------------

    /**
     * Resolves a class/interface from the registered DI container.
     *
     * @param string $abstract Class or interface name.
     * @return mixed Resolved instance.
     * @throws RuntimeException If no container has been configured.
     */
    public function resolveInject(string $abstract): mixed
    {
        if (self::$container === null) {
            throw new RuntimeException(
                'Luna: @inject requires a container configured via Luna::setContainer().'
            );
        }

        return (self::$container)($abstract);
    }

    // ---- Includes -----------------------------------------------------------

    /**
     * Renders a sub-template and returns its output.
     *
     * @param string               $template Dot-notation template path.
     * @param array<string, mixed> $data     Additional variables (merged with parent data).
     * @return string Rendered output.
     */
    public function renderInclude(string $template, array $data = []): string
    {
        $engine           = new self($template, array_merge($this->data, $data));
        $engine->sections = $this->sections;
        $engine->stacks   = $this->stacks;
        return $engine->render();
    }

    /**
     * Renders a sub-template only if the file exists; silently returns empty string otherwise.
     *
     * @param string               $template Dot-notation template path.
     * @param array<string, mixed> $data     Additional variables.
     * @return string Rendered output or empty string.
     */
    public function renderIncludeIf(string $template, array $data = []): string
    {
        $template = self::$aliases[$template] ?? $template;
        $path = self::$viewsDir . DIRECTORY_SEPARATOR
            . str_replace('.', DIRECTORY_SEPARATOR, $template) . '.luna.php';
        return file_exists($path) ? $this->renderInclude($template, $data) : '';
    }

    /**
     * Renders the first existing template from a list of candidates.
     *
     * @param string[]             $templates Ordered list of dot-notation template paths.
     * @param array<string, mixed> $data      Additional variables.
     * @return string Rendered output.
     * @throws RuntimeException If none of the templates exist.
     */
    public function renderIncludeFirst(array $templates, array $data = []): string
    {
        foreach ($templates as $template) {
            $template = self::$aliases[$template] ?? $template;
            $path = self::$viewsDir . DIRECTORY_SEPARATOR
                . str_replace('.', DIRECTORY_SEPARATOR, $template) . '.luna.php';
            if (file_exists($path)) {
                return $this->renderInclude($template, $data);
            }
        }
        throw new RuntimeException('None of the specified views exist: ' . implode(', ', $templates));
    }

    /**
     * Iterates over $items and renders $template for each item.
     *
     * @param string          $template  Dot-notation template path.
     * @param iterable<mixed> $items     Collection to iterate.
     * @param string          $variable  Variable name injected per iteration.
     * @param string          $empty     Fallback template name if $items is empty (or literal string).
     * @return string Concatenated rendered output.
     */
    public function renderEach(string $template, mixed $items, string $variable, string $empty = ''): string
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

    // ---- Utility Helpers (called from compiled templates) -------------------

    /**
     * Truncates a string to a maximum length, appending a suffix.
     *
     * @param string $text   Input string.
     * @param int    $length Maximum length.
     * @param string $suffix Appended when truncated (default: '...').
     * @return string
     */
    public function truncate(string $text, int $length, string $suffix = '...'): string
    {
        if (mb_strlen($text) <= $length) {
            return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        }
        return htmlspecialchars(mb_substr($text, 0, $length) . $suffix, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Converts basic Markdown to HTML without external dependencies.
     *
     * Supports: headings, bold, italic, inline code, code blocks, links,
     * images, unordered lists, ordered lists, blockquotes, and horizontal rules.
     *
     * @param string $text Raw Markdown input.
     * @return string Rendered HTML (NOT escaped — intended for {!! !!}).
     */
    public function parseMarkdown(string $text): string
    {
        // Code blocks (``` ... ```)
        $text = preg_replace_callback(
            '/```(\w*)\n?([\s\S]*?)```/s',
            fn($m) => '<pre><code' . ($m[1] ? ' class="language-' . htmlspecialchars($m[1]) . '"' : '') . '>'
                . htmlspecialchars($m[2]) . '</code></pre>',
            $text
        ) ?? $text;

        // Inline code
        $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text) ?? $text;

        // Headings
        $text = preg_replace_callback('/^(#{1,6})\s+(.+)$/m', function ($m) {
            $level = strlen($m[1]);
            return "<h{$level}>" . htmlspecialchars($m[2]) . "</h{$level}>";
        }, $text) ?? $text;

        // Horizontal rules
        $text = preg_replace('/^[-*_]{3,}\s*$/m', '<hr>', $text) ?? $text;

        // Blockquotes
        $text = preg_replace('/^>\s+(.+)$/m', '<blockquote>$1</blockquote>', $text) ?? $text;

        // Bold + Italic
        $text = preg_replace('/\*\*\*(.+?)\*\*\*/', '<strong><em>$1</em></strong>', $text) ?? $text;
        $text = preg_replace('/\*\*(.+?)\*\*/',     '<strong>$1</strong>',          $text) ?? $text;
        $text = preg_replace('/\*(.+?)\*/',          '<em>$1</em>',                 $text) ?? $text;

        // Images (before links)
        $text = preg_replace('/!\[([^\]]*)\]\(([^\)]+)\)/', '<img src="$2" alt="$1">', $text) ?? $text;

        // Links
        $text = preg_replace('/\[([^\]]+)\]\(([^\)]+)\)/', '<a href="$2">$1</a>', $text) ?? $text;

        // Unordered lists
        $text = preg_replace('/^[-*+]\s+(.+)$/m', '<li>$1</li>', $text) ?? $text;
        $text = preg_replace('/(<li>.*<\/li>)/s', '<ul>$1</ul>', $text) ?? $text;

        // Ordered lists
        $text = preg_replace('/^\d+\.\s+(.+)$/m', '<li>$1</li>', $text) ?? $text;

        // Paragraphs (blank-line separated)
        $text = preg_replace('/\n{2,}/', '</p><p>', trim($text)) ?? $text;
        $text = '<p>' . $text . '</p>';

        // Clean up <p> around block elements
        $blockTags = 'h[1-6]|ul|ol|li|blockquote|pre|hr';
        $text = preg_replace("/<p>(<(?:{$blockTags})[^>]*>)/", '$1', $text) ?? $text;
        $text = preg_replace("/(</(?:{$blockTags})>)<\/p>/", '$1', $text) ?? $text;

        return $text;
    }

    // =========================================================================
    // Logging
    // =========================================================================

    /**
     * Writes a log entry to the Luna log file (if logging is enabled).
     *
     * @param string               $level   Log level (DEBUG, INFO, WARNING, ERROR).
     * @param string               $message Log message.
     * @param array<string, mixed> $context Optional context data (JSON-encoded).
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

    /**
     * Resolves the dot-notation view key from an absolute view path.
     *
     * @param string $viewPath Absolute file path.
     * @return string Dot-notation view key.
     */
    private function resolveViewKey(string $viewPath): string
    {
        $relative = str_replace(self::$viewsDir . DIRECTORY_SEPARATOR, '', $viewPath);
        $relative = str_replace('.luna.php', '', $relative);
        return str_replace(DIRECTORY_SEPARATOR, '.', $relative);
    }

    /**
     * Formats a byte count into a human-readable string.
     *
     * @param int $bytes Size in bytes.
     * @return string Formatted size string (e.g. "1.25 MB").
     */
    private static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1_048_576) {
            return round($bytes / 1_048_576, 2) . ' MB';
        }
        if ($bytes >= 1_024) {
            return round($bytes / 1_024, 2) . ' KB';
        }
        return $bytes . ' B';
    }
}