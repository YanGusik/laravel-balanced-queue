<?php

declare(strict_types=1);

namespace YanGusik\BalancedQueue\Contracts;

use Illuminate\Contracts\Redis\Connection;

interface ConcurrencyLimiter
{
    /**
     * Check if a new job can be started for the given partition.
     *
     * @param Connection $redis Redis connection instance
     * @param string $queue The queue name
     * @param string $partition The partition identifier
     * @return bool True if a new job can start, false otherwise
     */
    public function canProcess(Connection $redis, string $queue, string $partition): bool;

    /**
     * Acquire a slot for processing a job in the given partition.
     * Should be called before starting job execution.
     *
     * @param Connection $redis Redis connection instance
     * @param string $queue The queue name
     * @param string $partition The partition identifier
     * @param string $jobId Unique job identifier
     * @return bool True if slot was acquired, false otherwise
     */
    public function acquire(Connection $redis, string $queue, string $partition, string $jobId): bool;

    /**
     * Release a slot after job completion or failure.
     * Should be called after job execution finishes.
     *
     * @param Connection $redis Redis connection instance
     * @param string $queue The queue name
     * @param string $partition The partition identifier
     * @param string $jobId Unique job identifier
     * @return void
     */
    public function release(Connection $redis, string $queue, string $partition, string $jobId): void;

    /**
     * Get the current number of active jobs for a partition.
     *
     * @param Connection $redis Redis connection instance
     * @param string $queue The queue name
     * @param string $partition The partition identifier
     * @return int Number of currently active jobs
     */
    public function getActiveCount(Connection $redis, string $queue, string $partition): int;

    /**
     * Get the limiter name for identification.
     *
     * @return string
     */
    public function getName(): string;
}
