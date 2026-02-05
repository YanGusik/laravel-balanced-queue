# Laravel Balanced Queue

A Laravel package for queue management with load balancing between partitions (user groups). Perfect for scenarios where you need fair job distribution and concurrency control per user/tenant.

## Problem Solved

Imagine you have an AI generation service where users can submit unlimited tasks. Without balanced queuing:
- One user can flood the queue and block everyone else
- No control over how many concurrent tasks a single user can run
- Resource-heavy users can exhaust API rate limits

**Laravel Balanced Queue** solves this by:
- Distributing jobs fairly across all users (round-robin)
- Limiting concurrent jobs per user (e.g., max 2 AI generations per user)
- Never rejecting jobs - they queue up and execute eventually
- Preventing single users from monopolizing workers

## How It Works

### The Problem

```
Standard Laravel Queue (FIFO):

Queue: [A1][A2][A3][A4][A5][A6][A7][A8][B1][B2][C1][C2]
        ─────────────────────────────→ time

Execution order: A1 → A2 → A3 → A4 → A5 → A6 → A7 → A8 → B1 → B2 → C1 → C2

User A submitted 8 tasks first.
User B and C must wait until ALL of User A's tasks complete!
```

### The Solution

Balanced Queue partitions jobs by user and rotates between them:

```
Balanced Queue (partitioned):

Partition A: [A1][A2][A3][A4][A5][A6][A7][A8]
Partition B: [B1][B2]
Partition C: [C1][C2]

Execution order: A1 → B1 → C1 → A2 → B2 → C2 → A3 → A4 → A5...

Everyone gets fair turns! User B doesn't wait for all 8 of User A's tasks.
```

### Strategy Comparison

**Round-Robin Strategy** (recommended) — strict rotation:

```
Partitions:  [A: 5 jobs] [B: 2 jobs] [C: 2 jobs]

Worker 1:    A1 ──────── B1 ──────── C1 ──────── A2 ──────── B2 ────────
Worker 2:       ──────── A3 ──────── C2 ──────── A4 ──────── A5 ────────

Order: A → B → C → A → B → C → A → A → A
       └── cycles through all partitions equally
```

**Random Strategy** — unpredictable but fast:

```
Partitions:  [A: 5 jobs] [B: 2 jobs] [C: 2 jobs]

Worker 1:    A1 ──────── A2 ──────── B1 ──────── C1 ──────── A3 ────────
Worker 2:       ──────── C2 ──────── A4 ──────── B2 ──────── A5 ────────

Order: Random selection each time (stateless, good for high load)
```

**Smart Strategy** — prioritizes smaller queues:

```
Partitions:  [A: 50 jobs] [B: 3 jobs] [C: 2 jobs]
                  │            │           │
             (deprioritized)  (boost)    (boost)

Worker 1:    B1 ──────── C1 ──────── B2 ──────── C2 ──────── B3 ────────
Worker 2:       ──────── A1 ──────── A2 ──────── A3 ──────── A4 ────────

Small queues (B, C) get processed faster, preventing starvation.
User A's 50 jobs won't block users with just a few tasks.
```

### Concurrency Limiting

With `max_concurrent: 2` per partition:

```
Partition A: [A1][A2][A3][A4][A5] waiting
                 │   │
             ┌───┴───┴───┐
             │  Active   │
             │  A1  A2   │ ← max 2 running simultaneously
             └───────────┘

A3, A4, A5 wait until A1 or A2 completes.
Other partitions (B, C) can still run their jobs!
```

## Installation

```bash
composer require yangusik/laravel-balanced-queue
```

Publish the configuration:

```bash
php artisan vendor:publish --tag=balanced-queue-config
```

## Quick Start

### Step 1: Add Queue Connection

Add to `config/queue.php`:

```php
'connections' => [
    // ... your existing connections

    'balanced' => [
        'driver' => 'balanced',
        'connection' => 'default', // Redis connection from database.php
        'queue' => 'default',
        'retry_after' => 90,
    ],
],
```

