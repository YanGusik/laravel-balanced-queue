<?php

declare(strict_types=1);

namespace YanGusik\BalancedQueue\Tests\Integration;

use Illuminate\Support\Facades\Redis;
use YanGusik\BalancedQueue\Tests\TestCase;

abstract class IntegrationTestCase extends TestCase
{
    protected function setUp(): void
    {
        if (!getenv('REDIS_INTEGRATION')) {
            $this->markTestSkipped('Set REDIS_INTEGRATION=1 to run integration tests');
        }

        parent::setUp();

        $this->flushRedis();
    }

    protected function tearDown(): void
    {
        $this->flushRedis();

        parent::tearDown();
    }

    protected function flushRedis(): void
    {
        try {
            Redis::connection(config('balanced-queue.redis.connection'))->flushdb();
        } catch (\Exception $e) {
            // Ignore if Redis not available
        }
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // Use real Redis for integration tests
        $app['config']->set('database.redis.default', [
            'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('REDIS_PORT') ?: 6380),
            'database' => (int) (getenv('REDIS_DB') ?: 15),
        ]);

        // Remove any prefix for predictable key names
        $app['config']->set('database.redis.options.prefix', '');

        // Configure balanced queue connection
        $app['config']->set('queue.connections.balanced', [
            'driver' => 'balanced',
            'connection' => 'default',
            'queue' => 'default',
            'retry_after' => 90,
            'block_for' => null,
        ]);
    }
}
