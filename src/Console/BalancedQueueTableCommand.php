<?php

declare(strict_types=1);

namespace YanGusik\BalancedQueue\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use YanGusik\BalancedQueue\Support\Metrics;

class BalancedQueueTableCommand extends Command
{
    protected $signature = 'balanced-queue:table
                            {queue=default : The queue name}
                            {--all : Show all active queues}
                            {--watch : Watch mode with auto-refresh}
                            {--interval=2 : Refresh interval in seconds}';

    protected $description = 'Display balanced queue statistics';

    protected string $prefix;

    public function handle(): int
    {
        $this->prefix = config('balanced-queue.redis.prefix', 'balanced-queue');
        $showAll = $this->option('all');
        $watch = $this->option('watch');
        $interval = (int) $this->option('interval');

        if ($showAll) {
            $queues = $this->getAllQueues();
            if (empty($queues)) {
                $this->warn('No active queues found');
                return Command::SUCCESS;
            }
        } else {
            $queues = [$this->argument('queue')];
        }

        if ($watch) {
            $this->watchQueues($queues, $interval);
        } else {
            $this->displayQueues($queues);
        }

        return Command::SUCCESS;
    }

    /**
     * Get all active queues.
     *
     * @return array<string>
     */
    protected function getAllQueues(): array
    {
        $metrics = new Metrics(Redis::connection(config('balanced-queue.redis.connection')), $this->prefix);

        return $metrics->getAllQueues();
    }

    /**
     * Display tables for multiple queues.
     *
     * @param array<string> $queues
     */
    protected function displayQueues(array $queues): void
    {
        foreach ($queues as $index => $queueName) {
            if ($index > 0) {
                $this->newLine();
            }
            if (count($queues) > 1) {
                $this->info("Queue: {$queueName}");
                $this->line(str_repeat('-', 40));
            }
            $this->displayTable($queueName);
        }
    }

    /**
     * Watch multiple queues.
     *
     * @param array<string> $queues
     */
    protected function watchQueues(array $queues, int $interval): void
    {
        $queueList = implode(', ', $queues);
        $this->info("Watching queues: {$queueList} (Ctrl+C to exit)");
        $this->newLine();

        while (true) {
            // Clear screen
            $this->output->write("\033[2J\033[H");

            foreach ($queues as $index => $queueName) {
                if ($index > 0) {
                    $this->newLine();
                }
                $this->displayHeader($queueName);
                $this->displayTable($queueName);
            }
            $this->displayFooter();

            sleep($interval);
        }
    }

    protected function displayHeader(string $queueName): void
    {
        $this->info("╔══════════════════════════════════════════════════════════════╗");
        $this->info("║            BALANCED QUEUE MONITOR - {$queueName}");
        $this->info("╚══════════════════════════════════════════════════════════════╝");
        $this->newLine();
    }

    protected function displayTable(string $queueName): void
    {
        $redis = Redis::connection(config('balanced-queue.redis.connection'));

        // Get all partitions
        $partitionsKey = $this->getPartitionsKey($queueName);
        $partitions = $redis->smembers($partitionsKey);

        if (empty($partitions)) {
            $this->warn("No active partitions in queue '{$queueName}'");
            return;
        }

        $totalPending = 0;
        $totalActive = 0;
        $rows = [];

        foreach ($partitions as $partition) {
            $queueListKey = $this->getPartitionQueueKey($queueName, $partition);
            $activeKey = $this->getActiveKey($queueName, $partition);
            $metricsKey = $this->getMetricsKey($queueName, $partition);

            $pending = (int) $redis->llen($queueListKey);
            $active = (int) $redis->hlen($activeKey);
            $totalPushed = (int) ($redis->hget($metricsKey, 'total_pushed') ?? 0);
            $totalPopped = (int) ($redis->hget($metricsKey, 'total_popped') ?? 0);

            $totalPending += $pending;
            $totalActive += $active;

            // Status indicator
            $status = $active > 0 ? '<fg=green>●</>' : '<fg=gray>○</>';

            $rows[] = [
                'partition' => $partition,
                'status' => $status,
                'pending' => $pending,
                'active' => $active,
                'processed' => $totalPopped,
            ];
        }

        // Sort by pending desc
        usort($rows, fn($a, $b) => $b['pending'] <=> $a['pending']);

        $this->table(
            ['Partition', 'Status', 'Pending', 'Active', 'Processed'],
            array_map(fn($row) => [
                $row['partition'],
                $row['status'],
                $this->formatNumber($row['pending']),
                $this->formatActive($row['active']),
                $this->formatNumber($row['processed']),
            ], $rows)
        );

        $this->newLine();
        $this->line("  <fg=cyan>Total:</> {$totalPending} pending, {$totalActive} active, " . count($partitions) . " partitions");
    }

    protected function displayFooter(): void
    {
        $this->newLine();
        $maxConcurrent = config('balanced-queue.limiters.simple.max_concurrent', 2);
        $strategy = config('balanced-queue.strategy', 'round-robin');

        $this->line("  <fg=gray>Strategy:</> {$strategy} | <fg=gray>Max concurrent:</> {$maxConcurrent}");
        $this->line("  <fg=gray>Updated:</> " . now()->format('H:i:s'));
    }

    protected function formatNumber(int $num): string
    {
        if ($num === 0) {
            return '<fg=gray>0</>';
        }

        if ($num > 100) {
            return "<fg=red>{$num}</>";
        }

        if ($num > 10) {
            return "<fg=yellow>{$num}</>";
        }

        return "<fg=white>{$num}</>";
    }

    protected function formatActive(int $num): string
    {
        if ($num === 0) {
            return '<fg=gray>0</>';
        }

        return "<fg=green>{$num}</>";
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
}
