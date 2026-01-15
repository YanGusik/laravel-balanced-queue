<?php

declare(strict_types=1);

namespace YanGusik\BalancedQueue\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class BalancedQueueTableCommand extends Command
{
    protected $signature = 'balanced-queue:table
                            {queue=default : The queue name}
                            {--watch : Watch mode with auto-refresh}
                            {--interval=2 : Refresh interval in seconds}';

    protected $description = 'Display balanced queue statistics';

    protected string $prefix;

    public function handle(): int
    {
        $this->prefix = config('balanced-queue.redis.prefix', 'balanced-queue');
        $queue = $this->argument('queue');
        $watch = $this->option('watch');
        $interval = (int) $this->option('interval');

        if ($watch) {
            $this->watch($queue, $interval);
        } else {
            $this->displayTable($queue);
        }

        return Command::SUCCESS;
    }

    protected function watch(string $queue, int $interval): void
    {
        $this->info("Watching balanced queue '{$queue}' (Ctrl+C to exit)");
        $this->newLine();

        while (true) {
            // Clear screen
            $this->output->write("\033[2J\033[H");

            $this->displayHeader($queue);
            $this->displayTable($queue);
            $this->displayFooter();

            sleep($interval);
        }
    }

    protected function displayHeader(string $queue): void
    {
        $this->info("╔══════════════════════════════════════════════════════════════╗");
        $this->info("║            BALANCED QUEUE MONITOR - {$queue}");
        $this->info("╚══════════════════════════════════════════════════════════════╝");
        $this->newLine();
    }

    protected function displayTable(string $queue): void
    {
        $redis = Redis::connection();
        $queueKey = "queues:{$queue}";

        // Get all partitions
        $partitionsKey = "{$this->prefix}:{$queueKey}:partitions";
        $partitions = $redis->smembers($partitionsKey);

        if (empty($partitions)) {
            $this->warn("No active partitions in queue '{$queue}'");
            return;
        }

        $totalPending = 0;
        $totalActive = 0;
        $rows = [];

        foreach ($partitions as $partition) {
            $queueListKey = "{$this->prefix}:{$queueKey}:{$partition}";
            $activeKey = "{$this->prefix}:{$queueKey}:{$partition}:active";
            $metricsKey = "{$this->prefix}:metrics:{$queueKey}:{$partition}";

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
}
