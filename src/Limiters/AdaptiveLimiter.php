<?php

declare(strict_types=1);

namespace YanGusik\BalancedQueue\Limiters;

use Illuminate\Contracts\Redis\Connection;
use YanGusik\BalancedQueue\Contracts\ConcurrencyLimiter;

/**
 * Adaptive limiter - dynamic concurrent jobs based on system load.
 *
 * Adjusts the concurrency limit based on overall system utilization.
 * Starts with a base limit and can increase up to max limit when system is underutilized.
 */
class AdaptiveLimiter implements ConcurrencyLimiter
{
    protected int $baseLimit;
    protected int $maxLimit;
    protected int $lockTtl;
    protected string $activeKeyPrefix;
    protected string $metricsKeyPrefix;
    protected float $utilizationThreshold;

    public function __construct(
        int $baseLimit = 2,
        int $maxLimit = 5,
        int $lockTtl = 3600,
        float $utilizationThreshold = 0.7,
        string $activeKeyPrefix = 'balanced-queue:active',
        string $metricsKeyPrefix = 'balanced-queue:metrics'
    ) {
        $this->baseLimit = $baseLimit;
        $this->maxLimit = $maxLimit;
        $this->lockTtl = $lockTtl;
        $this->utilizationThreshold = $utilizationThreshold;
        $this->activeKeyPrefix = $activeKeyPrefix;
        $this->metricsKeyPrefix = $metricsKeyPrefix;
    }

    public function canProcess(Connection $redis, string $queue, string $partition): bool
    {
        $currentLimit = $this->calculateCurrentLimit($redis, $queue);
        $activeCount = $this->getActiveCount($redis, $queue, $partition);

        return $activeCount < $currentLimit;
    }

    public function acquire(Connection $redis, string $queue, string $partition, string $jobId): bool
    {
        $activeKey = $this->getActiveKey($queue, $partition);
        $currentLimit = $this->calculateCurrentLimit($redis, $queue);

        $script = <<<'LUA'
            local active_key = KEYS[1]
            local job_id = ARGV[1]
            local max_concurrent = tonumber(ARGV[2])
            local ttl = tonumber(ARGV[3])
            local current_time = ARGV[4]

            local current_count = redis.call('HLEN', active_key)

            if current_count < max_concurrent then
                redis.call('HSET', active_key, job_id, current_time)
                redis.call('EXPIRE', active_key, ttl)
                return 1
            end

            return 0
        LUA;

        $result = $redis->eval(
            $script,
            1,
            $activeKey,
            $jobId,
            $currentLimit,
            $this->lockTtl,
            time()
        );

        // Update metrics
        if ($result) {
            $this->updateMetrics($redis, $queue);
        }

        return (bool) $result;
    }

    public function release(Connection $redis, string $queue, string $partition, string $jobId): void
    {
        $activeKey = $this->getActiveKey($queue, $partition);
        $redis->hdel($activeKey, $jobId);
    }

    public function getActiveCount(Connection $redis, string $queue, string $partition): int
    {
        $activeKey = $this->getActiveKey($queue, $partition);

        return (int) $redis->hlen($activeKey);
    }

    public function getName(): string
    {
        return 'adaptive';
    }

    /**
     * Calculate the current dynamic limit based on system utilization.
     */
    protected function calculateCurrentLimit(Connection $redis, string $queue): int
    {
        $metricsKey = "{$this->metricsKeyPrefix}:{$queue}:global";
        $utilization = (float) ($redis->hget($metricsKey, 'utilization') ?? 0);

        // If utilization is low, we can allow more concurrent jobs
        if ($utilization < $this->utilizationThreshold) {
            // Scale up based on how much headroom we have
            $headroom = $this->utilizationThreshold - $utilization;
            $extraSlots = (int) floor(($this->maxLimit - $this->baseLimit) * ($headroom / $this->utilizationThreshold));

            return min($this->baseLimit + $extraSlots, $this->maxLimit);
        }

        return $this->baseLimit;
    }

    /**
     * Update global metrics for adaptive calculations.
     */
    protected function updateMetrics(Connection $redis, string $queue): void
    {
        $metricsKey = "{$this->metricsKeyPrefix}:{$queue}:global";
        $redis->hincrby($metricsKey, 'total_acquired', 1);
        $redis->hset($metricsKey, 'last_updated', time());
        $redis->expire($metricsKey, 3600);
    }

    protected function getActiveKey(string $queue, string $partition): string
    {
        return "{$this->activeKeyPrefix}:{$queue}:{$partition}";
    }
}