### Step 2: Create a Job

```php
<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use YanGusik\BalancedQueue\Jobs\BalancedDispatchable;

class GenerateAIImage implements ShouldQueue
{
    use BalancedDispatchable;  // Instead of standard Dispatchable

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

### Step 3: Dispatch Jobs

```php
// The job will automatically use $userId as partition key
GenerateAIImage::dispatch($userId, $prompt)
    ->onConnection('balanced')
    ->onQueue('ai-generation');

// Or explicitly set partition
GenerateAIImage::dispatch($userId, $prompt)
    ->onPartition($userId)
    ->onConnection('balanced');
```

### Step 4: Run Workers

```bash
# Standard Laravel worker
php artisan queue:work balanced --queue=ai-generation

# Or with Horizon (see Horizon section below)
```

That's it! Jobs are now distributed fairly with max 2 concurrent per user.

---

## Laravel Horizon Integration

### Configuration

Add a supervisor for balanced queue in `config/horizon.php`:

```php
'environments' => [
    'local' => [
        // Your other supervisors...

        'supervisor-balanced' => [
            'connection' => 'balanced',      // Must match connection name in queue.php
            'queue' => ['default'],          // Queue names to process
            'maxProcesses' => 4,             // Number of workers
            'tries' => 1,
            'timeout' => 300,
        ],
    ],

    'production' => [
        'supervisor-balanced' => [
            'connection' => 'balanced',
            'queue' => ['default', 'ai-generation'],
            'maxProcesses' => 10,
            'tries' => 1,
            'timeout' => 300,
            'balance' => 'auto',             // Horizon's auto-scaling
        ],
    ],
],
```

### Important: What Works and What Doesn't with Horizon

| Feature | Status | Notes |
|---------|--------|-------|
| Job execution | Works | Jobs execute normally through Horizon workers |
| Failed jobs list | Works | Failed jobs appear in Horizon |
| Worker metrics | Works | CPU, memory, throughput visible |
| **Pending jobs count** | **Doesn't work** | Horizon shows 0 pending |
| **Completed jobs list** | **Experimental** | Enable with `horizon.enabled` config |
| **Recent jobs list** | **Experimental** | Enable with `horizon.enabled` config |
| **horizon:clear** | **Doesn't work** | Use `balanced-queue:clear` instead |

**Why?** Balanced Queue uses a different Redis key structure (partitioned queues) than standard Laravel queues. Horizon expects jobs in `queues:{name}` but we store them in `balanced-queue:queues:{name}:{partition}`.

### Experimental: Horizon Dashboard Integration

You can enable experimental Horizon events integration to see completed/recent jobs in the Horizon dashboard:

```php
// config/balanced-queue.php
'horizon' => [
    'enabled' => 'auto',  // 'auto', true, or false
],
```

| Value | Behavior |
|-------|----------|
| `'auto'` | Enable if `laravel/horizon` is installed (default) |
| `true` | Always enable (requires Horizon) |
| `false` | Disable Horizon events |

Or via environment variable:

```env
BALANCED_QUEUE_HORIZON_ENABLED=auto
```

**Warning:** This feature is experimental and adds a small overhead per job (writing to Horizon's Redis keys). Test thoroughly in your environment before using in production.

**What this enables:**
- Completed jobs appear in Horizon dashboard
- Recent jobs list works
- Job metrics (throughput, runtime) are tracked

**What still doesn't work:**
- Pending jobs count (architectural limitation)
- `horizon:clear` command (use `balanced-queue:clear`)

### Monitoring Commands

Use built-in commands instead of Horizon for queue management:

```bash
# View live statistics (updates every 2 seconds)
php artisan balanced-queue:table --watch

# One-time stats view
php artisan balanced-queue:table

# Clear all jobs from balanced queue
php artisan balanced-queue:clear

# Clear specific partition only
php artisan balanced-queue:clear --partition=user:123

