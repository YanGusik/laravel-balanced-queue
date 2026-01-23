<?php

declare(strict_types=1);

namespace YanGusik\BalancedQueue\Tests\Feature;

use Mockery;
use YanGusik\BalancedQueue\Support\Metrics;
use YanGusik\BalancedQueue\Support\PrometheusExporter;
use YanGusik\BalancedQueue\Tests\TestCase;

class PrometheusTest extends TestCase
{
    public function test_export_formats_metrics_correctly(): void
    {
        $metrics = Mockery::mock(Metrics::class);

        $metrics->shouldReceive('getAllQueues')
            ->once()
            ->andReturn(['default', 'emails']);

        $metrics->shouldReceive('getQueueStats')
            ->with('default')
            ->once()
            ->andReturn([
                'user:1' => [
                    'queued' => 5,
                    'active' => 2,
                    'metrics' => ['total_popped' => '100'],
                ],
                'user:2' => [
                    'queued' => 3,
                    'active' => 1,
                    'metrics' => ['total_popped' => '50'],
                ],
            ]);

        $metrics->shouldReceive('getQueueStats')
            ->with('emails')
            ->once()
            ->andReturn([
                'org:1' => [
                    'queued' => 10,
                    'active' => 0,
                    'metrics' => [],
                ],
            ]);

        $exporter = new PrometheusExporter($metrics);
        $output = $exporter->export();

        // Check metric headers
        $this->assertStringContainsString('# HELP balanced_queue_pending_jobs', $output);
        $this->assertStringContainsString('# TYPE balanced_queue_pending_jobs gauge', $output);
        $this->assertStringContainsString('# HELP balanced_queue_active_jobs', $output);
        $this->assertStringContainsString('# TYPE balanced_queue_active_jobs gauge', $output);
        $this->assertStringContainsString('# HELP balanced_queue_processed_total', $output);
        $this->assertStringContainsString('# TYPE balanced_queue_processed_total counter', $output);
        $this->assertStringContainsString('# HELP balanced_queue_partitions_total', $output);
        $this->assertStringContainsString('# TYPE balanced_queue_partitions_total gauge', $output);

        // Check aggregated metric values (by queue, not partition)
        $this->assertStringContainsString('balanced_queue_pending_jobs{queue="default"} 8', $output);  // 5+3
        $this->assertStringContainsString('balanced_queue_active_jobs{queue="default"} 3', $output);   // 2+1
        $this->assertStringContainsString('balanced_queue_processed_total{queue="default"} 150', $output); // 100+50
        $this->assertStringContainsString('balanced_queue_partitions_total{queue="default"} 2', $output);
        $this->assertStringContainsString('balanced_queue_pending_jobs{queue="emails"} 10', $output);
        $this->assertStringContainsString('balanced_queue_partitions_total{queue="emails"} 1', $output);

        // Ensure partition-level metrics are NOT included (cardinality safety)
        $this->assertStringNotContainsString('partition="user:1"', $output);
        $this->assertStringNotContainsString('partition="user:2"', $output);
    }

    public function test_export_handles_empty_queues(): void
    {
        $metrics = Mockery::mock(Metrics::class);

        $metrics->shouldReceive('getAllQueues')
            ->once()
            ->andReturn([]);

        $exporter = new PrometheusExporter($metrics);
        $output = $exporter->export();

        // Should still have headers
        $this->assertStringContainsString('# HELP balanced_queue_pending_jobs', $output);
        $this->assertStringContainsString('# TYPE balanced_queue_pending_jobs gauge', $output);
    }

    public function test_export_escapes_special_characters_in_labels(): void
    {
        $metrics = Mockery::mock(Metrics::class);

        $metrics->shouldReceive('getAllQueues')
            ->once()
            ->andReturn(['queue-"test"']);

        $metrics->shouldReceive('getQueueStats')
            ->with('queue-"test"')
            ->once()
            ->andReturn([
                'partition1' => [
                    'queued' => 1,
                    'active' => 0,
                    'metrics' => [],
                ],
            ]);

        $exporter = new PrometheusExporter($metrics);
        $output = $exporter->export();

        // Check that quotes are escaped in queue name
        $this->assertStringContainsString('queue="queue-\\"test\\""', $output);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
