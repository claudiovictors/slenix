<?php

/*
|--------------------------------------------------------------------------
| WorkerCommand Class — Slenix Framework
|--------------------------------------------------------------------------
|
| This class handles all queue-related operations within the Celestial CLI.
| It manages the lifecycle of background jobs, including processing, 
| listing failed tasks, and clearing pending queues.
|
*/

declare(strict_types=1);

namespace Slenix\Core\Console;

use Slenix\Supports\Queue\Queue;
use Slenix\Supports\Queue\Worker;

class WorkerCommand extends Command
{
    /** @var array CLI arguments passed to the command. */
    private array $args;

    /**
     * WorkerCommand constructor.
     * * @param array $args Command line arguments.
     */
    public function __construct(array $args)
    {
        $this->args = $args;
    }

    /**
     * Starts the queue worker to process incoming jobs.
     * * @return void
     */
    public function work(): void
    {
        $c = self::console();

        // Parse options
        $queues        = $this->getOption('queue', 'default');
        $sleep         = (int) $this->getOption('sleep', '3');
        $once          = $this->hasFlag('--once');
        $stopWhenEmpty = $this->hasFlag('--stop-when-empty');
        $maxJobs       = (int) $this->getOption('max-jobs', '0');

        $channels = array_map('trim', explode(',', $queues));

        // Boot Queue storage path
        $projectRoot = dirname(__DIR__, 3);
        Queue::setBasePath($projectRoot . '/storage/queue');

        echo PHP_EOL;
        echo $c->colorize("▲ Celestial Queue Worker", 'white', true) . PHP_EOL;
        echo PHP_EOL;
        echo $c->colorize("  ✓ Listening on queue(s): ", 'green') . implode(', ', $channels) . PHP_EOL;
        echo $c->colorize("  ✓ Sleep between polls:   ", 'green') . "{$sleep}s" . PHP_EOL;

        if ($once)          echo $c->colorize("  ✓ Mode: process one job then exit", 'cyan') . PHP_EOL;
        if ($stopWhenEmpty) echo $c->colorize("  ✓ Mode: stop when queue is empty", 'cyan') . PHP_EOL;

        echo PHP_EOL;
        echo $c->colorize("  press Ctrl+C to stop", 'white') . PHP_EOL;
        echo PHP_EOL;

        $worker = new Worker(
            sleep:         $sleep,
            once:          $once,
            stopWhenEmpty: $stopWhenEmpty,
            maxJobs:       $maxJobs,
        );

        $worker->work($channels);

        echo PHP_EOL;
        self::success("Worker stopped. Processed {$worker->getProcessed()} job(s).");
    }

    /**
     * Displays a table of all failed background jobs.
     * * @return void
     */
    public function failed(): void
    {
        $projectRoot = dirname(__DIR__, 3);
        $failedDir   = $projectRoot . '/storage/queue/failed';

        if (!is_dir($failedDir)) {
            self::warning('No failed jobs found.');
            return;
        }

        $files = glob($failedDir . '/*.job') ?: [];

        if (empty($files)) {
            self::warning('No failed jobs found.');
            return;
        }

        self::info('Failed Jobs:');
        echo PHP_EOL;

        $headers = ['ID', 'CLASS', 'QUEUE', 'ATTEMPTS', 'FAILED AT'];
        $rows    = [];

        foreach ($files as $file) {
            $raw = file_get_contents($file);
            if (!$raw) continue;

            $payload = json_decode($raw, true);
            if (!is_array($payload)) continue;

            $rows[] = [
                substr($payload['id'] ?? '?', 0, 12) . '...',
                basename(str_replace('\\', '/', $payload['class'] ?? '?')),
                $payload['queue']    ?? 'default',
                $payload['attempts'] ?? '?',
                date('Y-m-d H:i:s', (int) ($payload['available_at'] ?? filemtime($file))),
            ];
        }

        self::table($headers, $rows);
        echo PHP_EOL;
        self::info(count($rows) . ' failed job(s) total.');
    }

    /**
     * Clears pending jobs from the queue storage.
     * * @return void
     */
    public function clear(): void
    {
        $queue = $this->getOption('queue', '');

        $projectRoot = dirname(__DIR__, 3);
        Queue::setBasePath($projectRoot . '/storage/queue');

        $cleared = Queue::clear($queue);

        if ($cleared === 0) {
            self::warning('No jobs to clear' . ($queue ? " in queue '{$queue}'" : '') . '.');
            return;
        }

        self::success("{$cleared} job(s) cleared" . ($queue ? " from queue '{$queue}'" : '') . '.');
    }

    /**
     * Returns the value of --option=value or a default.
     * * @param string $name    The option name.
     * @param string $default The fallback value.
     * @return string
     */
    private function getOption(string $name, string $default = ''): string
    {
        foreach ($this->args as $arg) {
            if (str_starts_with($arg, "--{$name}=")) {
                return substr($arg, strlen("--{$name}="));
            }
        }
        return $default;
    }

    /**
     * Returns true if a flag (e.g. --once) is present.
     * * @param string $flag The flag name to check.
     * @return bool
     */
    private function hasFlag(string $flag): bool
    {
        return in_array($flag, $this->args, true);
    }
}