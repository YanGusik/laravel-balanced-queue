<?php

declare(strict_types=1);

namespace YanGusik\BalancedQueue\Queue;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Queue\Jobs\RedisJob;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Str;
use YanGusik\BalancedQueue\Contracts\ConcurrencyLimiter;
use YanGusik\BalancedQueue\Contracts\PartitionStrategy;

/**
 * Balanced Redis Queue implementation.
 *
 * Extends Laravel's RedisQueue to add partition-based job distribution
 * with configurable strategies and concurrency limiting.
 */
class BalancedRedisQueue extends RedisQueue
{
    protected PartitionStrategy $strategy;
    protected ConcurrencyLimiter $limiter;
    protected string $prefix;
    protected \Closure $partitionResolver;

    public function __construct(
        RedisFactory $redis,
        string $default = 'default',
        ?string $connection = null,
        int $retryAfter = 60,
        ?int $blockFor = null,
        ?bool $dispatchAfterCommit = false,
        ?string $migrationBatchSize = null
    ) {
        parent::__construct($redis, $default, $connection, $retryAfter, $blockFor, $dispatchAfterCommit, $migrationBatchSize);
    }

    /**
     * Set the partition selection strategy.
     */
    public function setStrategy(PartitionStrategy $strategy): self
    {
        $this->strategy = $strategy;

        return $this;
    }

    /**
     * Set the concurrency limiter.
     */
    public function setLimiter(ConcurrencyLimiter $limiter): self
    {
        $this->limiter = $limiter;

        return $this;
    }

    /**
     * Set the partition key prefix.
     */
    public function setPrefix(string $prefix): self
    {
        $this->prefix = $prefix;

        return $this;
    }

    /**
     * Set the partition resolver function.
     */
    public function setPartitionResolver(\Closure $resolver): self
    {
        $this->partitionResolver = $resolver;

        return $this;
    }

    /**
     * Push a job onto the queue with partition support.
     */
    public function push($job, $data = '', $queue = null): mixed
    {
        $queue = $this->getQueue($queue);
        $partition = $this->resolvePartition($job);

        return $this->pushToPartition($queue, $partition, $this->createPayload($job, $queue, $data));
    }

    /**
     * Push a raw payload onto the queue.
     */
    public function pushRaw($payload, $queue = null, array $options = []): mixed
    {
        $queue = $this->getQueue($queue);
        $partition = $options['partition'] ?? 'default';

        return $this->pushToPartition($queue, $partition, $payload);
    }

    /**
     * Push a job to a specific partition.
     */
    protected function pushToPartition(string $queue, string $partition, string $payload): mixed
    {
        $redis = $this->getConnection();

        $partitionsKey = $this->getPartitionsKey($queue);
        $queueKey = $this->getPartitionQueueKey($queue, $partition);
        $metricsKey = $this->getMetricsKey($queue, $partition);

        return $redis->eval(
            LuaScripts::push(),
            3,
            $partitionsKey,
            $queueKey,
            $metricsKey,
            $payload,
            $partition,
            time()
        );
    }

    /**
     * Pop the next job from the queue.
     */
    public function pop($queue = null, $index = 0): ?Job
    {
        $queue = $this->getQueue($queue);
        $redis = $this->getConnection();

        // Select partition using strategy
        $partitionsKey = $this->getPartitionsKey($queue);
        $partition = $this->strategy->selectPartition($redis, $queue, $partitionsKey);

        if ($partition === null) {
            return null;
        }

        // Try to pop a job with concurrency limit
        return $this->popFromPartition($queue, $partition);
    }

    /**
     * Pop a job from a specific partition.
     */
    protected function popFromPartition(string $queue, string $partition): ?Job
    {
        $redis = $this->getConnection();

        $queueKey = $this->getPartitionQueueKey($queue, $partition);
        $partitionsKey = $this->getPartitionsKey($queue);
        $activeKey = $this->getActiveKey($queue, $partition);
        $metricsKey = $this->getMetricsKey($queue, $partition);

        // Check if we can process based on concurrency limit
        $activeCount = (int) $redis->hlen($activeKey);
        $maxConcurrent = $this->getLimiterMaxConcurrent();

        if ($activeCount >= $maxConcurrent) {
            // Try another partition
            return $this->tryNextPartition($queue, $partition);
        }

        $jobId = Str::uuid()->toString();

        // Pop with limit check
        $payload = $redis->eval(
            LuaScripts::popWithLimit(),
            4,
            $queueKey,
            $partitionsKey,
            $activeKey,
            $metricsKey,
            $partition,
            $jobId,
            $this->getLimiterMaxConcurrent(),
            $this->retryAfter,
            time()
        );

        if (!$payload) {
            return null;
        }

        return new BalancedRedisJob(
            $this->container,
            $this,
            $payload,
            $payload, // reserved is same as payload for balanced queue
            $this->connectionName,
            $queue,
            $partition,
            $jobId
        );
    }

