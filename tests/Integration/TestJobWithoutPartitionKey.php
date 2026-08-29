<?php

declare(strict_types=1);

namespace YanGusik\BalancedQueue\Tests\Integration;

use Illuminate\Contracts\Queue\ShouldQueue;
use YanGusik\BalancedQueue\Jobs\BalancedDispatchable;

/**
 * A job with no partitionKey / getPartitionKey() override, so partition
 * resolution falls through to the global resolver set via
 * BalancedRedisQueue::setPartitionResolver().
 */
class TestJobWithoutPartitionKey implements ShouldQueue
{
    use BalancedDispatchable;

    public function handle(): void
    {
        // Test job - does nothing
    }
}