# Force clear without confirmation
php artisan balanced-queue:clear --force
```

**Example output of `balanced-queue:table --watch`:**

```
╔══════════════════════════════════════════════════════════════╗
║            BALANCED QUEUE MONITOR - default
╚══════════════════════════════════════════════════════════════╝

+------------+--------+---------+--------+-----------+
| Partition  | Status | Pending | Active | Processed |
+------------+--------+---------+--------+-----------+
| user:456   | ●      | 15      | 2      | 45        |
| user:123   | ●      | 8       | 2      | 120       |
| user:789   | ○      | 3       | 0      | 12        |
+------------+--------+---------+--------+-----------+

  Total: 26 pending, 4 active, 3 partitions

  Strategy: round-robin | Max concurrent: 2
  Updated: 14:32:15
```

---

## Configuration

### Partition Strategies

Choose how partitions are selected for processing:

```php
// config/balanced-queue.php
'strategy' => env('BALANCED_QUEUE_STRATEGY', 'round-robin'),
```

| Strategy | Description | Best For |
|----------|-------------|----------|
| `random` | Random partition selection (Redis SRANDMEMBER) | High-load, stateless systems |
| `round-robin` | Strict sequential: A→B→C→A→B→C | **Recommended.** Fair distribution |
| `smart` | Considers queue size + wait time, boosts small queues | Preventing starvation of small users |

### Concurrency Limiters

Control how many jobs run simultaneously per partition:

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
| `null` | No limits, unlimited parallel jobs | When you only need fair distribution |
| `simple` | Fixed limit per partition (e.g., max 2) | **Recommended.** Most use cases |
| `adaptive` | Dynamic limit based on system load | Auto-scaling scenarios |

### Environment Variables

```env
BALANCED_QUEUE_ENABLED=true
BALANCED_QUEUE_STRATEGY=round-robin
BALANCED_QUEUE_LIMITER=simple
BALANCED_QUEUE_MAX_CONCURRENT=2
BALANCED_QUEUE_PREFIX=balanced-queue
BALANCED_QUEUE_REDIS_CONNECTION=default
```

---

## Partition Keys

### Automatic Detection

The `BalancedDispatchable` trait automatically detects partition key from common property names:

```php
class MyJob implements ShouldQueue
{
    use BalancedDispatchable;

    public function __construct(
        public int $userId  // Automatically used as partition key
    ) {}
}
```

Supported auto-detected properties: `$userId`, `$user_id`, `$tenantId`, `$tenant_id`

### Explicit Partition

```php
// Set partition when dispatching
MyJob::dispatch($data)
    ->onPartition("user:{$userId}")
    ->onConnection('balanced');

// Or set in job constructor
MyJob::dispatch($data)
    ->onPartition($companyId)
    ->onConnection('balanced');
```

### Custom Partition Logic

Override `getPartitionKey()` in your job:

```php
class ProcessOrder implements ShouldQueue
{
    use BalancedDispatchable;

    public function __construct(public Order $order) {}

    public function getPartitionKey(): string
    {
        // Partition by merchant instead of user
        return "merchant:{$this->order->merchant_id}";
    }
}
```

### Global Partition Resolver

Set a default resolver in config for all jobs:

```php
// config/balanced-queue.php
'partition_resolver' => function ($job) {
    return $job->tenant_id ?? $job->user_id ?? 'default';
},
```

### Partition Resolution Priority

When determining the partition key, the following order is used:

| Priority | Method | Description |
|----------|--------|-------------|
| 1 | `onPartition()` | Explicitly set when dispatching |
| 2 | `getPartitionKey()` | Custom method defined in your job class |
| 3 | `partition_resolver` | Global resolver from config |
| 4 | Auto-detection | Properties: `userId`, `user_id`, `tenantId`, `tenant_id` |
| 5 | `'default'` | Fallback partition |

The first non-null value wins. This allows you to:
- Override everything with `onPartition()` at dispatch time
- Define custom logic per job with `getPartitionKey()`
- Set a global default with `partition_resolver` in config
- Rely on automatic detection for simple cases

---

## Advanced Usage

### Programmatic Metrics

```php
use YanGusik\BalancedQueue\Support\Metrics;

