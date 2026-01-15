<?php

declare(strict_types=1);

namespace YanGusik\BalancedQueue\Contracts;

use Illuminate\Contracts\Redis\Connection;

interface PartitionStrategy
{
    /**
     * Select the next partition to process from available partitions.
     *
     * @param Connection $redis Redis connection instance
     * @param string $queue The queue name
     * @param string $partitionsKey Redis key containing the set of partitions
     * @return string|null The selected partition key, or null if no partitions available
     */
    public function selectPartition(Connection $redis, string $queue, string $partitionsKey): ?string;

    /**
     * Get the strategy name for identification.
     *
     * @return string
     */
    public function getName(): string;
}
