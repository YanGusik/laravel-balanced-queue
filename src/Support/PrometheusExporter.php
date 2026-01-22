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
     */
    public function export(): string
    {
        $queues = $this->metrics->getAllQueues();
        $lines = [];

        // Add metric type headers once
        $lines[] = '# HELP balanced_queue_pending_jobs Number of pending jobs';
        $lines[] = '# TYPE balanced_queue_pending_jobs gauge';
        $lines[] = '';

        $lines[] = '# HELP balanced_queue_active_jobs Number of currently active jobs';
        $lines[] = '# TYPE balanced_queue_active_jobs gauge';
        $lines[] = '';

        $lines[] = '# HELP balanced_queue_processed_total Total number of processed jobs';
        $lines[] = '# TYPE balanced_queue_processed_total counter';
        $lines[] = '';

        $lines[] = '# HELP balanced_queue_partitions_total Number of active partitions';
        $lines[] = '# TYPE balanced_queue_partitions_total gauge';
        $lines[] = '';

        // Collect all metrics
        $pendingMetrics = [];
        $activeMetrics = [];
        $processedMetrics = [];
        $partitionMetrics = [];

        foreach ($queues as $queue) {
            $stats = $this->metrics->getQueueStats($queue);
            $partitionCount = count($stats);

            $partitionMetrics[] = $this->formatMetric(
                'balanced_queue_partitions_total',
                $partitionCount,
                ['queue' => $queue]
            );

            foreach ($stats as $partition => $partitionStats) {
                $labels = [
                    'queue' => $queue,
                    'partition' => $partition,
                ];

                $pendingMetrics[] = $this->formatMetric(
                    'balanced_queue_pending_jobs',
                    $partitionStats['queued'],
                    $labels
                );

                $activeMetrics[] = $this->formatMetric(
                    'balanced_queue_active_jobs',
                    $partitionStats['active'],
                    $labels
                );

                $processed = (int) ($partitionStats['metrics']['total_popped'] ?? 0);
                $processedMetrics[] = $this->formatMetric(
                    'balanced_queue_processed_total',
                    $processed,
                    $labels
                );
            }
        }

        // Output metrics grouped by type
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