$metrics = new Metrics();

// Get queue summary
$summary = $metrics->getSummary('default');
// Returns: [
//     'partitions' => 5,
//     'total_queued' => 100,
//     'total_active' => 10,
//     'partitions_stats' => [...]
// ]

// Get per-partition stats
$stats = $metrics->getQueueStats('default');
// Returns: [
//     'user:123' => ['queued' => 10, 'active' => 2, 'metrics' => [...]],
//     'user:456' => ['queued' => 5, 'active' => 1, 'metrics' => [...]],
// ]

// Clear queue programmatically
$metrics->clearQueue('default');
```

### Custom Strategy

```php
use YanGusik\BalancedQueue\Contracts\PartitionStrategy;
use Illuminate\Contracts\Redis\Connection;

class PriorityStrategy implements PartitionStrategy
{
    public function selectPartition(Connection $redis, string $queue, string $partitionsKey): ?string
    {
        // Get all partitions
        $partitions = $redis->smembers($partitionsKey);

        // Your priority logic here
        // e.g., check user subscription level, queue size, etc.

        return $selectedPartition;
    }

    public function getName(): string
    {
        return 'priority';
    }
}
```

Register in config:

```php
// config/balanced-queue.php
'strategies' => [
    'priority' => [
        'class' => App\Queue\PriorityStrategy::class,
    ],
],

'strategy' => 'priority',
```

### Custom Limiter

```php
use YanGusik\BalancedQueue\Contracts\ConcurrencyLimiter;

class PlanBasedLimiter implements ConcurrencyLimiter
{
    public function canProcess($redis, $queue, $partition): bool
    {
        $userId = str_replace('user:', '', $partition);
        $user = User::find($userId);
        $limit = $user->subscription->concurrent_limit ?? 1;

        return $this->getActiveCount($redis, $queue, $partition) < $limit;
    }

    // ... implement other interface methods
}
```

---

## Redis Structure

Understanding the Redis key structure helps with debugging:

```
{prefix}:{queue}:partitions              → SET of partition names
{prefix}:{queue}:{partition}             → LIST of job payloads
{prefix}:{queue}:{partition}:active      → HASH of currently running job IDs
{prefix}:metrics:{queue}:{partition}     → HASH of metrics (pushed, popped counts)
{prefix}:rr-state:{queue}                → STRING round-robin counter
```

Example with default prefix and queue:

```
balanced-queue:queues:default:partitions       → {"user:123", "user:456"}
balanced-queue:queues:default:user:123         → [job1_payload, job2_payload, ...]
balanced-queue:queues:default:user:123:active  → {job_uuid: timestamp, ...}
```

### Debugging with Redis CLI

```bash
# List all balanced queue keys
redis-cli keys "balanced-queue*"

# See all partitions
redis-cli smembers "balanced-queue:queues:default:partitions"

# Check pending jobs for a partition
redis-cli llen "balanced-queue:queues:default:user:123"

# Check active jobs for a partition
redis-cli hgetall "balanced-queue:queues:default:user:123:active"

# Clear stuck active jobs (if worker crashed)
redis-cli del "balanced-queue:queues:default:user:123:active"
```

---

## Troubleshooting

### Jobs not executing

1. Check that connection name matches in `queue.php` and when dispatching:
   ```php
   ->onConnection('balanced')  // Must match 'balanced' in queue.php
   ```

2. Verify worker is running with correct connection:
   ```bash
   php artisan queue:work balanced
   ```

### Jobs stuck / not completing

Check for orphaned active job entries:

```bash
# View active jobs
redis-cli hgetall "balanced-queue:queues:default:{partition}:active"

