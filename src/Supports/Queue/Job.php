<?php

/*
|--------------------------------------------------------------------------
| Job Class — Slenix Framework
|--------------------------------------------------------------------------
|
| Abstract base class for all Slenix jobs.
| Extend this class and implement handle() with your background logic.
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Queue;

abstract class Job
{
    /** @var int Maximum number of attempts before the job is marked as failed. */
    public int $tries = 3;

    /** @var int Number of seconds the job may run before it times out. */
    public int $timeout = 60;

    /** @var int Number of seconds to wait before retrying after a failure. */
    public int $retryAfter = 5;

    /** @var string The queue channel this job should be pushed onto. */
    public string $queue = 'default';

    /** @var int Number of seconds to delay execution (0 = immediate). */
    public int $delay = 0;

    /** @var string Unique job identifier assigned by the Queue. */
    private string $jobId = '';

    /** @var int Current attempt number (set by the Worker). */
    private int $attempts = 0;

    /**
     * Execute the job logic.
     * Implement all background work here.
     * @return void
     */
    abstract public function handle(): void;

    /**
     * Called when all retry attempts have been exhausted.
     * @param \Throwable $e The last exception thrown
     * @return void
     */
    public function failed(\Throwable $e): void {}

    /**
     * Serialises this job instance to a storable string.
     * @return string
     */
    public function serialize(): string
    {
        return serialize($this);
    }

    /**
     * Deserialises a previously serialised job string.
     * @param string $data
     * @return static
     */
    public static function deserialize(string $data): static
    {
        return unserialize($data);
    }

    /**
     * Sets the unique job ID. Internal use by the Queue engine.
     * @internal
     * @param string $id
     * @return void
     */
    public function setJobId(string $id): void
    {
        $this->jobId = $id;
    }

    /**
     * Retrieves the unique job ID.
     * @return string
     */
    public function getJobId(): string
    {
        return $this->jobId;
    }

    /**
     * Sets the current attempt count. Internal use by the Worker.
     * @internal
     * @param int $attempts
     * @return void
     */
    public function setAttempts(int $attempts): void
    {
        $this->attempts = $attempts;
    }

    /**
     * Gets the number of times this job has been attempted.
     * @return int
     */
    public function getAttempts(): int
    {
        return $this->attempts;
    }

    /**
     * Checks if the job has exhausted all retry attempts.
     * @return bool
     */
    public function hasFailed(): bool
    {
        return $this->attempts >= $this->tries;
    }
}