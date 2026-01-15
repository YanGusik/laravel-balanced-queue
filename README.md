# Laravel Balanced Queue

A Laravel package for queue management with load balancing between partitions (user groups). Perfect for scenarios where you need fair job distribution and concurrency control per user/tenant.

## Problem Solved

Imagine you have an AI generation service where users can submit unlimited tasks. Without balanced queuing:
- One user can flood the queue and block everyone else
- No control over how many concurrent tasks a single user can run
- Resource-heavy users can exhaust API rate limits

**Laravel Balanced Queue** solves this by:
- ✅ Distributing jobs fairly across all users
- ✅ Limiting concurrent jobs per user (e.g., max 2 AI generations per user)
- ✅ Never rejecting jobs - they queue up and execute eventually
- ✅ Preventing single users from monopolizing workers

## Installation

```bash
composer require yangusik/laravel-balanced-queue
```

Publish the configuration:

```bash
php artisan vendor:publish --tag=balanced-queue-config
```

## Quick Start

### 1. Configure Your Queue Connection

In `config/queue.php`, add a balanced queue connection:

```php
'connections' => [
    'balanced' => [
        'driver' => 'balanced',
        'connection' => 'default', // Redis connection
        'queue' => 'default',
        'retry_after' => 90,
    ],
],
```

### 2. Create a Job with Partition Support

```php
<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use YanGusik\BalancedQueue\Jobs\BalancedDispatchable;

class GenerateAIImage implements ShouldQueue
{
    use BalancedDispatchable;

    public function __construct(
        public int $userId,
        public string $prompt
    ) {}

    public function handle(): void
    {
        // Your AI generation logic here
    }
}
```

### 3. Dispatch Jobs

```php
// Option 1: Automatic partition from $userId property
GenerateAIImage::dispatch($userId, $prompt)
    ->onConnection('balanced')
    ->onQueue('ai-generation');

// Option 2: Explicit partition
GenerateAIImage::dispatch($userId, $prompt)
    ->onPartition($userId)
    ->onConnection('balanced')
    ->onQueue('ai-generation');
```

### 4. Run Workers

```bash
php artisan queue:work balanced --queue=ai-generation
```

That's it! Jobs are now distributed fairly with max 2 concurrent per user.

## Configuration

### Partition Strategies

Choose how partitions are selected for processing:

```php
// config/balanced-queue.php
'strategy' => env('BALANCED_QUEUE_STRATEGY', 'round-robin'),
```

| Strategy | Description | Best For |
|----------|-------------|----------|
| `random` | Random partition selection (SRANDMEMBER) | High-load, stateless systems |
| `round-robin` | Strict sequential: A→B→C→A→B→C | Fair distribution, predictable |
| `smart` | Considers queue size + wait time | Preventing starvation |

### Concurrency Limiters

Control how many jobs run concurrently per partition:

```php
// config/balanced-queue.php
'limiter' => env('BALANCED_QUEUE_LIMITER', 'simple'),

'limiters' => [
    'simple' => [
        'max_concurrent' => 2, // Max 2 jobs per user at once
    ],
],
```

| Limiter | Description | Best For |
|---------|-------------|----------|
| `null` | No limits | When you only need distribution |
| `simple` | Fixed limit per partition | Most use cases |
| `adaptive` | Dynamic limit based on load | Auto-scaling scenarios |

### Environment Variables

```env
BALANCED_QUEUE_ENABLED=true
BALANCED_QUEUE_STRATEGY=round-robin
BALANCED_QUEUE_LIMITER=simple
BALANCED_QUEUE_MAX_CONCURRENT=2
BALANCED_QUEUE_PREFIX=balanced-queue
BALANCED_QUEUE_REDIS_CONNECTION=default
```

## Advanced Usage

### Custom Partition Logic

Override `getPartitionKey()` in your job:

```php
class ProcessOrder implements ShouldQueue
{
    use BalancedDispatchable;

    public function __construct(
        public Order $order
    ) {}

    public function getPartitionKey(): string
    {
        // Partition by merchant, not user
        return "merchant:{$this->order->merchant_id}";
    }
}
```

### Global Partition Resolver

Set a resolver in the config:

```php
// config/balanced-queue.php
'partition_resolver' => function ($job) {
    return $job->tenant_id ?? $job->user_id ?? 'default';
},
```

### Monitoring

```php
use YanGusik\BalancedQueue\Support\Metrics;

$metrics = new Metrics();

// Get queue summary
$summary = $metrics->getSummary('ai-generation');
// Returns: ['partitions' => 5, 'total_queued' => 100, 'total_active' => 10, ...]

// Get per-partition stats
$stats = $metrics->getQueueStats('ai-generation');
// Returns: ['user:1' => ['queued' => 10, 'active' => 2], ...]
```

### Custom Strategy

```php
use YanGusik\BalancedQueue\Contracts\PartitionStrategy;

class PriorityStrategy implements PartitionStrategy
{
    public function selectPartition($redis, $queue, $partitionsKey): ?string
    {
        // Your custom logic
    }

    public function getName(): string
    {
        return 'priority';
    }
}
```

Register in config:

```php
'strategies' => [
    'priority' => [
        'class' => App\Queue\PriorityStrategy::class,
    ],
],
```

### Custom Limiter

```php
use YanGusik\BalancedQueue\Contracts\ConcurrencyLimiter;

class PlanBasedLimiter implements ConcurrencyLimiter
{
    public function canProcess($redis, $queue, $partition): bool
    {
        $user = $this->getUserFromPartition($partition);
        $limit = $user->plan->concurrent_limit;

        return $this->getActiveCount($redis, $queue, $partition) < $limit;
    }

    // ... implement other methods
}
```

## How It Works

### Redis Structure

```
balanced-queue:{queue}:partitions         → SET of partition keys
balanced-queue:{queue}:{partition}        → LIST of jobs
balanced-queue:{queue}:{partition}:active → HASH of active job IDs
balanced-queue:metrics:{queue}:{partition} → HASH of metrics
```

### Flow

1. **Push**: Job is added to partition queue, partition registered in set
2. **Pop**: Strategy selects partition → Limiter checks concurrency → Job dequeued
3. **Process**: Job ID tracked in active hash
4. **Complete**: Job ID removed from active hash, slot freed

## Testing

```bash
composer test
```

## Horizon Compatibility

Works with Laravel Horizon out of the box. Jobs appear in Horizon dashboard as normal Redis jobs.

## Requirements

- PHP 8.1+
- Laravel 10.x, 11.x, or 12.x
- Redis (phpredis or predis)

## Credits

Inspired by [aloware/fair-queue](https://github.com/aloware/fair-queue) with improvements:
- Multiple partition strategies
- Built-in concurrency limiters
- Cleaner architecture
- Better extensibility

## License

MIT License. See [LICENSE](LICENSE) for details.
