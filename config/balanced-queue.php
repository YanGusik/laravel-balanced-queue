<?php

use YanGusik\BalancedQueue\Limiters\AdaptiveLimiter;
use YanGusik\BalancedQueue\Limiters\NullLimiter;
use YanGusik\BalancedQueue\Limiters\SimpleGroupLimiter;
use YanGusik\BalancedQueue\Strategies\RandomStrategy;
use YanGusik\BalancedQueue\Strategies\RoundRobinStrategy;
use YanGusik\BalancedQueue\Strategies\SmartFairStrategy;

return [
    /*
    |--------------------------------------------------------------------------
    | Enable Balanced Queue
    |--------------------------------------------------------------------------
    |
    | This option controls whether the balanced queue is enabled. When disabled,
    | the library will fall back to standard Laravel queue behavior.
    |
    */
    'enabled' => env('BALANCED_QUEUE_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Partition Selection Strategy
    |--------------------------------------------------------------------------
    |
    | The strategy used to select which partition to process next.
    | Available options: 'random', 'round-robin', 'smart'
    |
    | - random: Uses SRANDMEMBER for random selection (fast, stateless)
    | - round-robin: Strict sequential order A→B→C→A→B→C (fair, requires state)
    | - smart: Considers queue size and wait time (intelligent balancing)
    |
    */
    'strategy' => env('BALANCED_QUEUE_STRATEGY', 'round-robin'),

    /*
    |--------------------------------------------------------------------------
    | Strategy Configurations
    |--------------------------------------------------------------------------
    |
    | Configure each strategy with its specific options.
    | You can also add custom strategies by adding them here.
    |
    */
    'strategies' => [
        'random' => [
            'class' => RandomStrategy::class,
        ],

        'round-robin' => [
            'class' => RoundRobinStrategy::class,
            'state_key' => 'balanced-queue:rr-state',
        ],

        'smart' => [
            'class' => SmartFairStrategy::class,
            // Weight for wait time in score calculation (0.0 - 1.0)
            'weight_wait_time' => 0.6,
            // Weight for queue size in score calculation (0.0 - 1.0)
            'weight_queue_size' => 0.4,
            // Give priority to smaller queues
            'boost_small_queues' => true,
            // Queues smaller than this get a boost
            'small_queue_threshold' => 5,
            // Multiplier for small queue boost
            'boost_multiplier' => 1.5,
            'metrics_key_prefix' => 'balanced-queue:metrics',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Concurrency Limiter
    |--------------------------------------------------------------------------
    |
    | The limiter controls how many jobs can run concurrently per partition.
    | Available options: 'null', 'simple', 'adaptive'
    |
    | - null: No limits, all jobs run in parallel
    | - simple: Fixed limit per partition (e.g., max 2 concurrent per user)
    | - adaptive: Dynamic limit based on system load
    |
    */
    'limiter' => env('BALANCED_QUEUE_LIMITER', 'simple'),

    /*
    |--------------------------------------------------------------------------
    | Limiter Configurations
    |--------------------------------------------------------------------------
    |
    | Configure each limiter with its specific options.
    | You can also add custom limiters by adding them here.
    |
    */
    'limiters' => [
        'null' => [
            'class' => NullLimiter::class,
        ],

        'simple' => [
            'class' => SimpleGroupLimiter::class,
            // Maximum concurrent jobs per partition
            'max_concurrent' => (int) env('BALANCED_QUEUE_MAX_CONCURRENT', 2),
            // TTL for active job locks (in seconds)
            // IMPORTANT: Must be greater than queue's retry_after (config/queue.php)
            // If lock_ttl < retry_after, crashed jobs may bypass concurrency limits
            'lock_ttl' => (int) env('BALANCED_QUEUE_LOCK_TTL', 3600),
            'active_key_prefix' => 'balanced-queue:active',
        ],

        'adaptive' => [
            'class' => AdaptiveLimiter::class,
            // Minimum concurrent jobs per partition
            'base_limit' => 2,
            // Maximum concurrent jobs per partition (when system is underutilized)
            'max_limit' => 5,
            // TTL for active job locks (in seconds)
            // IMPORTANT: Must be greater than queue's retry_after (config/queue.php)
            // If lock_ttl < retry_after, crashed jobs may bypass concurrency limits
            'lock_ttl' => (int) env('BALANCED_QUEUE_LOCK_TTL', 3600),
            // Scale up when utilization is below this threshold
            'utilization_threshold' => 0.7,
            'active_key_prefix' => 'balanced-queue:active',
            'metrics_key_prefix' => 'balanced-queue:metrics',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Partition Resolver
    |--------------------------------------------------------------------------
    |
    | A closure that extracts the partition key from a job.
    | If your job implements getPartitionKey(), this is optional.
    |
    | Example:
    | 'partition_resolver' => function ($job) {
    |     return $job->userId ?? $job->user_id ?? 'default';
    | },
    |
    */
    'partition_resolver' => null,

    /*
    |--------------------------------------------------------------------------
    | Redis Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the Redis connection used by balanced queue.
    |
    */
    'redis' => [
        // Redis connection name from config/database.php
        'connection' => env('BALANCED_QUEUE_REDIS_CONNECTION', 'default'),
        // Key prefix for all balanced queue keys
        'prefix' => env('BALANCED_QUEUE_PREFIX', 'balanced-queue'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Prometheus Metrics Endpoint
    |--------------------------------------------------------------------------
    |
    | Configure the optional Prometheus metrics endpoint for monitoring.
    | When enabled, metrics will be exposed at the configured route.
    |
    */
    'prometheus' => [
        // Enable or disable the Prometheus endpoint
        'enabled' => env('BALANCED_QUEUE_PROMETHEUS_ENABLED', false),
        // The route path for the metrics endpoint
        'route' => env('BALANCED_QUEUE_PROMETHEUS_ROUTE', '/balanced-queue/metrics'),
        // Middleware to apply: 'ip_whitelist', 'auth.basic', or null (no middleware)
        'middleware' => env('BALANCED_QUEUE_PROMETHEUS_MIDDLEWARE', 'ip_whitelist'),
        // IP addresses/ranges allowed when using 'ip_whitelist' middleware
        'ip_whitelist' => [
            '127.0.0.1',
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
        ],
    ],
];
