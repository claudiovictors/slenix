<?php

/*
|--------------------------------------------------------------------------
| Queue Class — Slenix Framework
|--------------------------------------------------------------------------
|
| File-based job queue. Jobs are serialised to JSON files in
| storage/queue/{channel}/.
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Queue;

class Queue
{
    /** @var string Base directory for all queue channels */
    private static string $basePath = '';

    /** @var string Default channel name */
    private static string $defaultQueue = 'default';

    /**
     * Sets the root storage path for queue files.
     * @param string $path
     * @return void
     */
    public static function setBasePath(string $path): void
    {
        self::$basePath = rtrim($path, '/');
    }

    /**
     * Sets the default queue channel name.
     * @param string $name
     * @return void
     */
    public static function setDefaultQueue(string $name): void
    {
        self::$defaultQueue = $name;
    }

    /**
     * Pushes a job onto the specified queue channel.
     * @param Job $job
     * @param string $queue Channel name
     * @param int $delay Seconds to delay execution
     * @return string Job ID
     */
    public static function push(Job $job, string $queue = '', int $delay = 0): string
    {
        $channel = $queue ?: $job->queue ?: self::$defaultQueue;
        $delay   = $delay ?: $job->delay;

        $dir = self::channelPath($channel);
        self::ensureDirectory($dir);

        $jobId      = self::generateId();
        $availableAt = time() + $delay;

        $job->setJobId($jobId);

        $payload = json_encode([
            'id'           => $jobId,
            'queue'        => $channel,
            'class'        => get_class($job),
            'payload'      => base64_encode($job->serialize()),
            'attempts'     => 0,
            'available_at' => $availableAt,
            'created_at'   => time(),
        ], JSON_THROW_ON_ERROR);

        $file = sprintf('%s/%d_%s.job', $dir, $availableAt, $jobId);
        file_put_contents($file, $payload, LOCK_EX);

        return $jobId;
    }

    /**
     * Returns the next available job from the queue.
     * @param string $queue Channel name
     * @return array|null
     */
    public static function pop(string $queue = ''): ?array
    {
        $channel = $queue ?: self::$defaultQueue;
        $dir     = self::channelPath($channel);

        if (!is_dir($dir)) return null;

        $files = glob($dir . '/*.job');
        if (empty($files)) return null;

        sort($files);

        foreach ($files as $file) {
            $raw = file_get_contents($file);
            if ($raw === false) continue;

            $payload = json_decode($raw, true);
            if (!is_array($payload)) continue;

            if (($payload['available_at'] ?? 0) > time()) continue;

            return ['file' => $file, 'payload' => $payload];
        }

        return null;
    }

    /**
     * Acknowledges a job as processed by deleting its file.
     * @param string $file
     * @return void
     */
    public static function ack(string $file): void
    {
        @unlink($file);
    }

    /**
     * Handles a job failure by retrying or moving it to the failed queue.
     * @param string $file
     * @param array $payload
     * @param Job $job
     * @return void
     */
    public static function nack(string $file, array $payload, Job $job): void
    {
        @unlink($file);
        $payload['attempts']++;

        if ($payload['attempts'] >= $job->tries) {
            $failedDir = self::channelPath('failed');
            self::ensureDirectory($failedDir);
            $failedFile = sprintf('%s/%d_%s.job', $failedDir, time(), $payload['id']);
            file_put_contents($failedFile, json_encode($payload, JSON_THROW_ON_ERROR), LOCK_EX);
            return;
        }

        $payload['available_at'] = time() + $job->retryAfter;
        $dir = self::channelPath($payload['queue']);
        $retryFile = sprintf('%s/%d_%s.job', $dir, $payload['available_at'], $payload['id']);
        file_put_contents($retryFile, json_encode($payload, JSON_THROW_ON_ERROR), LOCK_EX);
    }

    /**
     * Returns the total number of jobs in a channel.
     * @param string $queue
     * @return int
     */
    public static function size(string $queue = ''): int
    {
        $dir = self::channelPath($queue ?: self::$defaultQueue);
        return is_dir($dir) ? count(glob($dir . '/*.job') ?: []) : 0;
    }

    /**
     * Clears all jobs from a channel.
     * @param string $queue
     * @return int Number of jobs removed
     */
    public static function clear(string $queue = ''): int
    {
        $dir   = self::channelPath($queue ?: self::$defaultQueue);
        $files = is_dir($dir) ? glob($dir . '/*.job') : [];
        $count = 0;
        foreach ($files as $file) {
            if (@unlink($file)) $count++;
        }
        return $count;
    }

    /**
     * Resolves the storage path for a specific channel.
     * @param string $channel
     * @return string
     */
    private static function channelPath(string $channel): string
    {
        $base = self::$basePath ?: (dirname(__DIR__, 3) . '/storage/queue');
        return $base . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $channel);
    }

    /**
     * Ensures that the queue directory exists.
     * @param string $dir
     * @throws \RuntimeException
     * @return void
     */
    private static function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new \RuntimeException("Could not create queue directory: {$dir}");
        }
    }

    /**
     * Generates a random unique identifier for the job.
     * @return string
     */
    private static function generateId(): string
    {
        return bin2hex(random_bytes(8));
    }
}