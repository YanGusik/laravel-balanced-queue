<?php

declare(strict_types=1);

namespace YanGusik\BalancedQueue\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use YanGusik\BalancedQueue\Support\Metrics;
use YanGusik\BalancedQueue\Support\PrometheusExporter;

class PrometheusController
{
    /**
     * Export metrics in Prometheus format.
     */
    public function metrics(PrometheusExporter $exporter): Response
    {
        $content = $exporter->export();

        return response($content, 200, [
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
        ]);
    }

    /**
     * Export metrics in JSON format for Grafana Infinity datasource.
     */
    public function json(Metrics $metrics): JsonResponse
    {
        $queues = $metrics->getAllQueues();
        $result = [];

        foreach ($queues as $queue) {
            $stats = $metrics->getQueueStats($queue);

            $totalPending = 0;
            $totalActive = 0;
            $totalProcessed = 0;
            $partitions = [];

            foreach ($stats as $partitionName => $partitionStats) {
                $pending = $partitionStats['queued'];
                $active = $partitionStats['active'];
                $processed = (int) ($partitionStats['metrics']['total_popped'] ?? 0);

                $totalPending += $pending;
                $totalActive += $active;
                $totalProcessed += $processed;

                $partitions[] = [
                    'partition' => $partitionName,
                    'pending' => $pending,
                    'active' => $active,
                    'processed' => $processed,
                ];
            }

            $result[] = [
                'queue' => $queue,
                'pending' => $totalPending,
                'active' => $totalActive,
                'processed' => $totalProcessed,
                'partition_count' => count($partitions),
                'partitions' => $partitions,
            ];
        }

        return response()->json([
            'timestamp' => now()->toIso8601String(),
            'queues' => $result,
        ]);
    }
}
