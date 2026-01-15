<?php

declare(strict_types=1);

namespace YanGusik\BalancedQueue\Strategies;

use Illuminate\Contracts\Redis\Connection;
use YanGusik\BalancedQueue\Contracts\PartitionStrategy;

/**
 * Round-robin partition selection strategy.
 *
 * Selects partitions in strict sequential order: A → B → C → A → B → C...
 * Ensures fair distribution but requires state storage in Redis.
 */
class RoundRobinStrategy implements PartitionStrategy
{
    protected string $stateKeyPrefix;

    public function __construct(string $stateKeyPrefix = 'balanced-queue:rr-state')
    {
        $this->stateKeyPrefix = $stateKeyPrefix;
    }

    public function selectPartition(Connection $redis, string $queue, string $partitionsKey): ?string
    {
        $stateKey = $this->getStateKey($queue);

        // Get all partitions sorted for consistent ordering
        $partitions = $redis->smembers($partitionsKey);

        if (empty($partitions)) {
            return null;
        }

        sort($partitions);
        $count = count($partitions);

        // Get current index and increment atomically
        $currentIndex = (int) $redis->incr($stateKey);

        // Calculate the actual index (0-based)
        $index = ($currentIndex - 1) % $count;

        return $partitions[$index];
    }

    public function getName(): string
    {
        return 'round-robin';
    }

    protected function getStateKey(string $queue): string
    {
        return "{$this->stateKeyPrefix}:{$queue}";
    }
}
