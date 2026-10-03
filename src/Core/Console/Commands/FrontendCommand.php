<?php

/*
|--------------------------------------------------------------------------
| FrontendCommand Class — Slenix Framework
|--------------------------------------------------------------------------
|
| Scaffolds the frontend stack (Luna only, React or Vue) by copying the
| matching stub from stubs/frontend/<stack> into the project root.
| After a successful run the stubs directory is removed, since it is only
| needed once, at project creation.
|
|   php celestial frontend:install                 (interactive)
|   php celestial frontend:install react
|   php celestial frontend:install vue --no-install --force --keep-stubs
|
*/

declare(strict_types=1);

namespace Slenix\Core\Console\Commands;

use Slenix\Core\Console\Command;
use Slenix\Core\Console\Prompt;

class FrontendCommand extends Command
{
    /** @var array<string, string> stack key → menu label */
    private const STACKS = [
        'luna'  => 'Luna only (server-side templates, no Node)',
        'react' => 'React',
        'vue'   => 'Vue',
    ];

    /** @var array<int, string> */
    private array $args;

    /**
     * @param array<int, string> $args Raw argv forwarded from celestial.
     */
    public function __construct(array $args)
    {
        $this->args = $args;
    }

    public function install(): void
    {
        $interactive = $this->isInteractive();
        $force       = $this->hasFlag('--force');
        $keepStubs   = $this->hasFlag('--keep-stubs');
        $skipNpm     = $this->hasFlag('--no-install');

        $stack = $this->resolveStack($interactive);

        if ($stack !== 'luna') {
            if (!$this->copyStack($stack, $force, $interactive)) {
                return; // aborted or failed: stubs are kept
            }

            self::success(self::STACKS[$stack] . ' stack installed.');

            if (!$skipNpm) {
                $this->runNpmInstall($interactive);
            }
        } else {
            self::success('Using Luna templates only. No Node tooling added.');
        }

        if (!$keepStubs) {
            $this->removeStubs();
        }

        $this->printNextSteps($stack);
    }

    // -------------------------------------------------------------------------
    // Stack selection
    // -------------------------------------------------------------------------

    private function resolveStack(bool $interactive): string
    {
        foreach (array_slice($this->args, 2) as $arg) {
            if (str_starts_with($arg, '-')) {
                continue;
            }

            $key = strtolower($arg);

            if (!isset(self::STACKS[$key])) {
                self::error("Unknown stack '{$arg}'. Use: luna, react or vue.");
                exit(1);
            }

            return $key;
        }

        if (!$interactive) {
            self::info('Non-interactive terminal detected. Defaulting to Luna only.');
            return 'luna';
        }

        $prompt = new Prompt();
        $label  = $prompt->select(
            'Which frontend stack do you want to use?',
            array_values(self::STACKS)
        );
        unset($prompt); // restores the terminal mode before we continue

        return (string) array_search($label, self::STACKS, true);
    }

    // -------------------------------------------------------------------------
    // Copy
    // -------------------------------------------------------------------------

    /**
     * @return bool True when files were copied, false when aborted.
     */
    private function copyStack(string $stack, bool $force, bool $interactive): bool
    {
        $source = $this->path("stubs/frontend/{$stack}");

        if (!is_dir($source)) {
            self::error("Stub for '{$stack}' not found at stubs/frontend/{$stack}.");
            self::info('The stubs are removed after the first install. Use --keep-stubs to keep them.');
            return false;
        }

        $files     = $this->listFiles($source);
        $conflicts = array_values(array_filter(
            $files,
            fn(string $f): bool => file_exists($this->path($f))
        ));

        if ($conflicts !== [] && !$force) {
            self::warning('These files already exist:');
            foreach ($conflicts as $f) {
                echo '  ' . self::console()->muted($f) . PHP_EOL;
            }

            if (!$interactive) {
                self::error('Aborting. Use --force to overwrite.');
                return false;
            }

            $overwrite = (new Prompt())->confirm('Overwrite existing files?', false);

            if (!$overwrite) {
                self::info('Nothing was changed.');
                return false;
            }
        }

        foreach ($files as $relative) {
            $target = $this->path($relative);
            $dir    = dirname($target);

            if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
                self::error("Could not create directory {$dir}.");
                return false;
            }

            if (!copy($source . '/' . $relative, $target)) {
                self::error("Could not write {$relative}.");
                return false;
            }
        }