    /**
     * Try to get a job from another partition when current is at capacity.
     */
    protected function tryNextPartition(string $queue, string $excludePartition): ?Job
    {
        $redis = $this->getConnection();
        $partitionsKey = $this->getPartitionsKey($queue);
        $maxConcurrent = $this->getLimiterMaxConcurrent();

        $partitions = $redis->smembers($partitionsKey);
        $partitions = array_diff($partitions, [$excludePartition]);

        foreach ($partitions as $partition) {
            $activeKey = $this->getActiveKey($queue, $partition);
            $activeCount = (int) $redis->hlen($activeKey);

            if ($activeCount < $maxConcurrent) {
                $job = $this->popFromPartition($queue, $partition);
                if ($job !== null) {
                    return $job;
                }
            }
        }

        return null;
    }

    /**
     * Release a job back to the queue.
     */
    public function releasePartitionJob(string $queue, string $partition, string $jobId, string $payload, int $delay = 0): void
    {
        $redis = $this->getConnection();

        // Release the concurrency slot directly from our active key
        $activeKey = $this->getActiveKey($queue, $partition);
        $redis->hdel($activeKey, $jobId);

        // Re-add to partition queue
        if ($delay > 0) {
            $redis->zadd(
                $this->getDelayedKey($queue, $partition),
                time() + $delay,
                $payload
            );
        } else {
            $this->pushToPartition($queue, $partition, $payload);
        }
    }

    /**
     * Delete a completed job.
     */
    public function deletePartitionJob(string $queue, string $partition, string $jobId): void
    {
        $redis = $this->getConnection();

        // Release the concurrency slot directly from our active key
        $activeKey = $this->getActiveKey($queue, $partition);
        $redis->hdel($activeKey, $jobId);
    }

    /**
     * Resolve partition key from job.
     */
    protected function resolvePartition($job): string
    {
        if (method_exists($job, 'getPartitionKey')) {
            return (string) $job->getPartitionKey();
        }

        if (isset($this->partitionResolver)) {
            return (string) ($this->partitionResolver)($job);
        }

        return 'default';
    }

    /**
     * Get the max concurrent value from limiter.
     */
    protected function getLimiterMaxConcurrent(): int
    {
        if (method_exists($this->limiter, 'getMaxConcurrent')) {
            return $this->limiter->getMaxConcurrent();
        }

        return PHP_INT_MAX;
    }

    /**
     * Get the partitions set key.
     */
    public function getPartitionsKey(string $queue): string
    {
        return "{$this->prefix}:{$queue}:partitions";
    }

    /**
     * Get the queue key for a partition.
     */
    public function getPartitionQueueKey(string $queue, string $partition): string
    {
        return "{$this->prefix}:{$queue}:{$partition}";
    }

    /**
     * Get the active jobs key for a partition.
     */
    public function getActiveKey(string $queue, string $partition): string
    {
        return "{$this->prefix}:{$queue}:{$partition}:active";
    }

    /**
     * Get the metrics key for a partition.
     */
    public function getMetricsKey(string $queue, string $partition): string
    {
        return "{$this->prefix}:metrics:{$queue}:{$partition}";
    }

    /**
     * Get the delayed jobs key for a partition.
     */
    public function getDelayedKey(string $queue, string $partition): string
    {
        return "{$this->prefix}:{$queue}:{$partition}:delayed";
    }

    /**
     * Get the number of jobs ready to process (for Horizon compatibility).
     */
    public function readyNow($queue = null): int
    {
        return $this->size($queue);
    }

    /**
     * Get the size of the queue (total jobs across all partitions).
     */
    public function size($queue = null): int
    {
        $queue = $this->getQueue($queue);
        $redis = $this->getConnection();
        $partitionsKey = $this->getPartitionsKey($queue);

        $partitions = $redis->smembers($partitionsKey);

        if (empty($partitions)) {
            return 0;
        }

        $total = 0;
        foreach ($partitions as $partition) {
            $queueKey = $this->getPartitionQueueKey($queue, $partition);
            $total += (int) $redis->llen($queueKey);
        }

        return $total;
    }
}
