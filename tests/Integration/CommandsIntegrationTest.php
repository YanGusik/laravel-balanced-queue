<?php

declare(strict_types=1);

namespace YanGusik\BalancedQueue\Tests\Integration;

class CommandsIntegrationTest extends IntegrationTestCase
{
    public function test_table_command_shows_empty_queue(): void
    {
        $this->artisan('balanced-queue:table', ['queue' => 'empty-queue'])
            ->expectsOutput("No active partitions in queue 'empty-queue'")
            ->assertSuccessful();
    }

    public function test_table_command_shows_partitions(): void
    {
        // Push some jobs first
        $queue = app('queue')->connection('balanced');
        $queue->push(new TestJob(['user_id' => 1]), '', 'test-queue');
        $queue->push(new TestJob(['user_id' => 2]), '', 'test-queue');

        $this->artisan('balanced-queue:table', ['queue' => 'test-queue'])
            ->expectsOutputToContain('user:1')
            ->expectsOutputToContain('user:2')
            ->assertSuccessful();
    }

    public function test_table_command_all_flag(): void
    {
        // Push jobs to different queues
        $queue = app('queue')->connection('balanced');
        $queue->push(new TestJob(['user_id' => 1]), '', 'queue-a');
        $queue->push(new TestJob(['user_id' => 2]), '', 'queue-b');

        $this->artisan('balanced-queue:table', ['--all' => true])
            ->assertSuccessful();
    }

    public function test_table_command_all_flag_empty(): void
    {
        $this->artisan('balanced-queue:table', ['--all' => true])
            ->expectsOutput('No active queues found')
            ->assertSuccessful();
    }

    public function test_clear_command_empty_queue(): void
    {
        $this->artisan('balanced-queue:clear', ['queue' => 'empty-queue', '--force' => true])
            ->expectsOutput('Queue is already empty.')
            ->assertSuccessful();
    }

    public function test_clear_command_clears_all_partitions(): void
    {
        // Push some jobs
        $queue = app('queue')->connection('balanced');
        $queue->push(new TestJob(['user_id' => 1]), '', 'clear-test');
        $queue->push(new TestJob(['user_id' => 2]), '', 'clear-test');

        $this->artisan('balanced-queue:clear', ['queue' => 'clear-test', '--force' => true])
            ->expectsOutputToContain('Cleared')
            ->assertSuccessful();

        // Verify queue is empty
        $this->artisan('balanced-queue:clear', ['queue' => 'clear-test', '--force' => true])
            ->expectsOutput('Queue is already empty.')
            ->assertSuccessful();
    }

    public function test_clear_command_clears_specific_partition(): void
    {
        // Push jobs to different partitions
        $queue = app('queue')->connection('balanced');
        $queue->push(new TestJob(['user_id' => 100]), '', 'partition-test');
        $queue->push(new TestJob(['user_id' => 200]), '', 'partition-test');

        // Clear only one partition
        $this->artisan('balanced-queue:clear', [
            'queue' => 'partition-test',
            '--partition' => 'user:100',
            '--force' => true,
        ])
            ->expectsOutputToContain("Cleared partition 'user:100'")
            ->assertSuccessful();

        // Other partition should still exist
        $this->artisan('balanced-queue:table', ['queue' => 'partition-test'])
            ->expectsOutputToContain('user:200')
            ->assertSuccessful();
    }

    public function test_clear_command_empty_partition(): void
    {
        $this->artisan('balanced-queue:clear', [
            'queue' => 'some-queue',
            '--partition' => 'non-existent',
            '--force' => true,
        ])
            ->expectsOutput("Partition 'non-existent' is already empty.")
            ->assertSuccessful();
    }
}
