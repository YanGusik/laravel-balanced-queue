<?php

declare(strict_types=1);

namespace YanGusik\BalancedQueue\Tests\Feature;

use Mockery;
use YanGusik\BalancedQueue\Support\Metrics;
use YanGusik\BalancedQueue\Tests\TestCase;

class MetricsTest extends TestCase
{
    public function test_get_all_queues_returns_unique_queues(): void
    {
        $redis = $this->getMockRedis();

        // Redis keys() returns keys WITH Laravel prefix
        $laravelPrefix = config('database.redis.options.prefix', '');

        $redis->shouldReceive('keys')
            ->once()
            ->with('balanced-queue:queues:*:partitions')
            ->andReturn([
                $laravelPrefix.'balanced-queue:queues:default:partitions',
                $laravelPrefix.'balanced-queue:queues:emails:partitions',
                $laravelPrefix.'balanced-queue:queues:notifications:partitions',
            ]);

        $metrics = new Metrics($redis, 'balanced-queue');
        $queues = $metrics->getAllQueues();

        $this->assertCount(3, $queues);
        $this->assertContains('default', $queues);
        $this->assertContains('emails', $queues);
        $this->assertContains('notifications', $queues);
    }

    public function test_get_all_queues_returns_empty_when_no_queues(): void
    {
        $redis = $this->getMockRedis();

        $redis->shouldReceive('keys')
            ->once()
            ->with('balanced-queue:queues:*:partitions')
            ->andReturn([]);

        $metrics = new Metrics($redis, 'balanced-queue');
        $queues = $metrics->getAllQueues();

        $this->assertEmpty($queues);
    }

    public function test_get_all_queues_handles_keys_failure(): void
    {
        $redis = $this->getMockRedis();

        $redis->shouldReceive('keys')
            ->once()
            ->with('balanced-queue:queues:*:partitions')
            ->andReturn(false);

        $metrics = new Metrics($redis, 'balanced-queue');
        $queues = $metrics->getAllQueues();

        $this->assertEmpty($queues);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
