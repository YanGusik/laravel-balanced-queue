<?php

declare(strict_types=1);

namespace YanGusik\BalancedQueue\Support;

use Illuminate\Contracts\Redis\Connection;
use Illuminate\Support\Facades\Redis;

/**
 * Metrics helper for monitoring balanced queue performance.
 *
 * All methods accept clean queue names (e.g., 'default') without 'queues:' prefix.
 * Redis keys are constructed internally with proper prefixes for consistency.
 */
class Metrics
{
    protected Connection $redis;
    protected RedisKeys $keys;

    public function __construct(?Connection $redis = null, string $prefix = 'balanced-queue')
    {
        $this->redis = $redis ?? Redis::connection(config('balanced-queue.redis.connection'));
        $this->keys = new RedisKeys($prefix);
    }

    /**
     * Get all partitions for a queue.
     *
     * @param string $queueName Clean queue name (e.g., 'default')
     * @return array<string>
     */
    public function getPartitions(string $queueName): array
    {
        $key = $this->keys->partitions($queueName);

        return $this->redis->smembers($key) ?: [];
    }

    /**
     * Get queue size for a partition.
     *
     * @param string $queueName Clean queue name (e.g., 'default')
     * @param string $partition Partition key
     */
    public function getQueueSize(string $queueName, string $partition): int
    {
        $key = $this->keys->partitionQueue($queueName, $partition);

        return (int) $this->redis->llen($key);
    }

    /**
     * Get active job count for a partition.
     *
     * @param string $queueName Clean queue name (e.g., 'default')
     * @param string $partition Partition key
     */
    public function getActiveCount(string $queueName, string $partition): int
    {
        $key = $this->keys->active($queueName, $partition);

        return (int) $this->redis->hlen($key);
    }

    /**
     * Get total queued jobs across all partitions.
     *
     * @param string $queueName Clean queue name (e.g., 'default')
     */
    public function getTotalQueuedJobs(string $queueName): int
    {
        $partitions = $this->getPartitions($queueName);
        $total = 0;

        foreach ($partitions as $partition) {
            $total += $this->getQueueSize($queueName, $partition);
        }

        return $total;
    }

    /**
     * Get total active jobs across all partitions.
     *
     * @param string $queueName Clean queue name (e.g., 'default')
     */
    public function getTotalActiveJobs(string $queueName): int
    {
        $partitions = $this->getPartitions($queueName);
        $total = 0;

        foreach ($partitions as $partition) {
            $total += $this->getActiveCount($queueName, $partition);
        }

        return $total;
    }

    /**
     * Get detailed stats for a queue.
     *
     * @param string $queueName Clean queue name (e.g., 'default')
     * @return array<string, array{queued: int, active: int, metrics: array}>
     */
    public function getQueueStats(string $queueName): array
    {
        $partitions = $this->getPartitions($queueName);
        $stats = [];

        foreach ($partitions as $partition) {
            $metricsKey = $this->keys->metrics($queueName, $partition);
            $metrics = $this->redis->hgetall($metricsKey) ?: [];

            $stats[$partition] = [
                'queued' => $this->getQueueSize($queueName, $partition),
                'active' => $this->getActiveCount($queueName, $partition),
                'metrics' => $metrics,
            ];
        }

        return $stats;
    }

    /**
     * Get summary stats for a queue.
     *
     * @param string $queueName Clean queue name (e.g., 'default')
     * @return array{partitions: int, total_queued: int, total_active: int, partitions_stats: array}
     */
    public function getSummary(string $queueName): array
    {
        $stats = $this->getQueueStats($queueName);

        $totalQueued = 0;
        $totalActive = 0;

        foreach ($stats as $partitionStats) {
            $totalQueued += $partitionStats['queued'];
            $totalActive += $partitionStats['active'];
        }

        return [
            'partitions' => count($stats),
            'total_queued' => $totalQueued,
            'total_active' => $totalActive,
            'partitions_stats' => $stats,
        ];
    }

    /**
     * Clear all data for a queue (use with caution!).
     *
     * @param string $queueName Clean queue name (e.g., 'default')
     */
    public function clearQueue(string $queueName): void
    {
        $partitions = $this->getPartitions($queueName);

        foreach ($partitions as $partition) {
            $this->redis->del($this->keys->partitionQueue($queueName, $partition));
            $this->redis->del($this->keys->active($queueName, $partition));
            $this->redis->del($this->keys->delayed($queueName, $partition));
            $this->redis->del($this->keys->metrics($queueName, $partition));
        }

        $this->redis->del($this->keys->partitions($queueName));
    }

    /**
     * Get all active queues by finding partition keys.
     *
     * @return array<string>
     */
    public function getAllQueues(): array
    {
        // Laravel's Redis connection auto-adds the prefix from config,
        // so we only use our balanced-queue prefix in the pattern
        $prefix = $this->keys->getPrefix();
        $pattern = "{$prefix}:queues:*:partitions";
        $queues = [];

        // Use KEYS command to find all partition keys
        $keys = $this->redis->keys($pattern);

        if (! is_array($keys)) {
            return [];
        }

        // Get Laravel's Redis prefix for stripping from returned keys
        $laravelPrefix = config('database.redis.options.prefix', '');

        foreach ($keys as $key) {
            // Keys returned include Laravel prefix, strip it first
            $keyWithoutLaravelPrefix = $laravelPrefix ? substr($key, strlen($laravelPrefix)) : $key;

            // Extract queue name from: balanced-queue:queues:{queue}:partitions
            $escapedPrefix = preg_quote($prefix, '/');
            if (preg_match('/^' . $escapedPrefix . ':queues:(.+):partitions$/', $keyWithoutLaravelPrefix, $matches)) {
                $queues[] = $matches[1];
            }
        }

        return array_values(array_unique($queues));
    }

}
