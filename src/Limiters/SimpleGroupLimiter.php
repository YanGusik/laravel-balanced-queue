<?php

declare(strict_types=1);

namespace YanGusik\BalancedQueue\Limiters;

use Illuminate\Contracts\Redis\Connection;
use YanGusik\BalancedQueue\Contracts\ConcurrencyLimiter;

/**
 * Simple group limiter - fixed concurrent jobs per partition.
 *
 * Limits the number of concurrently executing jobs per partition (e.g., user).
 * Perfect for scenarios like: "max 2 AI generation tasks per user".
 */
class SimpleGroupLimiter implements ConcurrencyLimiter
{
    protected int $maxConcurrent;
    protected int $lockTtl;
    protected string $activeKeyPrefix;

    public function __construct(
        int $maxConcurrent = 2,
        int $lockTtl = 3600,
        string $activeKeyPrefix = 'balanced-queue:active'
    ) {
        $this->maxConcurrent = $maxConcurrent;
        $this->lockTtl = $lockTtl;
        $this->activeKeyPrefix = $activeKeyPrefix;
    }

    public function canProcess(Connection $redis, string $queue, string $partition): bool
    {
        $activeKey = $this->getActiveKey($queue, $partition);

        // Lazy cleanup: remove stale entries and return actual count
        $script = <<<'LUA'
            local active_key = KEYS[1]
            local threshold = tonumber(ARGV[1])

            local entries = redis.call('HGETALL', active_key)

            for i = 1, #entries, 2 do
                local job_id = entries[i]
                local timestamp = tonumber(entries[i+1])
                if timestamp and timestamp < threshold then
                    redis.call('HDEL', active_key, job_id)
                end
            end

            return redis.call('HLEN', active_key)
        LUA;

        $threshold = time() - $this->lockTtl;
        $activeCount = (int) $redis->eval($script, 1, $activeKey, $threshold);

        return $activeCount < $this->maxConcurrent;
    }

    public function acquire(Connection $redis, string $queue, string $partition, string $jobId): bool
    {
        $activeKey = $this->getActiveKey($queue, $partition);

        // Use Lua script for atomic cleanup + check-and-set
        $script = <<<'LUA'
            local active_key = KEYS[1]
            local job_id = ARGV[1]
            local max_concurrent = tonumber(ARGV[2])
            local ttl = tonumber(ARGV[3])
            local current_time = tonumber(ARGV[4])
            local threshold = tonumber(ARGV[5])

            -- Lazy cleanup: remove stale entries first
            local entries = redis.call('HGETALL', active_key)
            for i = 1, #entries, 2 do
                local entry_job_id = entries[i]
                local timestamp = tonumber(entries[i+1])
                if timestamp and timestamp < threshold then
                    redis.call('HDEL', active_key, entry_job_id)
                end
            end

            local current_count = redis.call('HLEN', active_key)

            if current_count < max_concurrent then
                redis.call('HSET', active_key, job_id, current_time)
                redis.call('EXPIRE', active_key, ttl)
                return 1
            end

            return 0
        LUA;

        $now = time();
        $threshold = $now - $this->lockTtl;

        $result = $redis->eval(
            $script,
            1,
            $activeKey,
            $jobId,
            $this->maxConcurrent,
            $this->lockTtl,
            $now,
            $threshold
        );

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

        // Lazy cleanup: remove stale entries and return actual count
        $script = <<<'LUA'
            local active_key = KEYS[1]
            local threshold = tonumber(ARGV[1])

            local entries = redis.call('HGETALL', active_key)

            for i = 1, #entries, 2 do
                local job_id = entries[i]
                local timestamp = tonumber(entries[i+1])
                if timestamp and timestamp < threshold then
                    redis.call('HDEL', active_key, job_id)
                end
            end

            return redis.call('HLEN', active_key)
        LUA;

        $threshold = time() - $this->lockTtl;

        return (int) $redis->eval($script, 1, $activeKey, $threshold);
    }

    public function getName(): string
    {
        return 'simple';
    }

    public function getMaxConcurrent(): int
    {
        return $this->maxConcurrent;
    }

    protected function getActiveKey(string $queue, string $partition): string
    {
        return "{$this->activeKeyPrefix}:{$queue}:{$partition}";
    }
}
