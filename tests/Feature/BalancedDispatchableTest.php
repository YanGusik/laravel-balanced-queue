<?php

declare(strict_types=1);

namespace YanGusik\BalancedQueue\Tests\Feature;

use Illuminate\Contracts\Queue\ShouldQueue;
use YanGusik\BalancedQueue\Jobs\BalancedDispatchable;
use YanGusik\BalancedQueue\Tests\TestCase;

class TestJobWithUserId implements ShouldQueue
{
    use BalancedDispatchable;

    public function __construct(public int $userId)
    {
    }

    public function handle(): void
    {
    }
}

class TestJobWithCustomPartition implements ShouldQueue
{
    use BalancedDispatchable;

    public function __construct(public string $customKey)
    {
    }

    public function getPartitionKey(): string
    {
        return "custom:{$this->customKey}";
    }

    public function handle(): void
    {
    }
}

class TestJobWithoutPartition implements ShouldQueue
{
    use BalancedDispatchable;

    public function __construct(public string $data)
    {
    }

    public function handle(): void
    {
    }
}

class BalancedDispatchableTest extends TestCase
{
    public function test_partition_key_from_user_id_property(): void
    {
        $job = new TestJobWithUserId(userId: 42);

        $this->assertEquals('42', $job->getPartitionKey());
    }

    public function test_partition_key_from_custom_method(): void
    {
        $job = new TestJobWithCustomPartition(customKey: 'abc123');

        $this->assertEquals('custom:abc123', $job->getPartitionKey());
    }

    public function test_partition_key_default_when_no_property(): void
    {
        $job = new TestJobWithoutPartition(data: 'test');

        $this->assertEquals('default', $job->getPartitionKey());
    }

    public function test_on_partition_sets_explicit_key(): void
    {
        $job = new TestJobWithUserId(userId: 42);
        $job->onPartition('explicit-key');

        // Explicit key takes precedence over userId property
        $this->assertEquals('explicit-key', $job->getPartitionKey());
    }

    public function test_on_partition_returns_self(): void
    {
        $job = new TestJobWithUserId(userId: 1);
        $result = $job->onPartition('key');

        $this->assertSame($job, $result);
    }

    public function test_on_partition_accepts_integer(): void
    {
        $job = new TestJobWithUserId(userId: 1);
        $job->onPartition(999);

        $this->assertEquals('999', $job->getPartitionKey());
    }
}
