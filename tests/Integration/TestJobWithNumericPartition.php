<?php

declare(strict_types=1);

namespace YanGusik\BalancedQueue\Tests\Integration;

use Illuminate\Contracts\Queue\ShouldQueue;
use YanGusik\BalancedQueue\Jobs\BalancedDispatchable;

class TestJobWithNumericPartition implements ShouldQueue
{
    use BalancedDispatchable;

    public int $accountId;

    public function __construct(int $accountId)
    {
        $this->accountId = $accountId;
    }

    public function getPartitionKey(): string
    {
        // Return numeric string to test PR #8 fix
        return (string) $this->accountId;
    }

    public function handle(): void
    {
        // Test job - does nothing
    }
}
