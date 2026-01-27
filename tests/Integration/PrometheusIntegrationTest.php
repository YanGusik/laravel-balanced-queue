<?php

declare(strict_types=1);

namespace YanGusik\BalancedQueue\Tests\Integration;

use YanGusik\BalancedQueue\Support\PrometheusExporter;

class PrometheusIntegrationTest extends IntegrationTestCase
{
    public function test_export_empty_metrics(): void
    {
        $exporter = new PrometheusExporter();
        $output = $exporter->export();

        $this->assertStringContainsString('# HELP balanced_queue_pending_jobs', $output);
        $this->assertStringContainsString('# TYPE balanced_queue_pending_jobs gauge', $output);
        $this->assertStringContainsString('# HELP balanced_queue_active_jobs', $output);
        $this->assertStringContainsString('# TYPE balanced_queue_active_jobs gauge', $output);
    }

    public function test_export_with_jobs(): void
    {
        // Push some jobs
        $queue = app('queue')->connection('balanced');
        $queue->push(new TestJob(['user_id' => 1]), '', 'prometheus-test');
        $queue->push(new TestJob(['user_id' => 1]), '', 'prometheus-test');
        $queue->push(new TestJob(['user_id' => 2]), '', 'prometheus-test');

        $exporter = new PrometheusExporter();
        $output = $exporter->export();

        // Should have pending jobs metric
        $this->assertStringContainsString('balanced_queue_pending_jobs{queue="prometheus-test"} 3', $output);

        // Should have partitions metric (2 partitions: user:1 and user:2)
        $this->assertStringContainsString('balanced_queue_partitions_total{queue="prometheus-test"} 2', $output);
    }

    public function test_export_with_active_jobs(): void
    {
        // Push two jobs - one to pop, one to keep pending
        $queue = app('queue')->connection('balanced');
        $queue->push(new TestJob(['user_id' => 1]), '', 'active-test');
        $queue->push(new TestJob(['user_id' => 1]), '', 'active-test');

        // Pop one job (makes it active)
        $job = $queue->pop('active-test');
        $this->assertNotNull($job);

        $exporter = new PrometheusExporter();
        $output = $exporter->export();

        // Should have metrics for this queue
        $this->assertStringContainsString('queue="active-test"', $output);
        // Should have 1 active job
        $this->assertStringContainsString('balanced_queue_active_jobs{queue="active-test"} 1', $output);
        // Should have 1 pending job (we pushed 2, popped 1)
        $this->assertStringContainsString('balanced_queue_pending_jobs{queue="active-test"} 1', $output);
    }

    public function test_export_multiple_queues(): void
    {
        $queue = app('queue')->connection('balanced');
        $queue->push(new TestJob(['user_id' => 1]), '', 'queue-alpha');
        $queue->push(new TestJob(['user_id' => 2]), '', 'queue-beta');

        $exporter = new PrometheusExporter();
        $output = $exporter->export();

        $this->assertStringContainsString('queue="queue-alpha"', $output);
        $this->assertStringContainsString('queue="queue-beta"', $output);
    }

    public function test_prometheus_endpoint_disabled_by_default(): void
    {
        $response = $this->get('/balanced-queue/metrics');

        $response->assertNotFound();
    }

    public function test_prometheus_endpoint_when_enabled(): void
    {
        // Enable prometheus endpoint
        $this->app['config']->set('balanced-queue.prometheus.enabled', true);
        $this->app['config']->set('balanced-queue.prometheus.middleware', null);

        // Re-register routes
        $this->app->make(\YanGusik\BalancedQueue\BalancedQueueServiceProvider::class, ['app' => $this->app])
            ->boot();

        $response = $this->get('/balanced-queue/metrics');

        $response->assertOk();
        $this->assertStringStartsWith('text/plain', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('balanced_queue_pending_jobs', $response->getContent());
    }
}
