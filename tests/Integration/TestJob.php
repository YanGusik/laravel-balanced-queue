<?php

declare(strict_types=1);

namespace YanGusik\BalancedQueue\Tests\Integration;

use Illuminate\Contracts\Queue\ShouldQueue;
use YanGusik\BalancedQueue\Jobs\BalancedDispatchable;

class TestJob implements ShouldQueue
{
    use BalancedDispatchable;

    public int $userId;
    public array $data;

    public function __construct(array $data = [])
    {
        $this->userId = $data['user_id'] ?? 0;
        $this->data = $data;
    }

    public function getPartitionKey(): string
    {
        return "user:{$this->userId}";
    }

    public function handle(): void
    {
        // Test job - does nothing
    }
}
