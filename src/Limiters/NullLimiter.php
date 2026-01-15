<?php

declare(strict_types=1);

namespace YanGusik\BalancedQueue\Limiters;

use Illuminate\Contracts\Redis\Connection;
use YanGusik\BalancedQueue\Contracts\ConcurrencyLimiter;

/**
 * Null limiter - no concurrency restrictions.
 *
 * All jobs execute in parallel without any limitations.
 * Use when you don't need to control concurrent execution.
 */
class NullLimiter implements ConcurrencyLimiter
{
    public function canProcess(Connection $redis, string $queue, string $partition): bool
    {
        return true;
    }

    public function acquire(Connection $redis, string $queue, string $partition, string $jobId): bool
    {
        return true;
    }

    public function release(Connection $redis, string $queue, string $partition, string $jobId): void
    {
        // No-op
    }

    public function getActiveCount(Connection $redis, string $queue, string $partition): int
    {
        return 0;
    }

    public function getName(): string
    {
        return 'null';
    }
}
