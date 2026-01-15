<?php

declare(strict_types=1);

namespace YanGusik\BalancedQueue\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use YanGusik\BalancedQueue\BalancedQueueServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            BalancedQueueServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Setup default database to use sqlite :memory:
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Setup Redis for testing (uses mock by default)
        $app['config']->set('database.redis.client', 'phpredis');
        $app['config']->set('database.redis.default', [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => env('REDIS_PORT', 6379),
            'database' => env('REDIS_DB', 15), // Use separate DB for tests
        ]);

        // Setup balanced queue config
        $app['config']->set('balanced-queue.enabled', true);
        $app['config']->set('balanced-queue.strategy', 'random');
        $app['config']->set('balanced-queue.limiter', 'simple');
        $app['config']->set('balanced-queue.limiters.simple.max_concurrent', 2);
    }

    /**
     * Get a mock Redis connection for testing.
     */
    protected function getMockRedis(): \Mockery\MockInterface
    {
        return \Mockery::mock(\Illuminate\Contracts\Redis\Connection::class);
    }
}