# Clear if stuck (jobs older than retry_after)
redis-cli del "balanced-queue:queues:default:{partition}:active"
```

### Horizon shows 0 pending jobs

This is expected behavior. Use `balanced-queue:table` command instead:

```bash
php artisan balanced-queue:table --watch
```

### Workers idle but jobs pending

Check if all partitions hit their concurrency limit:

```bash
php artisan balanced-queue:table
```

If all partitions show `Active = max_concurrent`, workers are waiting for slots to free up.

---

## Monitoring with Prometheus & Grafana

The package provides optional HTTP endpoints for monitoring integration.

### Enable Monitoring Endpoints

```env
BALANCED_QUEUE_PROMETHEUS_ENABLED=true
BALANCED_QUEUE_PROMETHEUS_ROUTE=/balanced-queue/metrics
BALANCED_QUEUE_PROMETHEUS_MIDDLEWARE=ip_whitelist
```

### Available Endpoints

| Endpoint | Format | Description |
|----------|--------|-------------|
| `/balanced-queue/metrics` | Prometheus | Metrics in Prometheus text format |
| `/balanced-queue/metrics/json` | JSON | Metrics for Grafana Infinity plugin |

### Security

By default, endpoints are protected with IP whitelist middleware. Configure allowed IPs in `config/balanced-queue.php`:

```php
'prometheus' => [
    'enabled' => true,
    'middleware' => 'ip_whitelist', // or 'auth.basic', or null
    'ip_whitelist' => [
        '127.0.0.1',
        '10.0.0.0/8',      // Private networks
        '172.16.0.0/12',
        '192.168.0.0/16',
    ],
],
```

### Prometheus Setup

1. Add scrape config to `prometheus.yml`:

```yaml
scrape_configs:
  - job_name: 'balanced-queue'
    scrape_interval: 15s
    static_configs:
      - targets: ['your-app.com']
    metrics_path: /balanced-queue/metrics
```

2. Available metrics:

```
balanced_queue_pending_jobs{queue="default"} 84
balanced_queue_active_jobs{queue="default"} 4
balanced_queue_processed_total{queue="default"} 1250
balanced_queue_partitions_total{queue="default"} 15
```

### Grafana with Infinity Plugin (Real-time without Prometheus)

For real-time monitoring without Prometheus server, use [Grafana Infinity datasource](https://grafana.com/grafana/plugins/yesoreyeram-infinity-datasource/):

1. Install Infinity plugin in Grafana
2. Create datasource pointing to your app
3. Use the JSON endpoint:

```
GET /balanced-queue/metrics/json

Response:
{
  "timestamp": "2024-01-15T10:30:00+00:00",
  "queues": [
    {
      "queue": "default",
      "pending": 84,
      "active": 4,
      "processed": 1250,
      "partition_count": 3,
      "partitions": [
        {"partition": "user:123", "pending": 50, "active": 2, "processed": 800},
        {"partition": "user:456", "pending": 20, "active": 1, "processed": 300},
        {"partition": "user:789", "pending": 14, "active": 1, "processed": 150}
      ]
    }
  ]
}
```

4. Configure Infinity datasource query:
   - Type: JSON
   - URL: `https://your-app.com/balanced-queue/metrics/json`
   - Parser: Backend

5. For queue summary table: Rows/Root: `queues`
6. For partition details: Rows/Root: `queues[0].partitions` (or use JSONata for all partitions)

---

## Testing

```bash
# Run package tests
composer test

# Test in your app
php artisan tinker

>>> use App\Jobs\MyJob;
>>> MyJob::dispatch($userId, $data)->onPartition($userId)->onConnection('balanced');
```

---

## Requirements

- PHP 8.1+
- Laravel 10.x, 11.x, or 12.x
- Redis with phpredis or predis
- Laravel Horizon (optional, for worker management)

## Credits

Inspired by [aloware/fair-queue](https://github.com/aloware/fair-queue) with improvements:
- Multiple partition strategies (not just random)
- Built-in concurrency limiters
- Artisan commands for monitoring
- Cleaner, extensible architecture

## License

MIT License. See [LICENSE](LICENSE) for details.
