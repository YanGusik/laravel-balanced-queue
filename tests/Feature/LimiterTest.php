<?php

declare(strict_types=1);

namespace YanGusik\BalancedQueue\Tests\Feature;

use Mockery;
use YanGusik\BalancedQueue\Limiters\AdaptiveLimiter;
use YanGusik\BalancedQueue\Limiters\NullLimiter;
use YanGusik\BalancedQueue\Limiters\SimpleGroupLimiter;
use YanGusik\BalancedQueue\Tests\TestCase;

class LimiterTest extends TestCase
{
    public function test_null_limiter_always_allows_processing(): void
    {
        $redis = $this->getMockRedis();
        $limiter = new NullLimiter();

        // Should always return true
        $this->assertTrue($limiter->canProcess($redis, 'test', 'user:1'));
        $this->assertTrue($limiter->acquire($redis, 'test', 'user:1', 'job-1'));
        $this->assertEquals(0, $limiter->getActiveCount($redis, 'test', 'user:1'));
    }

    public function test_simple_limiter_enforces_max_concurrent(): void
    {
        $redis = $this->getMockRedis();

        // Currently 2 active jobs (at limit)
        $redis->shouldReceive('hlen')
            ->with('balanced-queue:active:test:user:1')
            ->andReturn(2);

        $limiter = new SimpleGroupLimiter(maxConcurrent: 2);

        // Should not allow more processing
        $this->assertFalse($limiter->canProcess($redis, 'test', 'user:1'));
    }

    public function test_simple_limiter_allows_when_under_limit(): void
    {
        $redis = $this->getMockRedis();

        // Currently 1 active job (under limit)
        $redis->shouldReceive('hlen')
            ->with('balanced-queue:active:test:user:1')
            ->andReturn(1);

        $limiter = new SimpleGroupLimiter(maxConcurrent: 2);

        // Should allow processing
        $this->assertTrue($limiter->canProcess($redis, 'test', 'user:1'));
    }

    public function test_simple_limiter_acquire_with_lua_script(): void
    {
        $redis = $this->getMockRedis();

        // Mock the Lua eval call
        $redis->shouldReceive('eval')
            ->once()
            ->andReturn(1); // Success

        $limiter = new SimpleGroupLimiter(maxConcurrent: 2);
        $result = $limiter->acquire($redis, 'test', 'user:1', 'job-123');

        $this->assertTrue($result);
    }

    public function test_simple_limiter_acquire_fails_at_limit(): void
    {
        $redis = $this->getMockRedis();

        // Mock the Lua eval call returning 0 (at limit)
        $redis->shouldReceive('eval')
            ->once()
            ->andReturn(0);

        $limiter = new SimpleGroupLimiter(maxConcurrent: 2);
        $result = $limiter->acquire($redis, 'test', 'user:1', 'job-123');

        $this->assertFalse($result);
    }

    public function test_simple_limiter_release(): void
    {
        $redis = $this->getMockRedis();

        $redis->shouldReceive('hdel')
            ->with('balanced-queue:active:test:user:1', 'job-123')
            ->once();

        $limiter = new SimpleGroupLimiter();
        $limiter->release($redis, 'test', 'user:1', 'job-123');

        // No exception means success
        $this->assertTrue(true);
    }

    public function test_adaptive_limiter_scales_with_utilization(): void
    {
        $redis = $this->getMockRedis();

        // Low utilization - should allow more concurrent jobs
        $redis->shouldReceive('hget')
            ->with('balanced-queue:metrics:test:global', 'utilization')
            ->andReturn('0.3'); // 30% utilization

        $redis->shouldReceive('hlen')
            ->with('balanced-queue:active:test:user:1')
            ->andReturn(2); // Currently 2 active

        $limiter = new AdaptiveLimiter(
            baseLimit: 2,
            maxLimit: 5,
            utilizationThreshold: 0.7
        );

        // With 30% utilization and 0.7 threshold, we have 0.4 headroom
        // Extra slots = floor((5-2) * (0.4/0.7)) = floor(1.71) = 1
        // Current limit = 2 + 1 = 3
        // With 2 active, should allow more
        $this->assertTrue($limiter->canProcess($redis, 'test', 'user:1'));
    }

    public function test_adaptive_limiter_restricts_at_high_utilization(): void
    {
        $redis = $this->getMockRedis();

        // High utilization - should use base limit
        $redis->shouldReceive('hget')
            ->with('balanced-queue:metrics:test:global', 'utilization')
            ->andReturn('0.9'); // 90% utilization

        $redis->shouldReceive('hlen')
            ->with('balanced-queue:active:test:user:1')
            ->andReturn(2); // Currently at base limit

        $limiter = new AdaptiveLimiter(
            baseLimit: 2,
            maxLimit: 5,
            utilizationThreshold: 0.7
        );

        // At high utilization, limit stays at base (2)
        // With 2 active, should not allow more
        $this->assertFalse($limiter->canProcess($redis, 'test', 'user:1'));
    }

    public function test_limiter_names(): void
    {
        $this->assertEquals('null', (new NullLimiter())->getName());
        $this->assertEquals('simple', (new SimpleGroupLimiter())->getName());
        $this->assertEquals('adaptive', (new AdaptiveLimiter())->getName());
    }

    public function test_simple_limiter_get_max_concurrent(): void
    {
        $limiter = new SimpleGroupLimiter(maxConcurrent: 5);
        $this->assertEquals(5, $limiter->getMaxConcurrent());
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
