<?php

/*
|--------------------------------------------------------------------------
| UpdateCommand — Slenix Framework
|--------------------------------------------------------------------------
|
| php celestial self:update                Update to the latest published version.
| php celestial self:update --check        Only compare versions; change nothing.
| php celestial self:update --dry-run      Show what would change; change nothing.
| php celestial self:update --to=3.2.3     Install a specific version.
| php celestial self:update --pre          Consider pre-release tags too.
| php celestial self:update --force        Skip confirmations (required without a TTY).
| php celestial self:rollback              Restore the most recent backup.
|
| What is touched, in three levels:
|   framework  src/, celestial, stubs/ (only when it still exists)   → replaced
|   shared     public/index.php                                      → replaced on confirmation
|              composer.json, .env.example                           → reported, never overwritten
|   yours      app/, routes/, views/, database/, .env, storage/, public/css → never touched
|
| Every update first copies what it will replace to storage/backups/.
|
| Place at: src/Core/Console/Commands/UpdateCommand.php
|
*/

declare(strict_types=1);

namespace Slenix\Core\Console\Commands;

use Slenix\Core\Console\Command;
use Slenix\Core\Foundation\ReleaseChecker;
use Slenix\Core\Foundation\Version;

class UpdateCommand extends Command
{
    /** @var array<int, string> CLI arguments received from the Celestial entry point. */
    private array $args;

    /** Max file names listed per group in the plan output. */
    private const LIST_LIMIT = 20;

    /**
     * @param array<int, string> $args Raw argv array forwarded from the Celestial CLI.
     */
    public function __construct(array $args)
    {
        $this->args = $args;
    }

    // =========================================================================
    // self:update
    // =========================================================================

    /**
     * Checks for a newer version and, unless --check is given, applies it.
     *
     * @return void
     */
    public function run(): void
    {
        $c       = self::console();
        $current = Version::CURRENT;
        $checker = new ReleaseChecker();

        self::newLine();
        echo '  ' . $c->muted('Installed version: ') . $c->white($current) . PHP_EOL;

        try {
            $target = $this->resolveTarget($checker);

            if ($target === null) {
                self::newLine();
                self::warning('No published versions were found for this project.');
                self::newLine();
                return;
            }

            echo '  ' . $c->muted('Target version:    ') . $c->white($target) . PHP_EOL;
            self::newLine();

            if (!$this->reportComparison($checker, $current, $target)) {
                return;
            }

            if ($this->hasFlag('--check')) {
                self::info('Run "php celestial self:update" to apply it.');
                self::newLine();
                return;
            }

            $this->apply($checker, $current, $target);
        } catch (\RuntimeException $e) {
            self::newLine();
            self::error($e->getMessage());
            self::newLine();
            exit(1);
        }
    }

    /**
     * Picks the version to install: --to=X when given, otherwise the latest tag.
     *
     * @return string|null Null when the repository has no versions.
     *
     * @throws \RuntimeException When --to points at a version that was not published.
     */
    private function resolveTarget(ReleaseChecker $checker): ?string
    {
        $to = $this->option('--to=');

        if ($to === null) {
            return $checker->latest($this->hasFlag('--pre'));
        }

        $to = ltrim($to, 'vV');

        if (!in_array($to, $checker->versions(true), true)) {
            throw new \RuntimeException("Version {$to} was not found among the published tags.");
        }

        return $to;
    }

    /**
     * Prints how the installed version relates to the target.
     *
     * @return bool True when the update should continue.
     */
    private function reportComparison(ReleaseChecker $checker, string $current, string $target): bool
    {
        $c          = self::console();
        $comparison = version_compare($current, $target);

        if ($comparison === 0) {
            self::success('You are already on this version.');
            self::newLine();
            return false;
        }

        if ($comparison > 0) {
            if ($this->option('--to=') === null) {
                self::info("Your version ({$current}) is newer than the latest published one ({$target}).");
                self::newLine();
                return false;
            }

            self::warning("This is a DOWNGRADE ({$current} -> {$target}).");
            return true;
        }

        self::warning("A new version is available: {$current} -> {$target}");

        if ((int) explode('.', $current)[0] !== (int) explode('.', $target)[0]) {
            self::warning('This is a MAJOR upgrade and may contain breaking changes. Read the release notes first.');
        }

        echo '  ' . $c->muted('What changed: ') . $checker->compareUrl($current, $target) . PHP_EOL;
        self::newLine();

        return true;
    }

