<?php

declare(strict_types=1);

namespace YanGusik\BalancedQueue\Queue;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Str;
use YanGusik\BalancedQueue\Contracts\ConcurrencyLimiter;
use YanGusik\BalancedQueue\Contracts\PartitionStrategy;
use YanGusik\BalancedQueue\Support\RedisKeys;

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
    protected RedisKeys $keys;
    protected \Closure $partitionResolver;

    /**
     * The job that was last pushed (for Horizon integration).
     *
     * @var object|string|null
     */
    protected $lastPushed;

    /**
     * Whether Horizon integration is enabled (cached).
     */
    protected ?bool $horizonEnabled = null;

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
        $this->keys = new RedisKeys($prefix);

        return $this;
    }

    /**
     * Get the Redis keys helper.
     */
    public function getKeys(): RedisKeys
    {
        return $this->keys;
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
     * Get clean queue name (without 'queues:' prefix).
     *
     * This normalizes the queue name for consistent usage throughout the class.
     * Laravel's base getQueue() adds 'queues:' prefix, but we want clean names.
     */
    protected function getCleanQueueName(?string $queue): string
    {
        return $queue ?: $this->default;
    }

    /**
     * Push a job onto the queue with partition support.
     */
    public function push($job, $data = '', $queue = null): mixed
    {
        $queueName = $this->getCleanQueueName($queue);
        $partition = $this->resolvePartition($job);

        // Store for Horizon integration
        $this->lastPushed = $job;

        return $this->pushToPartition($queueName, $partition, $this->createPayload($job, $this->getQueue($queue), $data));
    }

    /**
     * Push a raw payload onto the queue.
     */
    public function pushRaw($payload, $queue = null, array $options = []): mixed
    {
        $queueName = $this->getCleanQueueName($queue);
        $partition = $options['partition'] ?? 'default';

        // For raw push, we don't have the job object
        $this->lastPushed = null;

        return $this->pushToPartition($queueName, $partition, $payload);
    }

    /**
     * Push a job to a specific partition.
     *
     * @param string $queueName Clean queue name (e.g., 'default')
     * @param string $partition Partition key
     * @param string $payload Job payload
     */
    protected function pushToPartition(string $queueName, string $partition, string $payload): mixed
    {
        // Prepare payload for Horizon (adds tags, type, pushedAt, etc.)
        $payload = $this->preparePayloadForHorizon($payload, $this->lastPushed);

        // Fire Horizon JobPending event (before push)
        if ($this->isHorizonEnabled() && class_exists(\Laravel\Horizon\Events\JobPending::class)) {
            $this->fireHorizonEvent($queueName, new \Laravel\Horizon\Events\JobPending($payload));
        }

        $redis = $this->getConnection();

        $partitionsKey = $this->keys->partitions($queueName);
        $queueKey = $this->keys->partitionQueue($queueName, $partition);
        $metricsKey = $this->keys->metrics($queueName, $partition);

        $result = $redis->eval(
            LuaScripts::push(),
            3,
            $partitionsKey,
            $queueKey,
            $metricsKey,
            $payload,
            $partition,
            time()
        );

        // Fire Horizon JobPushed event (after push)
        if ($this->isHorizonEnabled() && class_exists(\Laravel\Horizon\Events\JobPushed::class)) {
            $this->fireHorizonEvent($queueName, new \Laravel\Horizon\Events\JobPushed($payload));
        }

        // Clear lastPushed
        $this->lastPushed = null;

        return $result;
    }

    /**
     * Pop the next job from the queue.
     */
    public function pop($queue = null, $index = 0): ?Job
    {
        $queueName = $this->getCleanQueueName($queue);
        $redis = $this->getConnection();

        // Select partition using strategy
        $partitionsKey = $this->keys->partitions($queueName);
        $partition = $this->strategy->selectPartition($redis, $queueName, $partitionsKey);

        if ($partition === null) {
            return null;
        }

        // Try to pop a job with concurrency limit
        return $this->popFromPartition($queueName, $partition);
    }

    /**
     * Pop a job from a specific partition.
     *
     * @param string $queueName Clean queue name (e.g., 'default')
     * @param string $partition Partition key
     */
    protected function popFromPartition(string $queueName, string $partition): ?Job
    {
        $redis = $this->getConnection();

        $queueKey = $this->keys->partitionQueue($queueName, $partition);
        $partitionsKey = $this->keys->partitions($queueName);
        $activeKey = $this->keys->active($queueName, $partition);
        $metricsKey = $this->keys->metrics($queueName, $partition);

        // Check if we can process based on concurrency limit
        $activeCount = (int) $redis->hlen($activeKey);
        $maxConcurrent = $this->getLimiterMaxConcurrent();

        if ($activeCount >= $maxConcurrent) {
            // Try another partition
            return $this->tryNextPartition($queueName, $partition);
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

        if (! $payload) {
            return null;
        }

        // Fire Horizon JobReserved event
        if ($this->isHorizonEnabled() && class_exists(\Laravel\Horizon\Events\JobReserved::class)) {
            $this->fireHorizonEvent($queueName, new \Laravel\Horizon\Events\JobReserved($payload));
        }

        return new BalancedRedisJob(
            $this->container,
            $this,
            $payload,
            $payload, // reserved is same as payload for balanced queue
            $this->connectionName,
            $queueName,
            $partition,
            $jobId
        );
    }

    /**
     * Try to get a job from another partition when current is at capacity.
     *
     * @param string $queueName Clean queue name (e.g., 'default')
     * @param string $excludePartition Partition to exclude
     */
    protected function tryNextPartition(string $queueName, string $excludePartition): ?Job
    {
        $redis = $this->getConnection();
        $partitionsKey = $this->keys->partitions($queueName);
        $maxConcurrent = $this->getLimiterMaxConcurrent();

        $partitions = $redis->smembers($partitionsKey);
        $partitions = array_diff($partitions, [$excludePartition]);

        foreach ($partitions as $partition) {
            $activeKey = $this->keys->active($queueName, $partition);
            $activeCount = (int) $redis->hlen($activeKey);

            if ($activeCount < $maxConcurrent) {
                $job = $this->popFromPartition($queueName, $partition);
                if ($job !== null) {
                    return $job;
                }
            }
        }

        return null;
    }

    /**
     * Release a job back to the queue.
     *
     * @param string $queueName Clean queue name (e.g., 'default')
     * @param string $partition Partition key
     * @param string $jobId Job ID
     * @param string $payload Job payload
     * @param int $delay Delay in seconds
     * @param BalancedRedisJob|null $job Job instance for Horizon events
     */
    public function releasePartitionJob(string $queueName, string $partition, string $jobId, string $payload, int $delay = 0, ?BalancedRedisJob $job = null): void
    {
        $redis = $this->getConnection();

        // Release the concurrency slot directly from our active key
        $activeKey = $this->keys->active($queueName, $partition);
        $redis->hdel($activeKey, $jobId);

        // Re-add to partition queue
        if ($delay > 0) {
            $redis->zadd(
                $this->keys->delayed($queueName, $partition),
                time() + $delay,
                $payload
            );
        } else {
            // Don't fire Horizon events on re-push (it's a release, not a new job)
            $this->lastPushed = null;
            $this->pushToPartitionWithoutHorizonEvents($queueName, $partition, $payload);
        }

        // Fire Horizon JobReleased event
        if ($this->isHorizonEnabled() && class_exists(\Laravel\Horizon\Events\JobReleased::class)) {
            $this->fireHorizonEvent($queueName, new \Laravel\Horizon\Events\JobReleased($payload));
        }
    }

    /**
     * Push to partition without firing Horizon events (used for release).
     *
     * @param string $queueName Clean queue name (e.g., 'default')
     * @param string $partition Partition key
     * @param string $payload Job payload
     */
    protected function pushToPartitionWithoutHorizonEvents(string $queueName, string $partition, string $payload): mixed
    {
        $redis = $this->getConnection();

        $partitionsKey = $this->keys->partitions($queueName);
        $queueKey = $this->keys->partitionQueue($queueName, $partition);
        $metricsKey = $this->keys->metrics($queueName, $partition);

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
     * Delete a completed job.
     *
     * @param string $queueName Clean queue name (e.g., 'default')
     * @param string $partition Partition key
     * @param string $jobId Job ID
     * @param BalancedRedisJob|null $job Job instance for Horizon events
     */
    public function deletePartitionJob(string $queueName, string $partition, string $jobId, ?BalancedRedisJob $job = null): void
    {
        $redis = $this->getConnection();

        // Release the concurrency slot directly from our active key
        $activeKey = $this->keys->active($queueName, $partition);
        $redis->hdel($activeKey, $jobId);

        // Fire Horizon JobDeleted event (marks job as complete in dashboard)
        if ($job && $this->isHorizonEnabled() && class_exists(\Laravel\Horizon\Events\JobDeleted::class)) {
            $this->fireHorizonEvent($queueName, new \Laravel\Horizon\Events\JobDeleted($job, $job->getRawBody()));
        }
    }

    /**
     * Resolve partition key from job.
     */
    protected function resolvePartition($job): string
    {
        // 1. Explicitly set via onPartition()
        if (isset($job->partitionKey)) {
            return (string) $job->partitionKey;
        }

        // 2. Overridden in job class (now works correctly without trait method!)
        if (method_exists($job, 'getPartitionKey')) {
            return (string) $job->getPartitionKey();
        }

        // 3. Global resolver from config
        if (isset($this->partitionResolver)) {
            return (string) ($this->partitionResolver)($job);
        }

        // 4. Auto-detect from common properties
        foreach (['userId', 'user_id', 'tenantId', 'tenant_id'] as $prop) {
            if (property_exists($job, $prop) && $job->$prop !== null) {
                return (string) $job->$prop;
            }
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
        $queueName = $this->getCleanQueueName($queue);
        $redis = $this->getConnection();
        $partitionsKey = $this->keys->partitions($queueName);

        $partitions = $redis->smembers($partitionsKey);

        if (empty($partitions)) {
            return 0;
        }

        $total = 0;
        foreach ($partitions as $partition) {
            $queueKey = $this->keys->partitionQueue($queueName, $partition);
            $total += (int) $redis->llen($queueKey);
        }

        return $total;
    }

    // =========================================================================
    // Horizon Integration (Experimental)
    // =========================================================================

    /**
     * Check if Horizon integration is enabled.
     */
    protected function isHorizonEnabled(): bool
    {
        if ($this->horizonEnabled !== null) {
            return $this->horizonEnabled;
        }

        $config = config('balanced-queue.horizon.enabled', 'auto');

        if ($config === false) {
            return $this->horizonEnabled = false;
        }

        if ($config === true) {
            return $this->horizonEnabled = class_exists(\Laravel\Horizon\Horizon::class);
        }

        // 'auto' - enable only if Horizon is installed
        return $this->horizonEnabled = class_exists(\Laravel\Horizon\Horizon::class);
    }

    /**
     * Fire a Horizon event if Horizon integration is enabled.
     *
     * @param string $queueName Clean queue name (e.g., 'default')
     * @param mixed $event The Horizon event instance
     */
    protected function fireHorizonEvent(string $queueName, $event): void
    {
        if (! $this->isHorizonEnabled()) {
            return;
        }

        if (! $this->container || ! $this->container->bound(Dispatcher::class)) {
            return;
        }

        $this->container->make(Dispatcher::class)->dispatch(
            $event->connection($this->getConnectionName())->queue($queueName)
        );
    }

    /**
     * Prepare payload for Horizon (adds tags, type, pushedAt, etc.).
     */
    protected function preparePayloadForHorizon(string $payload, $job = null): string
    {
        if (! $this->isHorizonEnabled()) {
            return $payload;
        }

        if (! class_exists(\Laravel\Horizon\JobPayload::class)) {
            return $payload;
        }

        $horizonPayload = new \Laravel\Horizon\JobPayload($payload);

        return $horizonPayload->prepare($job)->value;
    }
}
