<?php

declare(strict_types=1);

namespace YanGusik\BalancedQueue\Support;

use Illuminate\Contracts\Redis\Connection;
use Illuminate\Support\Facades\Redis;

/**
 * Metrics helper for monitoring balanced queue performance.
 */
class Metrics
{
    protected Connection $redis;
    protected string $prefix;

    public function __construct(?Connection $redis = null, string $prefix = 'balanced-queue')
    {
        $this->redis = $redis ?? Redis::connection();
        $this->prefix = $prefix;
    }

    /**
     * Get all partitions for a queue.
     *
     * @return array<string>
     */
    public function getPartitions(string $queue): array
    {
        $queueKey = "queues:{$queue}";
        $key = "{$this->prefix}:{$queueKey}:partitions";

        return $this->redis->smembers($key) ?: [];
    }

    /**
     * Get queue size for a partition.
     */
    public function getQueueSize(string $queue, string $partition): int
    {
        $queueKey = "queues:{$queue}";
        $key = "{$this->prefix}:{$queueKey}:{$partition}";

        return (int) $this->redis->llen($key);
    }

    /**
     * Get active job count for a partition.
     */
    public function getActiveCount(string $queue, string $partition): int
    {
        $queueKey = "queues:{$queue}";
        $key = "{$this->prefix}:{$queueKey}:{$partition}:active";

        return (int) $this->redis->hlen($key);
    }

    /**
     * Get total queued jobs across all partitions.
     */
    public function getTotalQueuedJobs(string $queue): int
    {
        $partitions = $this->getPartitions($queue);
        $total = 0;

        foreach ($partitions as $partition) {
            $total += $this->getQueueSize($queue, $partition);
        }

        return $total;
    }

    /**
     * Get total active jobs across all partitions.
     */
    public function getTotalActiveJobs(string $queue): int
    {
        $partitions = $this->getPartitions($queue);
        $total = 0;

        foreach ($partitions as $partition) {
            $total += $this->getActiveCount($queue, $partition);
        }

        return $total;
    }

    /**
     * Get detailed stats for a queue.
     *
     * @return array<string, array{queued: int, active: int, metrics: array}>
     */
    public function getQueueStats(string $queue): array
    {
        $partitions = $this->getPartitions($queue);
        $stats = [];
        $queueKey = "queues:{$queue}";

        foreach ($partitions as $partition) {
            $metricsKey = "{$this->prefix}:metrics:{$queueKey}:{$partition}";
            $metrics = $this->redis->hgetall($metricsKey) ?: [];

            $stats[$partition] = [
                'queued' => $this->getQueueSize($queue, $partition),
                'active' => $this->getActiveCount($queue, $partition),
                'metrics' => $metrics,
            ];
        }

        return $stats;
    }

    /**
     * Get summary stats for a queue.
     *
     * @return array{partitions: int, total_queued: int, total_active: int, partitions_stats: array}
     */
    public function getSummary(string $queue): array
    {
        $stats = $this->getQueueStats($queue);

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
     */
    public function clearQueue(string $queue): void
    {
        $partitions = $this->getPartitions($queue);
        $queueKey = "queues:{$queue}";

        foreach ($partitions as $partition) {
            $this->redis->del("{$this->prefix}:{$queueKey}:{$partition}");
            $this->redis->del("{$this->prefix}:{$queueKey}:{$partition}:active");
            $this->redis->del("{$this->prefix}:{$queueKey}:{$partition}:delayed");
            $this->redis->del("{$this->prefix}:metrics:{$queueKey}:{$partition}");
        }

        $this->redis->del("{$this->prefix}:{$queueKey}:partitions");
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
        $pattern = "{$this->prefix}:queues:*:partitions";
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
            $escapedPrefix = preg_quote($this->prefix, '/');
            if (preg_match('/^' . $escapedPrefix . ':queues:(.+):partitions$/', $keyWithoutLaravelPrefix, $matches)) {
                $queues[] = $matches[1];
            }
        }

        return array_values(array_unique($queues));
    }
}