        return true;
    }

    // -------------------------------------------------------------------------
    // npm
    // -------------------------------------------------------------------------

    private function runNpmInstall(bool $interactive): void
    {
        if (!$this->commandExists('npm')) {
            self::warning('npm was not found. Install Node.js, then run: npm install');
            return;
        }

        if (!$interactive) {
            self::info('Skipping npm install (non-interactive). Run it manually.');
            return;
        }

        if (!(new Prompt())->confirm('Run "npm install" now?', true)) {
            return;
        }

        chdir($this->path(''));
        passthru('npm install', $code);

        if ($code !== 0) {
            self::warning('npm install failed. Run it manually later.');
        }
    }

    private function commandExists(string $cmd): bool
    {
        $probe = DIRECTORY_SEPARATOR === '\\'
            ? "where {$cmd} 2>nul"
            : "command -v {$cmd} 2>/dev/null";

        return trim((string) shell_exec($probe)) !== '';
    }

    // -------------------------------------------------------------------------
    // Stub cleanup
    // -------------------------------------------------------------------------

    private function removeStubs(): void
    {
        $frontend = $this->path('stubs/frontend');
        $stubs    = $this->path('stubs');

        if (is_dir($frontend)) {
            $this->deleteDir($frontend);
        }

        if (is_dir($stubs) && count(scandir($stubs) ?: []) <= 2) {
            @rmdir($stubs);
        }

        if (!is_dir($frontend)) {
            self::info('Installer stubs removed.');
        }
    }

    private function deleteDir(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() && !$item->isLink()
                ? @rmdir($item->getPathname())
                : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }

    // -------------------------------------------------------------------------
    // Output
    // -------------------------------------------------------------------------

    private function printNextSteps(string $stack): void
    {
        $c = self::console();

        self::newLine();

        if ($stack !== 'luna') {
            echo '  ' . $c->white('1.') . ' Add the entry route to routes/web.php:' . PHP_EOL;
            self::newLine();
            echo '     ' . $c->colorize("Router::get('/app', fn() => view('app'));", 'success') . PHP_EOL;
            self::newLine();
            echo '  ' . $c->white('2.') . ' ' . $c->colorize('npm install', 'success')
                . $c->muted('   (if you skipped it)') . PHP_EOL;
            echo '  ' . $c->white('3.') . ' ' . $c->colorize('npm run dev', 'success')
                . $c->muted('       (terminal 1)') . PHP_EOL;
            echo '  ' . $c->white('4.') . ' ' . $c->colorize('php celestial serve', 'success')
                . $c->muted('  (terminal 2)') . PHP_EOL;
        } else {
            echo '  ' . $c->muted('Run ') . $c->colorize('php celestial serve', 'success', true)
                . $c->muted(' to start building.') . PHP_EOL;
        }

        self::newLine();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function isInteractive(): bool
    {
        if ($this->hasFlag('--no-interaction') || $this->hasFlag('-n')) {
            return false;
        }

        return function_exists('stream_isatty') && stream_isatty(STDIN);
    }

    private function hasFlag(string $flag): bool
    {
        return in_array($flag, $this->args, true);
    }

    /**
     * @return array<int, string> Paths relative to $dir, using "/" separators.
     */
    private function listFiles(string $dir): array
    {
        $out = [];
        $it  = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $file) {
            if ($file->isFile()) {
                $out[] = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
            }
        }

        sort($out);
        return $out;
    }

    /** Project root is 4 levels above src/Core/Console/Commands. */
    private function path(string $relative): string
    {
        return rtrim(dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . ltrim($relative, '/\\'), '/\\');
    }
}