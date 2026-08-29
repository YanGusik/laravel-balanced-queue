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
     * Push a job to a partition's delayed set and register the partition atomically.
     *
     * KEYS[1] - partitions set key
     * KEYS[2] - partition delayed key (ZSET)
     * KEYS[3] - metrics key
     * ARGV[1] - job payload
     * ARGV[2] - partition identifier
     * ARGV[3] - timestamp at which the job becomes available
     */
    public static function pushDelayed(): string
    {
        return <<<'LUA'
            local partitions_key = KEYS[1]
            local delayed_key = KEYS[2]
            local metrics_key = KEYS[3]
            local payload = ARGV[1]
            local partition = ARGV[2]
            local available_at = ARGV[3]

            -- Register the partition and enqueue the payload in one step, so a
            -- concurrent pop cannot remove the partition in between and strand
            -- the delayed job in a partition no strategy will visit.
            redis.call('SADD', partitions_key, partition)
            redis.call('ZADD', delayed_key, available_at, payload)

            redis.call('HINCRBY', metrics_key, 'total_pushed', 1)

            return redis.call('ZCARD', delayed_key)
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
     * KEYS[5] - delayed jobs key
     * KEYS[6] - reserved jobs key (HASH job id => reserved payload)
     * KEYS[7] - reserved expiry index key (ZSET job id => expires at)
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
            local delayed_key = KEYS[5]
            local reserved_key = KEYS[6]
            local reserved_index = KEYS[7]
            local partition = ARGV[1]
            local job_id = ARGV[2]
            local max_concurrent = tonumber(ARGV[3])
            local lock_ttl = tonumber(ARGV[4])
            local timestamp = tonumber(ARGV[5])

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

                -- Keep a copy of the payload, with its attempt count bumped, so
                -- the job survives the death of the worker holding it. Cleared
                -- when the job is deleted or released.
                local decoded = cjson.decode(job)
                decoded['attempts'] = (decoded['attempts'] or 0) + 1
                redis.call('HSET', reserved_key, job_id, cjson.encode(decoded))
                redis.call('ZADD', reserved_index, timestamp + lock_ttl, job_id)

                -- Update metrics
                redis.call('HINCRBY', metrics_key, 'total_popped', 1)

                -- Drop the partition only when nothing is left to do for it: no
                -- ready jobs, no delayed jobs, and nothing in flight. Dropping
                -- it while a reservation is outstanding would make that
                -- reservation unreachable, since no strategy would select the
                -- partition again.
                local remaining = redis.call('LLEN', queue_key)
                if remaining == 0 then
                    local delayed_count = redis.call('ZCARD', delayed_key)
                    local reserved_count = redis.call('ZCARD', reserved_index)
                    if delayed_count == 0 and reserved_count == 0 then
                        redis.call('SREM', partitions_key, partition)
                        redis.call('HDEL', metrics_key, 'first_job_time')
                    end
                end
            end

            return job
        LUA;
    }

    /**
     * Migrate delayed jobs that are ready into the partition queue.
     * Removes partition from set if both LIST and delayed ZSET are empty.
     *
     * KEYS[1] - delayed jobs key (ZSET)
     * KEYS[2] - partition queue key (LIST)
     * KEYS[3] - partitions set key
     * KEYS[4] - reserved expiry index key (ZSET)
     * ARGV[1] - partition identifier
     * ARGV[2] - current timestamp
     */
    public static function migrateDelayed(): string
    {
        return <<<'LUA'
            local delayed_key = KEYS[1]
            local queue_key = KEYS[2]
            local partitions_key = KEYS[3]
            local reserved_index = KEYS[4]
            local partition = ARGV[1]
            local current_time = tonumber(ARGV[2])

            -- Get all jobs with score <= current_time (ready to process)
            local jobs = redis.call('ZRANGEBYSCORE', delayed_key, '-inf', current_time)

            if #jobs > 0 then
                redis.call('ZREMRANGEBYSCORE', delayed_key, '-inf', current_time)
                for _, job in ipairs(jobs) do
                    redis.call('RPUSH', queue_key, job)
                end
            end

            -- Keep the partition registered while a reservation is outstanding,
            -- otherwise an in-flight job whose worker dies is unreachable.
            local list_len = redis.call('LLEN', queue_key)
            local delayed_count = redis.call('ZCARD', delayed_key)
            local reserved_count = redis.call('ZCARD', reserved_index)
            if list_len == 0 and delayed_count == 0 and reserved_count == 0 then
                redis.call('SREM', partitions_key, partition)
            end

            return #jobs
        LUA;
    }

    /**
     * Return expired reservations to the partition's ready list.
     *
     * A reservation expires retry_after seconds after the pop. Reaching that
     * point means the worker holding the job never deleted or released it - it
     * was killed. The stock Redis driver recovers such jobs from its reserved
     * ZSET; this is the partitioned equivalent.
     *
     * KEYS[1] - reserved expiry index key (ZSET job id => expires at)
     * KEYS[2] - reserved jobs key (HASH job id => reserved payload)
     * KEYS[3] - partition queue key (LIST)
     * KEYS[4] - active jobs key (HASH)
     * KEYS[5] - partitions set key
     * KEYS[6] - delayed jobs key (ZSET)
     * KEYS[7] - metrics key
     * ARGV[1] - partition identifier
     * ARGV[2] - current timestamp
     */
    public static function migrateExpired(): string
    {
        return <<<'LUA'
            local reserved_index = KEYS[1]
            local reserved_key = KEYS[2]
            local queue_key = KEYS[3]
            local active_key = KEYS[4]
            local partitions_key = KEYS[5]
            local delayed_key = KEYS[6]
            local metrics_key = KEYS[7]
            local partition = ARGV[1]
            local current_time = tonumber(ARGV[2])

            local expired = redis.call('ZRANGEBYSCORE', reserved_index, '-inf', current_time)
            local recovered = 0

            for _, job_id in ipairs(expired) do
                local payload = redis.call('HGET', reserved_key, job_id)

                if payload then
                    redis.call('RPUSH', queue_key, payload)
                    recovered = recovered + 1
                end

                -- Drop the reservation and free the concurrency slot it held
                redis.call('HDEL', reserved_key, job_id)
                redis.call('HDEL', active_key, job_id)
                redis.call('ZREM', reserved_index, job_id)
            end

            if recovered > 0 then
                redis.call('SADD', partitions_key, partition)
            elseif #expired > 0 then
                -- Reservations vanished without payloads: the partition may now
                -- be idle, so do not leave it registered forever.
                if redis.call('LLEN', queue_key) == 0
                    and redis.call('ZCARD', delayed_key) == 0
                    and redis.call('ZCARD', reserved_index) == 0 then
                    redis.call('SREM', partitions_key, partition)
                    redis.call('HDEL', metrics_key, 'first_job_time')
                end
            end

            return recovered
        LUA;
    }

    /**
     * Forget one reservation and unregister the partition if it fell idle.
     *
     * The pop script keeps a partition registered while a reservation is
     * outstanding, so the last reservation to be cleared has to re-check
     * whether anything is left for that partition. Without this, partitions
     * accumulate in the set and workers keep selecting empty ones.
     *
     * KEYS[1] - reserved jobs key (HASH)
     * KEYS[2] - reserved expiry index key (ZSET)
     * KEYS[3] - active jobs key (HASH)
     * KEYS[4] - partition queue key (LIST)
     * KEYS[5] - delayed jobs key (ZSET)
     * KEYS[6] - partitions set key
     * KEYS[7] - metrics key
     * ARGV[1] - job id
     * ARGV[2] - partition identifier
     */
    public static function clearReservation(): string
    {
        return <<<'LUA'
            local reserved_key = KEYS[1]
            local reserved_index = KEYS[2]
            local active_key = KEYS[3]
            local queue_key = KEYS[4]
            local delayed_key = KEYS[5]
            local partitions_key = KEYS[6]
            local metrics_key = KEYS[7]
            local job_id = ARGV[1]
            local partition = ARGV[2]

            redis.call('HDEL', reserved_key, job_id)
            redis.call('ZREM', reserved_index, job_id)
            redis.call('HDEL', active_key, job_id)

            if redis.call('LLEN', queue_key) == 0
                and redis.call('ZCARD', delayed_key) == 0
                and redis.call('ZCARD', reserved_index) == 0 then
                redis.call('SREM', partitions_key, partition)
                redis.call('HDEL', metrics_key, 'first_job_time')
            end

            return 1
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
