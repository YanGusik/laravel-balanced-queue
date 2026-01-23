<?php

declare(strict_types=1);

namespace YanGusik\BalancedQueue\Support;

/**
 * Exports balanced queue metrics in Prometheus format.
 */
class PrometheusExporter
{
    protected Metrics $metrics;

    public function __construct(?Metrics $metrics = null)
    {
        $this->metrics = $metrics ?? new Metrics();
    }

    /**
     * Export all metrics in Prometheus format.
     *
     * Metrics are aggregated by queue to avoid cardinality explosion
     * (partition-level metrics would grow O(N) with users).
     */
    public function export(): string
    {
        $queues = $this->metrics->getAllQueues();

        $pendingMetrics = [];
        $activeMetrics = [];
        $processedMetrics = [];
        $partitionMetrics = [];

        foreach ($queues as $queue) {
            $stats = $this->metrics->getQueueStats($queue);

            $totalPending = 0;
            $totalActive = 0;
            $totalProcessed = 0;
            $partitionCount = count($stats);

            foreach ($stats as $partitionStats) {
                $totalPending += $partitionStats['queued'];
                $totalActive += $partitionStats['active'];
                $totalProcessed += (int) ($partitionStats['metrics']['total_popped'] ?? 0);
            }

            $labels = ['queue' => $queue];

            $pendingMetrics[] = $this->formatMetric('balanced_queue_pending_jobs', $totalPending, $labels);
            $activeMetrics[] = $this->formatMetric('balanced_queue_active_jobs', $totalActive, $labels);
            $processedMetrics[] = $this->formatMetric('balanced_queue_processed_total', $totalProcessed, $labels);
            $partitionMetrics[] = $this->formatMetric('balanced_queue_partitions_total', $partitionCount, $labels);
        }

        $lines = array_merge(
            ['# HELP balanced_queue_pending_jobs Number of pending jobs'],
            ['# TYPE balanced_queue_pending_jobs gauge'],
            $pendingMetrics,
            [''],
            ['# HELP balanced_queue_active_jobs Number of currently active jobs'],
            ['# TYPE balanced_queue_active_jobs gauge'],
            $activeMetrics,
            [''],
            ['# HELP balanced_queue_processed_total Total number of processed jobs'],
            ['# TYPE balanced_queue_processed_total counter'],
            $processedMetrics,
            [''],
            ['# HELP balanced_queue_partitions_total Number of active partitions'],
            ['# TYPE balanced_queue_partitions_total gauge'],
            $partitionMetrics
        );

        return implode("\n", $lines) . "\n";
    }

    /**
     * Format a single metric line.
     *
     * @param array<string, string> $labels
     */
    protected function formatMetric(string $name, int|float $value, array $labels = []): string
    {
        if (empty($labels)) {
            return "{$name} {$value}";
        }

        $labelParts = [];
        foreach ($labels as $key => $val) {
            // Escape special characters in label values
            $escapedVal = str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], (string) $val);
            $labelParts[] = "{$key}=\"{$escapedVal}\"";
        }

        $labelStr = implode(',', $labelParts);

        return "{$name}{{$labelStr}} {$value}";
    }
}
