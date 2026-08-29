<?php

declare(strict_types=1);

namespace YanGusik\BalancedQueue\Queue;

use Illuminate\Container\Container;
use Illuminate\Queue\Jobs\RedisJob;

/**
 * Balanced Redis Job wrapper.
 *
 * Extends RedisJob to properly handle partition-based release and delete operations.
 */
class BalancedRedisJob extends RedisJob
{
    protected string $partition;
    protected string $balancedJobId;

    public function __construct(
        Container $container,
        BalancedRedisQueue $redis,
        string $job,
        string $reserved,
        string $connectionName,
        string $queue,
        string $partition,
        string $jobId
    ) {
        parent::__construct($container, $redis, $job, $reserved, $connectionName, $queue);

        $this->partition = $partition;
        $this->balancedJobId = $jobId;
    }

    /**
     * Release the job back into the queue.
     */
    public function release($delay = 0): void
    {
        // RedisJob::release() routes through RedisQueue::deleteAndRelease(),
        // which targets the stock "queues:<name>:reserved" / ":delayed" keys.
        // This driver stores nothing there, so that call finds no reservation
        // to remove and its ZADD leaves an orphan payload in a namespace no
        // balanced worker reads. Only the base Job flag is wanted here.
        $this->released = true;

        /** @var BalancedRedisQueue $redis */
        $redis = $this->redis;

        $redis->releasePartitionJob(
            $this->queue,
            $this->partition,
            $this->balancedJobId,
            $this->getRawBody(),
            $delay,
            $this
        );
    }

    /**
     * Delete the job from the queue.
     */
    public function delete(): void
    {
        // Skipped for the same reason as in release(): RedisJob::delete()
        // calls deleteReserved() against stock keys this driver never writes.
        $this->deleted = true;

        /** @var BalancedRedisQueue $redis */
        $redis = $this->redis;

        $redis->deletePartitionJob(
            $this->queue,
            $this->partition,
            $this->balancedJobId,
            $this
        );
    }

    /**
     * Get the partition this job belongs to.
     */
    public function getPartition(): string
    {
        return $this->partition;
    }

    /**
     * Get the balanced job ID.
     */
    public function getBalancedJobId(): string
    {
        return $this->balancedJobId;
    }
}
