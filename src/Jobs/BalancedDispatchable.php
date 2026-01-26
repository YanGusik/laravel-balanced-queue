<?php

declare(strict_types=1);

namespace YanGusik\BalancedQueue\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Trait for jobs that should use balanced queue.
 *
 * Combines standard Laravel job traits with partition support.
 *
 * Usage:
 * ```php
 * class MyJob implements ShouldQueue
 * {
 *     use BalancedDispatchable;
 *
 *     public function __construct(public int $userId) {}
 *
 *     public function getPartitionKey(): string
 *     {
 *         return (string) $this->userId;
 *     }
 * }
 * ```
 */
trait BalancedDispatchable
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * The partition key for this job.
     */
    protected ?string $partitionKey = null;

    /**
     * Set the partition key for this job.
     */
    public function onPartition(string|int $partition): static
    {
        $this->partitionKey = (string) $partition;

        return $this;
    }
}
