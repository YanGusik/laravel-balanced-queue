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

        // First scan returns some keys, cursor moves
        $redis->shouldReceive('scan')
            ->once()
            ->with('0', ['match' => 'balanced-queue:queues:*:partitions', 'count' => 100])
            ->andReturn(['5', [
                'balanced-queue:queues:default:partitions',
                'balanced-queue:queues:emails:partitions',
            ]]);

        // Second scan returns more keys, cursor back to 0
        $redis->shouldReceive('scan')
            ->once()
            ->with('5', ['match' => 'balanced-queue:queues:*:partitions', 'count' => 100])
            ->andReturn(['0', [
                'balanced-queue:queues:notifications:partitions',
                'balanced-queue:queues:default:partitions', // duplicate
            ]]);

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

        $redis->shouldReceive('scan')
            ->once()
            ->with('0', ['match' => 'balanced-queue:queues:*:partitions', 'count' => 100])
            ->andReturn(['0', []]);

        $metrics = new Metrics($redis, 'balanced-queue');
        $queues = $metrics->getAllQueues();

        $this->assertEmpty($queues);
    }

    public function test_get_all_queues_handles_scan_failure(): void
    {
        $redis = $this->getMockRedis();

        $redis->shouldReceive('scan')
            ->once()
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
