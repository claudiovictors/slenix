<?php

/*
|--------------------------------------------------------------------------
| Vite Asset Manager
|--------------------------------------------------------------------------
|
| Handles Vite integration for the Slenix framework.
| Manages HMR (Hot Module Replacement) during development and resolves
| compiled assets via manifest.json for production builds.
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Vite;

use RuntimeException;

/**
 * Class Vite
 * 
 * Gerencia a integração do Vite no framework, oferecendo suporte
 * para ambiente de desenvolvimento (HMR/Hot Reloading) e produção via manifest.json.
 * 
 * @package Slenix\Supports\Vite
 */
class Vite
{
    /**
     * @var self|null Instância única compartilhada da classe (Singleton pattern).
     */
    private static ?self $instance = null;

    /**
     * @var string Caminho absoluto para o diretório público.
     */
    private string $publicPath;

    /**
     * @var string Nome do diretório onde os assets compilados são salvos.
     */
    private string $buildDir;

    /**
     * @var array<string, mixed>|null Conteúdo do arquivo de manifest do Vite em cache.
     */
    private ?array $manifest = null;

    /**
     * Construtor da classe Vite.
     *
     * @param string|null $publicPath Caminho público base da aplicação. Se nulo, resolve automaticamente.
     * @param string $buildDir Nome da pasta contendo os arquivos compilados pelo Vite.
     */
    public function __construct(?string $publicPath = null, string $buildDir = 'build')
    {
        $default = defined('PUBLIC_PATH') ? PUBLIC_PATH : dirname(__DIR__, 3) . '/public';
        $this->publicPath = rtrim($publicPath ?? $default, '/\\');
        $this->buildDir   = trim($buildDir, '/');
    }

    /**
     * Instância compartilhada (usada pela diretiva @vite e pelo helper vite()).
     *
     * @return self Instância singleton da classe Vite.
     */
    public static function instance(): self
    {
        if (self::$instance === null) {
            $dir = function_exists('config') ? (config('app.vite.build_dir') ?: 'build') : 'build';
            self::$instance = new self(null, (string) $dir);
        }
        return self::$instance;
    }

    /**
     * Atalho estático: Vite::tags(['resources/css/app.css', 'resources/js/app.jsx'])
     *
     * @param string|array<int, string> $entries Entrada ou lista de entradas de arquivos/assets.
     * @return string HTML gerado com as tags <script> e <link>.
     * @throws RuntimeException Se um entrypoint não for encontrado no manifest em ambiente de produção.
     */
    public static function tags(string|array $entries): string
    {
        return self::instance()->render($entries);
    }

    /**
     * Renderiza as tags de assets dependendo do ambiente atual (Dev/Hot ou Produção).
     *
     * @param string|array<int, string> $entries Entrada ou lista de entradas de arquivos/assets.
     * @return string Tags HTML finalizadas.
     * @throws RuntimeException Se o asset não for localizado no arquivo de manifest.
     */
    public function render(string|array $entries): string
    {
        $entries = (array) $entries;

        return $this->isRunningHot()
            ? $this->renderDev($entries)
            : $this->renderBuild($entries);
    }

    // ── DEV ──────────────────────────────────────────────────────────────

    /**
     * Obtém o caminho do arquivo indicativo do servidor Hot Reload ('hot').
     *
     * @return string Caminho completo do arquivo 'hot'.
     */
    private function hotFile(): string
    {
        return $this->publicPath . '/hot';
    }

    /**
     * Verifica se o servidor de desenvolvimento do Vite está em execução.
     *
     * @return bool True se o arquivo 'hot' existir, false caso contrário.
     */
    private function isRunningHot(): bool
    {
        return is_file($this->hotFile());
    }

    /**
     * Lê a URL base do servidor de desenvolvimento contida no arquivo 'hot'.
     *
     * @return string URL base do Vite Dev Server.
     */
    private function hotUrl(): string
    {
        return rtrim(trim((string) file_get_contents($this->hotFile())), '/');
    }

    /**
     * Gera o código HTML necessário para carregar os assets no modo de desenvolvimento.
     *
     * @param array<int, string> $entries Lista de arquivos de entrada.
     * @return string Bloco de HTML com scripts e React Refresh (se aplicável).
     */
    private function renderDev(array $entries): string
    {
        $url  = $this->hotUrl();
        $html = '';

        if ($this->usesReact($entries)) {
            $html .= $this->reactRefresh($url);
        }

        $html .= '<script type="module" src="' . $this->e($url . '/@vite/client') . '"></script>' . "\n";

        foreach ($entries as $entry) {
            $html .= $this->tag($url . '/' . ltrim($entry, '/')) . "\n";
        }

        return $html;
    }

    // ── PRODUÇÃO ─────────────────────────────────────────────────────────

