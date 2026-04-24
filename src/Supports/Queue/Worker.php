<?php

/*
|--------------------------------------------------------------------------
| Worker Class — Slenix Framework
|--------------------------------------------------------------------------
|
| Processes jobs from one or more queue channels in a long-running loop.
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Queue;

class Worker
{
    /** @var bool Running flag used to exit the loop on signals. */
    private bool $running = true;

    /** @var int Sleep duration when the queue is empty. */
    private int $sleep;

    /** @var bool If true, process one job and exit. */
    private bool $once;

    /** @var bool If true, stop when no more jobs are available. */
    private bool $stopWhenEmpty;

    /** @var int Limit of jobs processed per execution. */
    private int $maxJobs;

    /** @var int Counter of jobs processed in the current run. */
    private int $processed = 0;

    /**
     * Constructor for the Worker.
     * @param int $sleep
     * @param bool $once
     * @param bool $stopWhenEmpty
     * @param int $maxJobs
     */
    public function __construct(
        int  $sleep         = 3,
        bool $once          = false,
        bool $stopWhenEmpty = false,
        int  $maxJobs       = 0,
    ) {
        $this->sleep         = $sleep;
        $this->once          = $once;
        $this->stopWhenEmpty = $stopWhenEmpty;
        $this->maxJobs       = $maxJobs;

        $this->registerSignalHandlers();
    }

    /**
     * Starts the infinite loop to poll for and process jobs.
     * @param array $queues
     * @return void
     */
    public function work(array $queues = ['default']): void
    {
        while ($this->running) {
            $processed = false;

            foreach ($queues as $queue) {
                $item = Queue::pop($queue);
                if ($item === null) continue;

                $this->process($item['file'], $item['payload']);
                $processed = true;
                $this->processed++;

                if ($this->once || ($this->maxJobs > 0 && $this->processed >= $this->maxJobs)) {
                    return;
                }
            }

            if (!$processed) {
                if ($this->stopWhenEmpty) return;
                sleep($this->sleep);
            }
        }
    }

    /**
     * Deserialises and executes the actual Job logic.
     * @param string $file
     * @param array $payload
     * @return void
     */
    private function process(string $file, array $payload): void
    {
        try {
            $job = unserialize(base64_decode($payload['payload']));
            if (!$job instanceof Job) {
                throw new \RuntimeException("Invalid job payload in file: {$file}");
            }

            $job->setAttempts((int) ($payload['attempts'] ?? 0));

            if ($job->timeout > 0) set_time_limit($job->timeout);

            $job->handle();
            Queue::ack($file);

        } catch (\Throwable $e) {
            try {
                $job = unserialize(base64_decode($payload['payload']));
                $job->setAttempts((int) ($payload['attempts'] ?? 0));

                if (($payload['attempts'] + 1) >= $job->tries) {
                    try { $job->failed($e); } catch (\Throwable) {}
                }

                Queue::nack($file, $payload, $job);
            } catch (\Throwable) {
                @unlink($file);
            }
        }
    }

    /**
     * Registers PCNTL signal handlers for graceful shutdown.
     * @return void
     */
    private function registerSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) return;

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function (): void { $this->running = false; });
        pcntl_signal(SIGINT,  function (): void { $this->running = false; });
    }

    /**
     * Returns the total number of processed jobs.
     * @return int
     */
    public function getProcessed(): int
    {
        return $this->processed;
    }
}