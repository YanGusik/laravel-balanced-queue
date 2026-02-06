<?php

declare(strict_types=1);

namespace YanGusik\BalancedQueue\Support;

/**
 * Redis key generator for balanced queue.
 *
 * Centralized key generation to ensure consistency across all components.
 * All methods accept clean queue names (e.g., 'default') without 'queues:' prefix.
 */
class RedisKeys
{
    public function __construct(
        protected string $prefix = 'balanced-queue'
    ) {}

    /**
     * Get the partitions set key.
     */
    public function partitions(string $queueName): string
    {
        return "{$this->prefix}:queues:{$queueName}:partitions";
    }

    /**
     * Get the queue key for a partition.
     */
    public function partitionQueue(string $queueName, string $partition): string
    {
        return "{$this->prefix}:queues:{$queueName}:{$partition}";
    }

    /**
     * Get the active jobs key for a partition.
     */
    public function active(string $queueName, string $partition): string
    {
        return "{$this->prefix}:queues:{$queueName}:{$partition}:active";
    }

    /**
     * Get the metrics key for a partition.
     */
    public function metrics(string $queueName, string $partition): string
    {
        return "{$this->prefix}:metrics:{$queueName}:{$partition}";
    }

    /**
     * Get the delayed jobs key for a partition.
     */
    public function delayed(string $queueName, string $partition): string
    {
        return "{$this->prefix}:queues:{$queueName}:{$partition}:delayed";
    }

    /**
     * Get the round-robin state key.
     */
    public function roundRobinState(string $queueName): string
    {
        return "{$this->prefix}:rr-state:{$queueName}";
    }

    /**
     * Get the prefix.
     */
    public function getPrefix(): string
    {
        return $this->prefix;
    }
}
