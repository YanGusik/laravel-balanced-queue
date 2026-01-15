<?php

declare(strict_types=1);

namespace YanGusik\BalancedQueue\Strategies;

use Illuminate\Contracts\Redis\Connection;
use YanGusik\BalancedQueue\Contracts\PartitionStrategy;

/**
 * Smart fair partition selection strategy.
 *
 * Considers queue size and wait time to prioritize partitions.
 * Gives priority to smaller queues to prevent starvation.
 *
 * Score calculation:
 * score = (wait_time * weight_wait_time) + (queue_size_normalized * weight_queue_size)
 * if queue_size < small_queue_threshold: score *= boost_multiplier
 */
class SmartFairStrategy implements PartitionStrategy
{
    protected float $weightWaitTime;
    protected float $weightQueueSize;
    protected bool $boostSmallQueues;
    protected int $smallQueueThreshold;
    protected float $boostMultiplier;
    protected string $metricsKeyPrefix;

    public function __construct(
        float $weightWaitTime = 0.6,
        float $weightQueueSize = 0.4,
        bool $boostSmallQueues = true,
        int $smallQueueThreshold = 5,
        float $boostMultiplier = 1.5,
        string $metricsKeyPrefix = 'balanced-queue:metrics'
    ) {
        $this->weightWaitTime = $weightWaitTime;
        $this->weightQueueSize = $weightQueueSize;
        $this->boostSmallQueues = $boostSmallQueues;
        $this->smallQueueThreshold = $smallQueueThreshold;
        $this->boostMultiplier = $boostMultiplier;
        $this->metricsKeyPrefix = $metricsKeyPrefix;
    }

    public function selectPartition(Connection $redis, string $queue, string $partitionsKey): ?string
    {
        $partitions = $redis->smembers($partitionsKey);

        if (empty($partitions)) {
            return null;
        }

        $scores = [];
        $maxQueueSize = 1;
        $partitionData = [];

        // First pass: collect data and find max queue size
        foreach ($partitions as $partition) {
            $queueKey = "balanced-queue:{$queue}:{$partition}";
            $metricsKey = "{$this->metricsKeyPrefix}:{$queue}:{$partition}";

            $queueSize = (int) $redis->llen($queueKey);
            $firstJobTime = $redis->hget($metricsKey, 'first_job_time');
            $waitTime = $firstJobTime ? (time() - (int) $firstJobTime) : 0;

            $partitionData[$partition] = [
                'queue_size' => $queueSize,
                'wait_time' => $waitTime,
            ];

            if ($queueSize > $maxQueueSize) {
                $maxQueueSize = $queueSize;
            }
        }

        // Second pass: calculate scores
        foreach ($partitionData as $partition => $data) {
            // Skip partitions with no jobs
            if ($data['queue_size'] === 0) {
                continue;
            }

            // Normalize queue size (0 to 1, inverted so smaller is better)
            $normalizedSize = 1 - ($data['queue_size'] / $maxQueueSize);

            // Calculate base score
            $score = ($data['wait_time'] * $this->weightWaitTime)
                   + ($normalizedSize * 100 * $this->weightQueueSize);

            // Boost small queues
            if ($this->boostSmallQueues && $data['queue_size'] < $this->smallQueueThreshold) {
                $score *= $this->boostMultiplier;
            }

            $scores[$partition] = $score;
        }

        if (empty($scores)) {
            return null;
        }

        // Select partition with highest score
        arsort($scores);

        return array_key_first($scores);
    }

    public function getName(): string
    {
        return 'smart';
    }
}
