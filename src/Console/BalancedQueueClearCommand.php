<?php

declare(strict_types=1);

namespace YanGusik\BalancedQueue\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class BalancedQueueClearCommand extends Command
{
    protected $signature = 'balanced-queue:clear
                            {queue=default : The queue name}
                            {--partition= : Clear only specific partition}
                            {--force : Skip confirmation}';

    protected $description = 'Clear balanced queue jobs';

    protected string $prefix;

    public function handle(): int
    {
        $this->prefix = config('balanced-queue.redis.prefix', 'balanced-queue');
        $queueName = $this->argument('queue');
        $partition = $this->option('partition');
        $force = $this->option('force');

        $redis = Redis::connection(config('balanced-queue.redis.connection'));

        if ($partition) {
            return $this->clearPartition($redis, $queueName, $partition, $force);
        }

        return $this->clearAll($redis, $queueName, $force);
    }

    protected function clearPartition($redis, string $queueName, string $partition, bool $force): int
    {
        $queueListKey = $this->getPartitionQueueKey($queueName, $partition);
        $activeKey = $this->getActiveKey($queueName, $partition);
        $metricsKey = $this->getMetricsKey($queueName, $partition);
        $partitionsKey = $this->getPartitionsKey($queueName);

        $pending = (int) $redis->llen($queueListKey);
        $active = (int) $redis->hlen($activeKey);

        if ($pending === 0 && $active === 0) {
            $this->info("Partition '{$partition}' is already empty.");
            return Command::SUCCESS;
        }

        $this->warn("Partition: {$partition}");
        $this->line("  Pending jobs: {$pending}");
        $this->line("  Active jobs: {$active}");
        $this->newLine();

        if (!$force && !$this->confirm("Are you sure you want to clear this partition?")) {
            $this->info('Operation cancelled.');
            return Command::SUCCESS;
        }

        $redis->del($queueListKey);
        $redis->del($activeKey);
        $redis->del($metricsKey);
        $redis->srem($partitionsKey, $partition);

        $this->info("✓ Cleared partition '{$partition}': {$pending} pending, {$active} active jobs removed.");

        return Command::SUCCESS;
    }

    protected function clearAll($redis, string $queueName, bool $force): int
    {
        $partitionsKey = $this->getPartitionsKey($queueName);
        $partitions = $redis->smembers($partitionsKey);

        if (empty($partitions)) {
            $this->info('Queue is already empty.');
            return Command::SUCCESS;
        }

        $totalPending = 0;
        $totalActive = 0;
        $partitionStats = [];

        foreach ($partitions as $partition) {
            $queueListKey = $this->getPartitionQueueKey($queueName, $partition);
            $activeKey = $this->getActiveKey($queueName, $partition);

            $pending = (int) $redis->llen($queueListKey);
            $active = (int) $redis->hlen($activeKey);

            $totalPending += $pending;
            $totalActive += $active;

            $partitionStats[] = [
                'partition' => $partition,
                'pending' => $pending,
                'active' => $active,
            ];
        }

        $this->warn("Queue: {$queueName}");
        $this->table(
            ['Partition', 'Pending', 'Active'],
            array_map(fn($s) => [$s['partition'], $s['pending'], $s['active']], $partitionStats)
        );

        $this->line("Total: {$totalPending} pending, {$totalActive} active jobs in " . count($partitions) . " partitions");
        $this->newLine();

        if (!$force && !$this->confirm("Are you sure you want to clear ALL partitions?")) {
            $this->info('Operation cancelled.');
            return Command::SUCCESS;
        }

        // Clear all
        foreach ($partitions as $partition) {
            $redis->del($this->getPartitionQueueKey($queueName, $partition));
            $redis->del($this->getActiveKey($queueName, $partition));
            $redis->del($this->getDelayedKey($queueName, $partition));
            $redis->del($this->getMetricsKey($queueName, $partition));
        }

        $redis->del($partitionsKey);

        // Clear round-robin state (uses queueName without 'queues:' prefix)
        $rrStateKey = "{$this->prefix}:rr-state:{$queueName}";
        $redis->del($rrStateKey);

        $this->info("✓ Cleared {$totalPending} pending and {$totalActive} active jobs from " . count($partitions) . " partitions.");

        return Command::SUCCESS;
    }

    // =========================================================================
    // Redis Key Helpers (mirrors BalancedRedisQueue)
    // =========================================================================

    protected function getPartitionsKey(string $queueName): string
    {
        return "{$this->prefix}:queues:{$queueName}:partitions";
    }

    protected function getPartitionQueueKey(string $queueName, string $partition): string
    {
        return "{$this->prefix}:queues:{$queueName}:{$partition}";
    }

    protected function getActiveKey(string $queueName, string $partition): string
    {
        return "{$this->prefix}:queues:{$queueName}:{$partition}:active";
    }

    protected function getMetricsKey(string $queueName, string $partition): string
    {
        return "{$this->prefix}:metrics:{$queueName}:{$partition}";
    }

    protected function getDelayedKey(string $queueName, string $partition): string
    {
        return "{$this->prefix}:queues:{$queueName}:{$partition}:delayed";
    }
}
