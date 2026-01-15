<?php

declare(strict_types=1);

namespace YanGusik\BalancedQueue\Strategies;

use Illuminate\Contracts\Redis\Connection;
use YanGusik\BalancedQueue\Contracts\PartitionStrategy;

/**
 * Random partition selection strategy.
 *
 * Uses Redis SRANDMEMBER to randomly select a partition.
 * Fast and stateless, suitable for high-load systems.
 */
class RandomStrategy implements PartitionStrategy
{
    public function selectPartition(Connection $redis, string $queue, string $partitionsKey): ?string
    {
        $partition = $redis->srandmember($partitionsKey);

        return $partition ?: null;
    }

    public function getName(): string
    {
        return 'random';
    }
}
