<?php

declare(strict_types=1);

namespace YanGusik\BalancedQueue\Queue;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Support\Arr;
use YanGusik\BalancedQueue\BalancedQueueManager;

/**
 * Balanced Queue Connector.
 *
 * Connects the balanced queue driver to Laravel's queue system.
 */
class BalancedQueueConnector implements ConnectorInterface
{
    protected RedisFactory $redis;
    protected BalancedQueueManager $manager;

    public function __construct(RedisFactory $redis, BalancedQueueManager $manager)
    {
        $this->redis = $redis;
        $this->manager = $manager;
    }

    /**
     * Establish a queue connection.
     */
    public function connect(array $config): BalancedRedisQueue
    {
        $queue = new BalancedRedisQueue(
            $this->redis,
            $config['queue'] ?? 'default',
            $config['connection'] ?? null,
            $config['retry_after'] ?? 60,
            $config['block_for'] ?? null,
            $config['after_commit'] ?? false,
        );

        $queue->setContainer(app());
        $queue->setStrategy($this->manager->getStrategy());
        $queue->setLimiter($this->manager->getLimiter());
        $queue->setPrefix($this->manager->getPrefix());

        $resolver = $this->manager->getPartitionResolver();
        if ($resolver) {
            $queue->setPartitionResolver($resolver);
        }

        return $queue;
    }
}