    /**
     * Gera o código HTML necessário para carregar os assets em ambiente de produção usando o manifest.json.
     *
     * @param array<int, string> $entries Lista de arquivos de entrada.
     * @return string Bloco de HTML com as tags <link rel="modulepreload"> e <script>/<link>.
     * @throws RuntimeException Se o entrypoint especificado não existir no manifest.
     */
    private function renderBuild(array $entries): string
    {
        $manifest = $this->manifest();
        $tags     = [];
        $preloads = [];

        foreach ($entries as $entry) {
            if (!isset($manifest[$entry])) {
                throw new RuntimeException("Vite: entrypoint [{$entry}] não encontrado no manifest.");
            }

            $css  = [];
            $imps = [];
            $this->walk($manifest, $entry, $css, $imps);

            foreach ($css as $file) {
                $tags[$this->asset($file)] = true;
            }
            foreach ($imps as $file) {
                $preloads[$this->asset($file)] = true;
            }

            // O próprio arquivo do entrypoint (js ou css)
            $tags[$this->asset($manifest[$entry]['file'])] = true;
        }

        $html = '';
        foreach (array_keys($preloads) as $url) {
            $html .= '<link rel="modulepreload" href="' . $this->e($url) . '">' . "\n";
        }
        foreach (array_keys($tags) as $url) {
            $html .= '<link rel="stylesheet" href="' . $this->e($url) . '">' . "\n"; // mantido comportamento do seu loop original
        }

        return $html;
    }

    /**
     * Percorre imports recursivamente coletando CSS e chunks para preload.
     *
     * @param array<string, mixed> $manifest Dados do arquivo manifest.json.
     * @param string $key Chave do módulo no manifest.
     * @param array<int, string> $css Passado por referência. Coleção de arquivos CSS descobertos.
     * @param array<int, string> $imports Passado por referência. Coleção de imports descobertos.
     * @param array<string, bool> $seen Passado por referência. Registro de chaves já visitadas para evitar loops.
     * @return void
     */
    private function walk(array $manifest, string $key, array &$css, array &$imports, array &$seen = []): void
    {
        if (isset($seen[$key]) || !isset($manifest[$key])) {
            return;
        }
        $seen[$key] = true;

        foreach ($manifest[$key]['css'] ?? [] as $file) {
            $css[] = $file;
        }

        foreach ($manifest[$key]['imports'] ?? [] as $import) {
            if (isset($manifest[$import])) {
                $imports[] = $manifest[$import]['file'];
                $this->walk($manifest, $import, $css, $imports, $seen);
            }
        }
    }

    /**
     * Carrega e converte o arquivo manifest.json em array.
     *
     * @return array<string, mixed> Dados do manifest.json.
     * @throws RuntimeException Se nenhum arquivo manifest.json for localizado.
     */
    private function manifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        $base = $this->publicPath . '/' . $this->buildDir;

        foreach (["{$base}/.vite/manifest.json", "{$base}/manifest.json"] as $path) {
            if (is_file($path)) {
                return $this->manifest = json_decode((string) file_get_contents($path), true) ?: [];
            }
        }

        throw new RuntimeException('Vite: manifest não encontrado. Rode "npm run build" ou "npm run dev".');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Gera a tag HTML adequada (<link> ou <script>) de acordo com a extensão do arquivo.
     *
     * @param string $url URL do asset.
     * @return string Tag HTML formatada.
     */
    private function tag(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: $url;

        return preg_match('/\.(css|less|scss|sass|styl|stylus|pcss|postcss)$/i', $path)
            ? '<link rel="stylesheet" href="' . $this->e($url) . '">'
            : '<script type="module" src="' . $this->e($url) . '"></script>';
    }

    /**
     * Formata o caminho público final para um arquivo de build.
     *
     * @param string $file Nome do arquivo no diretório de build.
     * @return string Caminho absoluto/relativo web formatado.
     */
    private function asset(string $file): string
    {
        return '/' . $this->buildDir . '/' . ltrim($file, '/');
    }

    /**
     * Verifica se algum dos entrypoints utiliza React (arquivos .jsx ou .tsx).
     *
     * @param array<int, string> $entries Lista de entradas.
     * @return bool True se contiver extensões do React, false caso contrário.
     */
    private function usesReact(array $entries): bool
    {
        foreach ($entries as $e) {
            if (preg_match('/\.(jsx|tsx)$/i', $e)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Gera o script inline necessário para habilitar o React Fast Refresh em dev.
     *
     * @param string $url URL base do Vite Dev Server.
     * @return string Tag <script> do preamble do React Refresh.
     */
    private function reactRefresh(string $url): string
    {
        $u = $this->e($url);

        return <<<HTML
<script type="module">
  import RefreshRuntime from '{$u}/@react-refresh'
  RefreshRuntime.injectIntoGlobalHook(window)
  window.\$RefreshReg\$ = () => {}
  window.\$RefreshSig\$ = () => (type) => type
  window.__vite_plugin_react_preamble_installed__ = true
</script>

HTML;
    }

    /**
     * Escapa valores para prevenção de XSS ao renderizar atributos nas tags HTML.
     *
     * @param string $v String a ser escapada.
     * @return string String sanitizada.
     */
    private function e(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}