<?php

declare(strict_types=1);

namespace YanGusik\BalancedQueue\Tests\Feature;

use Mockery;
use YanGusik\BalancedQueue\Strategies\RandomStrategy;
use YanGusik\BalancedQueue\Strategies\RoundRobinStrategy;
use YanGusik\BalancedQueue\Strategies\SmartFairStrategy;
use YanGusik\BalancedQueue\Tests\TestCase;

class StrategyTest extends TestCase
{
    public function test_random_strategy_selects_partition(): void
    {
        $redis = $this->getMockRedis();
        $redis->shouldReceive('srandmember')
            ->with('balanced-queue:test:partitions')
            ->andReturn('user:1');

        $strategy = new RandomStrategy();
        $result = $strategy->selectPartition($redis, 'test', 'balanced-queue:test:partitions');

        $this->assertEquals('user:1', $result);
    }

    public function test_random_strategy_returns_null_when_no_partitions(): void
    {
        $redis = $this->getMockRedis();
        $redis->shouldReceive('srandmember')
            ->with('balanced-queue:test:partitions')
            ->andReturn(null);

        $strategy = new RandomStrategy();
        $result = $strategy->selectPartition($redis, 'test', 'balanced-queue:test:partitions');

        $this->assertNull($result);
    }

    public function test_round_robin_strict_order(): void
    {
        $redis = $this->getMockRedis();
        $partitions = ['user:1', 'user:2', 'user:3'];

        $redis->shouldReceive('smembers')
            ->with('balanced-queue:test:partitions')
            ->andReturn($partitions);

        // Simulate sequential increments: 1, 2, 3, 4, 5, 6
        $counter = 0;
        $redis->shouldReceive('incr')
            ->with('balanced-queue:rr-state:test')
            ->andReturnUsing(function () use (&$counter) {
                return ++$counter;
            });

        $strategy = new RoundRobinStrategy();
        $results = [];

        // Get 6 partitions (should cycle through A→B→C→A→B→C)
        for ($i = 0; $i < 6; $i++) {
            $results[] = $strategy->selectPartition($redis, 'test', 'balanced-queue:test:partitions');
        }

        // Verify strict round-robin order
        $this->assertEquals([
            'user:1', 'user:2', 'user:3',
            'user:1', 'user:2', 'user:3',
        ], $results);
    }

    public function test_round_robin_returns_null_when_no_partitions(): void
    {
        $redis = $this->getMockRedis();
        $redis->shouldReceive('smembers')
            ->with('balanced-queue:test:partitions')
            ->andReturn([]);

        $strategy = new RoundRobinStrategy();
        $result = $strategy->selectPartition($redis, 'test', 'balanced-queue:test:partitions');

        $this->assertNull($result);
    }

    public function test_smart_strategy_prioritizes_small_queues(): void
    {
        $redis = $this->getMockRedis();

        // Two partitions: one with 50 jobs, one with 5 jobs
        $redis->shouldReceive('smembers')
            ->with('balanced-queue:test:partitions')
            ->andReturn(['user:heavy', 'user:light']);

        // user:heavy has 50 jobs
        $redis->shouldReceive('llen')
            ->with('balanced-queue:test:user:heavy')
            ->andReturn(50);

        // user:light has 5 jobs (but still > 0, should get boost)
        $redis->shouldReceive('llen')
            ->with('balanced-queue:test:user:light')
            ->andReturn(3);

        // Metrics for wait time
        $redis->shouldReceive('hget')
            ->with('balanced-queue:metrics:test:user:heavy', 'first_job_time')
            ->andReturn((string) (time() - 10)); // 10 seconds wait

        $redis->shouldReceive('hget')
            ->with('balanced-queue:metrics:test:user:light', 'first_job_time')
            ->andReturn((string) (time() - 10)); // 10 seconds wait

        $strategy = new SmartFairStrategy(
            weightWaitTime: 0.6,
            weightQueueSize: 0.4,
            boostSmallQueues: true,
            smallQueueThreshold: 5,
            boostMultiplier: 1.5
        );

        $result = $strategy->selectPartition($redis, 'test', 'balanced-queue:test:partitions');

        // The smaller queue (user:light) should be selected due to boost
        $this->assertEquals('user:light', $result);
    }

    public function test_smart_strategy_skips_empty_partitions(): void
    {
        $redis = $this->getMockRedis();

        $redis->shouldReceive('smembers')
            ->with('balanced-queue:test:partitions')
            ->andReturn(['user:empty', 'user:active']);

        // user:empty has 0 jobs
        $redis->shouldReceive('llen')
            ->with('balanced-queue:test:user:empty')
            ->andReturn(0);

        // user:active has 5 jobs
        $redis->shouldReceive('llen')
            ->with('balanced-queue:test:user:active')
            ->andReturn(5);

        $redis->shouldReceive('hget')
            ->with('balanced-queue:metrics:test:user:empty', 'first_job_time')
            ->andReturn(null);

        $redis->shouldReceive('hget')
            ->with('balanced-queue:metrics:test:user:active', 'first_job_time')
            ->andReturn((string) time());

        $strategy = new SmartFairStrategy();
        $result = $strategy->selectPartition($redis, 'test', 'balanced-queue:test:partitions');

        $this->assertEquals('user:active', $result);
    }

    public function test_strategy_name(): void
    {
        $this->assertEquals('random', (new RandomStrategy())->getName());
        $this->assertEquals('round-robin', (new RoundRobinStrategy())->getName());
        $this->assertEquals('smart', (new SmartFairStrategy())->getName());
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
