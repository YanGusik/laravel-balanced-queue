<?php

declare(strict_types=1);

namespace YanGusik\BalancedQueue\Tests\Integration;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use YanGusik\BalancedQueue\Queue\BalancedRedisQueue;
use YanGusik\BalancedQueue\Support\Metrics;

class BalancedQueueIntegrationTest extends IntegrationTestCase
{
    private BalancedRedisQueue $queue;
    private Metrics $metrics;

    protected function setUp(): void
    {
        parent::setUp();

        $this->queue = app('queue')->connection('balanced');
        $this->metrics = new Metrics();
    }

    public function test_push_job_creates_partition(): void
    {
        $job = new TestJob(['user_id' => 123]);

        $this->queue->push($job, '', 'default');

        $partitions = $this->metrics->getPartitions('default');

        $this->assertContains('user:123', $partitions);
    }

    public function test_push_multiple_jobs_to_same_partition(): void
    {
        $job1 = new TestJob(['user_id' => 123]);
        $job2 = new TestJob(['user_id' => 123]);

        $this->queue->push($job1, '', 'default');
        $this->queue->push($job2, '', 'default');

        $size = $this->metrics->getQueueSize('default', 'user:123');

        $this->assertEquals(2, $size);
    }

    public function test_push_jobs_to_different_partitions(): void
    {
        $job1 = new TestJob(['user_id' => 123]);
        $job2 = new TestJob(['user_id' => 456]);

        $this->queue->push($job1, '', 'default');
        $this->queue->push($job2, '', 'default');

        $partitions = $this->metrics->getPartitions('default');

        $this->assertCount(2, $partitions);
        $this->assertContains('user:123', $partitions);
        $this->assertContains('user:456', $partitions);
    }

    public function test_pop_returns_job_from_partition(): void
    {
        $job = new TestJob(['user_id' => 123, 'data' => 'test']);

        $this->queue->push($job, '', 'default');

        $poppedJob = $this->queue->pop('default');

        $this->assertNotNull($poppedJob);
    }

    public function test_pop_empty_queue_returns_null(): void
    {
        $result = $this->queue->pop('empty-queue');

        $this->assertNull($result);
    }

    public function test_metrics_get_all_queues(): void
    {
        $job1 = new TestJob(['user_id' => 1]);
        $job2 = new TestJob(['user_id' => 2]);

        $this->queue->push($job1, '', 'emails');
        $this->queue->push($job2, '', 'notifications');

        $queues = $this->metrics->getAllQueues();

        $this->assertContains('emails', $queues);
        $this->assertContains('notifications', $queues);
    }

    public function test_numeric_partition_keys_work_correctly(): void
    {
        // This tests the fix from PR #8
        $job = new TestJobWithNumericPartition(12345);

        $this->queue->push($job, '', 'default');

        $partitions = $this->metrics->getPartitions('default');

        $this->assertContains('12345', $partitions);
        $this->assertIsString($partitions[0]);
    }

    public function test_concurrent_limit_respected(): void
    {
        // Default max_concurrent is 2, push 3 jobs to same partition
        $job1 = new TestJob(['user_id' => 888]);
        $job2 = new TestJob(['user_id' => 888]);
        $job3 = new TestJob(['user_id' => 888]);

        $this->queue->push($job1, '', 'default');
        $this->queue->push($job2, '', 'default');
        $this->queue->push($job3, '', 'default');

        // Pop first two jobs (should succeed, max_concurrent=2)
        $poppedJob1 = $this->queue->pop('default');
        $this->assertNotNull($poppedJob1, 'First job should be available');

        $poppedJob2 = $this->queue->pop('default');
        $this->assertNotNull($poppedJob2, 'Second job should be available');

        // Pop third job (should be null - concurrent limit reached)
        $poppedJob3 = $this->queue->pop('default');
        $this->assertNull($poppedJob3, 'Third job should be blocked by concurrent limit');

        // Delete first job (releases one slot)
        $poppedJob1->delete();

        // Now third job should be available
        $poppedJob4 = $this->queue->pop('default');
        $this->assertNotNull($poppedJob4, 'Third job should be available after releasing slot');
    }
}
