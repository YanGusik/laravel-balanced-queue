<?php

declare(strict_types=1);

namespace YanGusik\BalancedQueue\Queue;

/**
 * Lua scripts for atomic Redis operations.
 */
class LuaScripts
{
    /**
     * Push a job to a partition queue and register the partition.
     *
     * KEYS[1] - partitions set key
     * KEYS[2] - partition queue key
     * KEYS[3] - metrics key
     * ARGV[1] - job payload
     * ARGV[2] - partition identifier
     * ARGV[3] - current timestamp
     */
    public static function push(): string
    {
        return <<<'LUA'
            local partitions_key = KEYS[1]
            local queue_key = KEYS[2]
            local metrics_key = KEYS[3]
            local payload = ARGV[1]
            local partition = ARGV[2]
            local timestamp = ARGV[3]

            -- Add partition to the set
            redis.call('SADD', partitions_key, partition)

            -- Push job to the partition queue
            redis.call('RPUSH', queue_key, payload)

            -- Update metrics (first job time for wait time calculation)
            local first_job_time = redis.call('HGET', metrics_key, 'first_job_time')
            if not first_job_time then
                redis.call('HSET', metrics_key, 'first_job_time', timestamp)
            end
            redis.call('HINCRBY', metrics_key, 'total_pushed', 1)

            return redis.call('LLEN', queue_key)
        LUA;
    }

    /**
     * Pop a job from a partition queue.
     *
     * KEYS[1] - partition queue key
     * KEYS[2] - partitions set key
     * KEYS[3] - metrics key
     * ARGV[1] - partition identifier
     */
    public static function pop(): string
    {
        return <<<'LUA'
            local queue_key = KEYS[1]
            local partitions_key = KEYS[2]
            local metrics_key = KEYS[3]
            local partition = ARGV[1]

            -- Pop job from queue
            local job = redis.call('LPOP', queue_key)

            if job then
                -- Update metrics
                redis.call('HINCRBY', metrics_key, 'total_popped', 1)

                -- Check if queue is now empty
                local remaining = redis.call('LLEN', queue_key)
                if remaining == 0 then
                    -- Remove partition from set
                    redis.call('SREM', partitions_key, partition)
                    -- Clear first job time
                    redis.call('HDEL', metrics_key, 'first_job_time')
                end
            end

            return job
        LUA;
    }

    /**
     * Pop job with concurrency limit check.
     *
     * KEYS[1] - partition queue key
     * KEYS[2] - partitions set key
     * KEYS[3] - active jobs key
     * KEYS[4] - metrics key
     * ARGV[1] - partition identifier
     * ARGV[2] - job id
     * ARGV[3] - max concurrent
     * ARGV[4] - lock ttl
     * ARGV[5] - current timestamp
     */
    public static function popWithLimit(): string
    {
        return <<<'LUA'
            local queue_key = KEYS[1]
            local partitions_key = KEYS[2]
            local active_key = KEYS[3]
            local metrics_key = KEYS[4]
            local partition = ARGV[1]
            local job_id = ARGV[2]
            local max_concurrent = tonumber(ARGV[3])
            local lock_ttl = tonumber(ARGV[4])
            local timestamp = ARGV[5]

            -- Check concurrency limit
            local active_count = redis.call('HLEN', active_key)
            if active_count >= max_concurrent then
                return nil
            end

            -- Pop job from queue
            local job = redis.call('LPOP', queue_key)

            if job then
                -- Acquire slot
                redis.call('HSET', active_key, job_id, timestamp)
                redis.call('EXPIRE', active_key, lock_ttl)

                -- Update metrics
                redis.call('HINCRBY', metrics_key, 'total_popped', 1)

                -- Check if queue is now empty
                local remaining = redis.call('LLEN', queue_key)
                if remaining == 0 then
                    redis.call('SREM', partitions_key, partition)
                    redis.call('HDEL', metrics_key, 'first_job_time')
                end
            end

            return job
        LUA;
    }

    /**
     * Get partition stats.
     *
     * KEYS[1] - partition queue key
     * KEYS[2] - active jobs key
     * KEYS[3] - metrics key
     */
    public static function getStats(): string
    {
        return <<<'LUA'
            local queue_key = KEYS[1]
            local active_key = KEYS[2]
            local metrics_key = KEYS[3]

            local queue_size = redis.call('LLEN', queue_key)
            local active_count = redis.call('HLEN', active_key)
            local metrics = redis.call('HGETALL', metrics_key)

            return {queue_size, active_count, metrics}
        LUA;
    }

    /**
     * Clean up stale active jobs.
     *
     * KEYS[1] - active jobs key
     * ARGV[1] - max age in seconds
     * ARGV[2] - current timestamp
     */
    public static function cleanupStale(): string
    {
        return <<<'LUA'
            local active_key = KEYS[1]
            local max_age = tonumber(ARGV[1])
            local current_time = tonumber(ARGV[2])

            local jobs = redis.call('HGETALL', active_key)
            local cleaned = 0

            for i = 1, #jobs, 2 do
                local job_id = jobs[i]
                local start_time = tonumber(jobs[i + 1])

                if current_time - start_time > max_age then
                    redis.call('HDEL', active_key, job_id)
                    cleaned = cleaned + 1
                end
            end

            return cleaned
        LUA;
    }
}