    /**
     * Downloads, plans, backs up and applies the update.
     *
     * Every failure is thrown as RuntimeException so the temp directory is
     * always cleaned (exit() would skip the finally block).
     *
     * @throws \RuntimeException
     */
    private function apply(ReleaseChecker $checker, string $current, string $target): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('The PHP zip extension (ext-zip) is required to update.');
        }

        $work = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'slenix-update-' . getmypid();

        try {
            self::info("Downloading Slenix {$target}...");
            $new = $this->fetchRelease($checker, $target, $work);

            $declared = $this->releaseVersion($new);
            if ($declared !== $target) {
                self::warning("The release declares version " . ($declared ?? 'unknown') . " in Version.php, not {$target}.");

                if (!$this->hasFlag('--force')) {
                    throw new \RuntimeException('Aborting: the release was probably tagged before bumping Version::CURRENT. Use --force to continue anyway.');
                }
            }

            // The installed version's release is the baseline used to tell
            // "you edited this file" apart from "upstream changed this file".
            $base = null;
            try {
                self::info("Downloading Slenix {$current} to detect local changes...");
                $base = $this->fetchRelease($checker, $current, $work);
            } catch (\RuntimeException $e) {
                self::warning('Local-change detection skipped: ' . $e->getMessage());
            }

            $plan = $this->buildPlan($new, $base);
            $this->printPlan($plan);

            if ($this->hasFlag('--dry-run')) {
                self::info('Dry run: nothing was changed.');
                self::newLine();
                return;
            }

            if (!$this->confirm("Apply the update {$current} -> {$target}?")) {
                self::warning('Update cancelled.');
                self::newLine();
                return;
            }

            if ($plan['index'] && !$this->confirm('public/index.php changed upstream. Replace it?')) {
                $plan['index'] = false;
            }

            $paths = $plan['paths'];
            if ($plan['index']) {
                $paths[] = 'public/index.php';
            }

            $backup = $this->createBackup($current, $paths);
            self::info('Backup saved to ' . str_replace($this->root() . '/', '', $backup));

            try {
                foreach ($paths as $path) {
                    is_dir($new . '/' . $path)
                        ? $this->swapDir($new . '/' . $path, $this->root($path))
                        : $this->swapFile($new . '/' . $path, $this->root($path));
                }
            } catch (\Throwable $e) {
                self::error('Update failed: ' . $e->getMessage());
                self::warning('Restoring the backup...');
                $this->restoreBackup($backup);
                throw new \RuntimeException('The previous version was restored.');
            }

            $this->dumpAutoload();

            self::newLine();
            self::success("Slenix updated to {$target}.");
            foreach ($plan['review'] as $file) {
                self::warning("{$file} differs from the new release. Review it manually.");
            }
            self::info('Run "php celestial view:clear" and test your application.');
            self::info('To undo: php celestial self:rollback');
            self::newLine();
        } finally {
            $this->deleteDir($work);
        }
    }

    // =========================================================================
    // Planning
    // =========================================================================

    /**
     * Compares the project with the new release and decides what is replaced.
     *
     * @param  string      $new  Extracted target release.
     * @param  string|null $base Extracted release of the installed version, if available.
     * @return array{paths: string[], added: string[], changed: string[], removed: string[],
     *               modified: string[], userAdded: string[], index: bool, review: string[]}
     */
    private function buildPlan(string $new, ?string $base): array
    {
        $paths = ['src', 'celestial'];

        // stubs/ is deleted by frontend:install on purpose; do not bring it back.
        if (is_dir($this->root('stubs')) && is_dir($new . '/stubs')) {
            $paths[] = 'stubs';
        }

        $local   = $this->fingerprint($this->root('src'));
        $target  = $this->fingerprint($new . '/src');
        $baseFp  = $base !== null ? $this->fingerprint($base . '/src') : null;

        $userAdded = $baseFp === null ? [] : array_keys(array_diff_key($local, $baseFp));

        return [
            'paths'     => $paths,
            'added'     => array_keys(array_diff_key($target, $local)),
            'changed'   => array_keys(array_filter($target, static fn($h, $f): bool => isset($local[$f]) && $local[$f] !== $h, ARRAY_FILTER_USE_BOTH)),
            'removed'   => array_values(array_diff(array_keys(array_diff_key($local, $target)), $userAdded)),
            'modified'  => $baseFp === null ? [] : array_keys(array_filter($local, static fn($h, $f): bool => isset($baseFp[$f]) && $baseFp[$f] !== $h, ARRAY_FILTER_USE_BOTH)),
            'userAdded' => $userAdded,
            'index'     => $this->differs($new . '/public/index.php', $this->root('public/index.php')),
            'review'    => array_values(array_filter(
                ['composer.json', '.env.example'],
                fn(string $f): bool => $this->differs($new . '/' . $f, $this->root($f))
            )),
        ];
    }

    /**
     * Prints the plan so the user knows what is about to happen.
     *
     * @param array<string, mixed> $plan
     */
    private function printPlan(array $plan): void
    {
        $c = self::console();

        self::newLine();
        echo '  ' . $c->white('Framework files (src/)') . PHP_EOL;
        $this->printGroup('Added', $plan['added'], 'success');
        $this->printGroup('Changed', $plan['changed'], 'warning');
        $this->printGroup('Removed upstream', $plan['removed'], 'error');

        if ($plan['modified'] !== []) {
            self::newLine();
            self::warning('You edited these framework files. The update OVERWRITES them (they stay in the backup):');
            $this->printGroup('Modified by you', $plan['modified'], 'warning');
        }

        if ($plan['userAdded'] !== []) {
            self::newLine();
            self::warning('These files exist only in your src/. They will be DELETED (kept in the backup):');
            $this->printGroup('Added by you', $plan['userAdded'], 'error');
        }

        self::newLine();
        echo '  ' . $c->muted('Also replaced: ') . implode(', ', array_diff($plan['paths'], ['src'])) . PHP_EOL;
        echo '  ' . $c->muted('Never touched: app/, routes/, views/, database/, .env, storage/, public/css') . PHP_EOL;
        self::newLine();
    }

    /**
     * @param string[] $files
     */
    private function printGroup(string $label, array $files, string $color): void
    {
        if ($files === []) {
            return;
        }

        $c = self::console();
        echo '    ' . $c->colorize($label . ' (' . count($files) . ')', $color) . PHP_EOL;

        foreach (array_slice($files, 0, self::LIST_LIMIT) as $file) {
            echo '      ' . $c->muted($file) . PHP_EOL;
        }

        if (count($files) > self::LIST_LIMIT) {
            echo '      ' . $c->muted('... and ' . (count($files) - self::LIST_LIMIT) . ' more') . PHP_EOL;
        }
    }

    // =========================================================================
    // Download
    // =========================================================================

    /**
     * Downloads and extracts a release; returns the folder that holds its files.
     *
     * GitHub zips contain a single top-level folder ("slenix-3.2.3/").
     *
     * @throws \RuntimeException
     */
    private function fetchRelease(ReleaseChecker $checker, string $version, string $work): string
    {
        if (!is_dir($work) && !mkdir($work, 0755, true)) {
            throw new \RuntimeException("Could not create the temporary directory {$work}.");
        }

        $zipPath = "{$work}/{$version}.zip";
        file_put_contents($zipPath, $checker->archive($version));

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException("The downloaded archive for {$version} is not a valid zip.");
        }

        // Reject path traversal ("zip slip") before extracting anything.
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (str_starts_with($name, '/') || str_contains($name, '..')) {
                $zip->close();
                throw new \RuntimeException('The archive contains an unsafe path. Aborting.');
            }
        }

        $out = "{$work}/{$version}";
        $zip->extractTo($out);
        $zip->close();

        $dirs = glob($out . '/*', GLOB_ONLYDIR) ?: [];
        if (count($dirs) !== 1) {
            throw new \RuntimeException('Unexpected archive layout.');
        }

        return $dirs[0];
    }

    /** Reads Version::CURRENT out of an extracted release without loading it. */
    private function releaseVersion(string $releaseDir): ?string
    {
        $file = $releaseDir . '/src/Core/Foundation/Version.php';

        if (!is_file($file)) {
            return null;
        }

        return preg_match("/CURRENT\s*=\s*'([^']+)'/", (string) file_get_contents($file), $m) ? $m[1] : null;
    }

    // =========================================================================
    // Backup & rollback
    // =========================================================================

    /**
     * Copies everything about to be replaced into storage/backups/.
     *
     * @param  string[] $paths Project-relative paths.
     * @return string Backup directory.
     *
     * @throws \RuntimeException
     */
    private function createBackup(string $version, array $paths): string
    {
        $dir = $this->root('storage/backups/slenix-' . $version . '-' . date('Ymd-His'));

        if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Could not create the backup directory {$dir}.");
        }

        foreach ($paths as $path) {
            $from = $this->root($path);
            $to   = $dir . '/' . $path;

            if (is_dir($from)) {
                $this->copyDir($from, $to);
                continue;
            }

            if (!is_dir(dirname($to))) {
                mkdir(dirname($to), 0755, true);
            }

            if (is_file($from) && !copy($from, $to)) {
                throw new \RuntimeException("Could not back up {$path}.");
            }
        }

        file_put_contents(
            $dir . '/manifest.json',
            json_encode(['version' => $version, 'created' => time(), 'paths' => $paths], JSON_PRETTY_PRINT)
        );

        return $dir;
    }

    /**
     * Restores a backup created by createBackup().
     *
     * @throws \RuntimeException
     */
    private function restoreBackup(string $backupDir): void
    {
        $manifest = json_decode((string) @file_get_contents($backupDir . '/manifest.json'), true);

        if (!is_array($manifest) || !isset($manifest['paths'])) {
            throw new \RuntimeException('Backup manifest is missing or corrupted.');
        }

        foreach ($manifest['paths'] as $path) {
            $from = $backupDir . '/' . $path;

            is_dir($from)
                ? $this->swapDir($from, $this->root($path))
                : $this->swapFile($from, $this->root($path));
        }
    }

    /**
     * Restores the most recent backup.
     *
     * @return void
     */
    public function rollback(): void
    {
        try {
            $backups = $this->listBackups();

            if ($backups === []) {
                throw new \RuntimeException('No backups found in storage/backups.');
            }

            $latest = $backups[0];

            self::newLine();
            self::info("Most recent backup: Slenix {$latest['version']} (" . date('Y-m-d H:i:s', $latest['created']) . ')');
            self::warning('Current src/ and celestial will be replaced by that backup.');

            if (!$this->confirm('Restore it?')) {
                self::warning('Rollback cancelled.');
                self::newLine();
                return;
            }

            $this->restoreBackup($latest['dir']);
            $this->dumpAutoload();

            self::newLine();
            self::success("Restored Slenix {$latest['version']}.");
            self::info('The backup folder was kept; delete it manually when you no longer need it.');
            self::newLine();
        } catch (\RuntimeException $e) {
            self::newLine();
            self::error($e->getMessage());
            self::newLine();
            exit(1);
        }
    }

    /**
     * @return array<int, array{dir: string, version: string, created: int}> Newest first.
     */
    private function listBackups(): array
    {
        $found = [];

        foreach (glob($this->root('storage/backups/slenix-*'), GLOB_ONLYDIR) ?: [] as $dir) {
            $manifest = json_decode((string) @file_get_contents($dir . '/manifest.json'), true);

            if (is_array($manifest) && isset($manifest['version'], $manifest['created'])) {
                $found[] = ['dir' => $dir, 'version' => (string) $manifest['version'], 'created' => (int) $manifest['created']];
            }
        }

        usort($found, static fn(array $a, array $b): int => $b['created'] <=> $a['created']);

        return $found;
    }

    // =========================================================================
    // Filesystem helpers
    // =========================================================================

    /**
     * Replaces a directory with a copy of another, swapping names so the old
     * one is only deleted after the new one is in place.
     *
     * @throws \RuntimeException
     */
    private function swapDir(string $from, string $target): void
    {
        $stage = $target . '.new';
        $old   = $target . '.old';

        $this->deleteDir($stage);
        $this->deleteDir($old);
        $this->copyDir($from, $stage);

        if (is_dir($target) && !rename($target, $old)) {
            $this->deleteDir($stage);
            throw new \RuntimeException("Could not move {$target} out of the way.");
        }

        if (!rename($stage, $target)) {
            if (is_dir($old)) {
                rename($old, $target);
            }
            throw new \RuntimeException("Could not put the new {$target} in place.");
        }

        $this->deleteDir($old);
    }

    /**
     * Replaces a single file, keeping its permissions (celestial stays executable).
     *
     * @throws \RuntimeException
     */
    private function swapFile(string $from, string $target): void
    {
        $perms = is_file($target) ? (fileperms($target) & 0777) : 0644;
        $stage = $target . '.new';

        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0755, true);
        }

        if (!copy($from, $stage)) {
            throw new \RuntimeException("Could not write {$target}.");
        }

        chmod($stage, $perms);

        if (!rename($stage, $target)) {
            @unlink($stage);
            throw new \RuntimeException("Could not replace {$target}.");
        }
    }

    /** @throws \RuntimeException */
    private function copyDir(string $from, string $to): void
    {
        if (!is_dir($to) && !mkdir($to, 0755, true) && !is_dir($to)) {
            throw new \RuntimeException("Could not create {$to}.");
        }

        foreach (new \DirectoryIterator($from) as $item) {
            if ($item->isDot()) {
                continue;
            }

            $dest = $to . '/' . $item->getFilename();

            if ($item->isDir()) {
                $this->copyDir($item->getPathname(), $dest);
            } elseif (!copy($item->getPathname(), $dest)) {
                throw new \RuntimeException("Could not copy {$item->getFilename()}.");
            }
        }
    }

    private function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }

    /**
     * Maps every file under $dir to a hash of its content (line endings
     * normalised so a Windows checkout does not look "modified").
     *
     * @return array<string, string> relative path => sha1
     */
    private function fingerprint(string $dir): array
    {
        $out = [];

        if (!is_dir($dir)) {
            return $out;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->isFile()) {
                $rel       = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
                $out[$rel] = sha1(str_replace("\r\n", "\n", (string) file_get_contents($file->getPathname())));
            }
        }

        ksort($out);

        return $out;
    }

    /** True when $new exists and its content differs from $local (or $local is missing). */
    private function differs(string $new, string $local): bool
    {
        if (!is_file($new)) {
            return false;
        }

        if (!is_file($local)) {
            return true;
        }

        $norm = static fn(string $f): string => str_replace("\r\n", "\n", (string) file_get_contents($f));

        return $norm($new) !== $norm($local);
    }

    /**
     * Regenerates the Composer autoloader (files may have been added or removed).
     */
    private function dumpAutoload(): void
    {
        $probe = DIRECTORY_SEPARATOR === '\\' ? 'where composer 2>nul' : 'command -v composer 2>/dev/null';

        if (trim((string) shell_exec($probe)) === '') {
            self::warning('Composer was not found. Run "composer dump-autoload" manually.');
            return;
        }

        chdir($this->root());
        passthru('composer dump-autoload', $code);

        if ($code !== 0) {
            self::warning('composer dump-autoload failed. Run it manually.');
        }
    }

    // =========================================================================
    // CLI helpers
    // =========================================================================

    /** Project root: src/Core/Console/Commands is 4 levels below it. */
    private function root(string $relative = ''): string
    {
        $root = dirname(__DIR__, 4);

        return $relative === '' ? $root : $root . '/' . ltrim($relative, '/\\');
    }

    private function hasFlag(string $flag): bool
    {
        return in_array($flag, $this->args, true);
    }

    private function option(string $prefix): ?string
    {
        foreach ($this->args as $arg) {
            if (str_starts_with($arg, $prefix)) {
                return substr($arg, strlen($prefix));
            }
        }

        return null;
    }

    /**
     * Yes/no question. --force answers yes; without a terminal it refuses.
     *
     * @throws \RuntimeException When there is no terminal and --force was not given.
     */
    private function confirm(string $question): bool
    {
        if ($this->hasFlag('--force')) {
            return true;
        }

        if (!function_exists('stream_isatty') || !stream_isatty(STDIN)) {
            throw new \RuntimeException('No interactive terminal. Re-run with --force to apply without confirmation.');
        }

        echo PHP_EOL . '  ' . $question . " \033[90m[yes/no]\033[0m: ";
        $answer = strtolower(trim((string) fgets(STDIN)));
        echo PHP_EOL;

        return in_array($answer, ['y', 'yes', 's', 'sim'], true);
    }
}