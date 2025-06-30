<?php
/*
|--------------------------------------------------------------------------
| Classe Template
|--------------------------------------------------------------------------
|
| Esta classe renderiza templates PHP com suporte a layouts, seções,
| inclusão de templates e diretivas como @if, @foreach e @csrf.
|
*/

declare(strict_types=1);

namespace Slenix\Libraries;

/**
 * Classe para renderizar templates PHP com suporte a layouts e seções.
 */
class Template
{
    /**
     * Caminho completo para o arquivo de visualização.
     *
     * @var string
     */
    private string $viewpath = '';

    /**
     * Dados a serem passados para a visualização.
     *
     * @var array<string, mixed>
     */
    private array $data = [];

    /**
     * Seções definidas no template.
     *
     * @var array<string, string>
     */
    private array $section = [];

    /**
     * Layout a ser estendido pela visualização.
     *
     * @var string
     */
    private string $layout = '';

    /**
     * Nome da seção atualmente aberta.
     *
     * @var string
     */
    private string $currentsection = '';

    /**
     * Dados globais compartilhados entre todas as visualizações.
     *
     * @var array<string, mixed>
     */
    private static array $globalData = [];

    /**
     * Construtor da classe Template.
     *
     * @param string $template Nome do template (com '.' para subdiretórios).
     * @param array<string, mixed> $data Dados a serem passados para o template.
     */
    public function __construct(string $template, array $data = [])
    {
        $this->viewpath = __DIR__ . '/../../views/' . str_replace('.', '/', $template) . '.luna.php';
        $this->data = array_merge(self::$globalData, $data);
    }

    /**
     * Define dados globais para todas as visualizações.
     *
     * @param string $key A chave dos dados.
     * @param mixed $value O valor dos dados.
     * @return void
     */
    public static function share(string $key, mixed $value): void
    {
        self::$globalData[$key] = $value;
    }

    /**
     * Verifica se uma seção foi definida.
     *
     * @param string $section Nome da seção.
     * @return bool
     */
    public function hasSection(string $section): bool
    {
        return isset($this->section[$section]);
    }

    /**
     * Renderiza o template e retorna o conteúdo.
     *
     * @throws \Exception Se o arquivo de visualização não existir.
     * @return string O conteúdo renderizado.
     */
    public function render(): string
    {
        if (!file_exists($this->viewpath)) {
            throw new \Exception('View ' . $this->viewpath . ' not exists');
        }

        $content_view = file_get_contents($this->viewpath);
        $compile_template = $this->compile($content_view);

        ob_start();
        extract($this->data, EXTR_SKIP);
        eval('?>' . $compile_template);
        $output = ob_get_clean();

        if ($this->layout) {
            $layoutEngine = new self($this->layout, $this->data);
            $layoutEngine->section = $this->section;
            return $layoutEngine->render();
        }

        return $output;
    }

    /**
     * Renderiza um template incluído.
     *
     * @param string $template Nome do template a incluir.
     * @return string O conteúdo renderizado.
     */
    private function renderInclude(string $template): string
    {
        $includePath = __DIR__ . '/../../views/' . str_replace('.', '/', $template) . '.php';
        if (file_exists($includePath)) {
            ob_start();
            extract($this->data, EXTR_SKIP);
            include $includePath;
            return ob_get_clean();
        }
        return '';
    }

    /**
     * Renderiza o conteúdo de uma seção.
     *
     * @param string $section Nome da seção.
     * @param string $default Conteúdo padrão se a seção não existir.
     * @return string
     */
    private function renderYield(string $section, string $default = ''): string
    {
        return $this->section[$section] ?? $default;
    }

    /**
     * Compila o conteúdo do template, substituindo diretivas por código PHP.
     *
     * @param string $content Conteúdo do template.
     * @return string Conteúdo compilado.
     */
    private function compile(string $content): string
    {
        $patterns = [
            '/\{\{\s*(.+?)\s*\}\}/' => function ($matches) {
                $expression = trim($matches[1]);
                if (preg_match('/^[a-zA-Z0-9\s,._-]+$/i', $expression) && !preg_match('/^\$|[()=+\-*\/<>]|\bfunction\b/i', $expression)) {
                    return '<?php echo htmlspecialchars(\'' . addslashes($expression) . '\', ENT_QUOTES, \'UTF-8\'); ?>';
                }
                return '<?php echo htmlspecialchars(' . $expression . ', ENT_QUOTES, \'UTF-8\'); ?>';
            },
            '/\{\!\!\s*(.+?)\s*\!\!\}/' => '<?php echo $1; ?>',
            '/@if\s*\(((?:[^()]*|\([^()]*\))*)\)/' => '<?php if($1): ?>',
            '/@elseif\s*\(((?:[^()]*|\([^()]*\))*)\)/' => '<?php elseif($1): ?>',
            '/@else/' => '<?php else: ?>',
            '/@endif/' => '<?php endif; ?>',
            '/@foreach\s*\(((?:[^()]*|\([^()]*\))*)\)/' => '<?php foreach($1): ?>',
            '/@endforeach/' => '<?php endforeach; ?>',
            '/@php\s*(.*?)\s*@endphp/' => '<?php $1 ?>',
            '/@include\s*\(\s*[\'"]?(.*?)[\'"]?\s*\)/' => '<?php echo $this->renderInclude(\'$1\'); ?>',
            '/@extends\s*\(\s*[\'"]?(.*?)[\'"]?\s*\)/' => '<?php $this->layout = \'$1\'; ?>',
            '/@section\s*\(\s*[\'"]?(.*?)[\'"]?\s*\)/' => '<?php ob_start(); $this->currentsection = \'$1\'; ?>',
            '/@endsection/' => '<?php $this->section[$this->currentsection] = ob_get_clean(); $this->currentsection = \'\'; ?>',
            '/@yield\s*\(\s*[\'"]?(.*?)[\'"]?(?:\s*,\s*[\'"]?(.*?)[\'"]?)?\s*\)/' => '<?php echo $this->renderYield(\'$1\', \'$2\'); ?>',
            '/@for\s*\(((?:[^()]*|\([^()]*\))*)\)/' => '<?php for($1): ?>',
            '/@endfor/' => '<?php endfor; ?>',
            '/@while\s*\(((?:[^()]*|\[^()]*\))*)\)/' => '<?php while($1): ?>',
            '/@endwhile/' => '<?php endwhile; ?>',
            '/@csrf/' => '<?php $csrf = \Slenix\Http\Auth\Csrf::generateToken(); ?><input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, \'UTF-8\') ?>"><meta name="csrf-token" content="<?= htmlspecialchars($csrf, ENT_QUOTES, \'UTF-8\') ?>">',
            '/@continue(\s*\(((?:[^()]*|\[^()]*\))*)\))?/' => '<?php if$1 continue; ?>',
            '/@break(\s*\(((?:[^()]*|\[^()]*\))*)\))?/' => '<?php if$1 break; ?>',
        ];

        foreach ($patterns as $pattern => $replacement) {
            if (is_callable($replacement)) {
                $content = preg_replace_callback($pattern, $replacement, $content);
            } else {
                $content = preg_replace($pattern, $replacement, $content);
            }
        }

        return $content;
    }
}
