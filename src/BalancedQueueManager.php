<?php

declare(strict_types=1);

namespace YanGusik\BalancedQueue;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Manager;
use InvalidArgumentException;
use YanGusik\BalancedQueue\Contracts\ConcurrencyLimiter;
use YanGusik\BalancedQueue\Contracts\PartitionStrategy;
use YanGusik\BalancedQueue\Limiters\AdaptiveLimiter;
use YanGusik\BalancedQueue\Limiters\NullLimiter;
use YanGusik\BalancedQueue\Limiters\SimpleGroupLimiter;
use YanGusik\BalancedQueue\Strategies\RandomStrategy;
use YanGusik\BalancedQueue\Strategies\RoundRobinStrategy;
use YanGusik\BalancedQueue\Strategies\SmartFairStrategy;

/**
 * Balanced Queue Manager.
 *
 * Manages partition strategies and concurrency limiters.
 * Follows Laravel's Manager pattern for extensibility.
 */
class BalancedQueueManager extends Manager
{
    protected ?PartitionStrategy $strategy = null;
    protected ?ConcurrencyLimiter $limiter = null;
    protected ?Closure $partitionResolver = null;

    public function __construct(Container $container)
    {
        parent::__construct($container);
    }

    /**
     * Get the default driver name.
     */
    public function getDefaultDriver(): string
    {
        return $this->config->get('balanced-queue.strategy', 'random');
    }

    /**
     * Get the current partition strategy.
     */
    public function getStrategy(): PartitionStrategy
    {
        if ($this->strategy === null) {
            $this->strategy = $this->createStrategy(
                $this->config->get('balanced-queue.strategy', 'random')
            );
        }

        return $this->strategy;
    }

    /**
     * Get the current concurrency limiter.
     */
    public function getLimiter(): ConcurrencyLimiter
    {
        if ($this->limiter === null) {
            $this->limiter = $this->createLimiter(
                $this->config->get('balanced-queue.limiter', 'simple')
            );
        }

        return $this->limiter;
    }

    /**
     * Set a custom partition strategy.
     */
    public function setStrategy(PartitionStrategy $strategy): self
    {
        $this->strategy = $strategy;

        return $this;
    }

    /**
     * Set a custom concurrency limiter.
     */
    public function setLimiter(ConcurrencyLimiter $limiter): self
    {
        $this->limiter = $limiter;

        return $this;
    }

    /**
     * Get the partition resolver closure.
     */
    public function getPartitionResolver(): ?Closure
    {
        if ($this->partitionResolver === null) {
            $resolver = $this->config->get('balanced-queue.partition_resolver');
            if ($resolver instanceof Closure) {
                $this->partitionResolver = $resolver;
            }
        }

        return $this->partitionResolver;
    }

    /**
     * Set the partition resolver closure.
     */
    public function setPartitionResolver(Closure $resolver): self
    {
        $this->partitionResolver = $resolver;

        return $this;
    }

    /**
     * Get the Redis key prefix.
     */
    public function getPrefix(): string
    {
        return $this->config->get('balanced-queue.redis.prefix', 'balanced-queue');
    }

    /**
     * Create a partition strategy instance.
     */
    public function createStrategy(string $name): PartitionStrategy
    {
        $config = $this->config->get("balanced-queue.strategies.{$name}", []);

        return match ($name) {
            'random' => $this->createRandomStrategy($config),
            'round-robin' => $this->createRoundRobinStrategy($config),
            'smart' => $this->createSmartFairStrategy($config),
            default => $this->createCustomStrategy($name, $config),
        };
    }

    /**
     * Create a concurrency limiter instance.
     */
    public function createLimiter(string $name): ConcurrencyLimiter
    {
        $config = $this->config->get("balanced-queue.limiters.{$name}", []);

        return match ($name) {
            'null' => $this->createNullLimiter($config),
            'simple' => $this->createSimpleLimiter($config),
            'adaptive' => $this->createAdaptiveLimiter($config),
            default => $this->createCustomLimiter($name, $config),
        };
    }

    /**
     * Create the random strategy driver.
     */
    protected function createRandomStrategy(array $config): RandomStrategy
    {
        return new RandomStrategy();
    }

    /**
     * Create the round-robin strategy driver.
     */
    protected function createRoundRobinStrategy(array $config): RoundRobinStrategy
    {
        return new RoundRobinStrategy(
            $config['state_key'] ?? 'balanced-queue:rr-state'
        );
    }

    /**
     * Create the smart fair strategy driver.
     */
    protected function createSmartFairStrategy(array $config): SmartFairStrategy
    {
        return new SmartFairStrategy(
            $config['weight_wait_time'] ?? 0.6,
            $config['weight_queue_size'] ?? 0.4,
            $config['boost_small_queues'] ?? true,
            $config['small_queue_threshold'] ?? 5,
            $config['boost_multiplier'] ?? 1.5,
            $config['metrics_key_prefix'] ?? 'balanced-queue:metrics'
        );
    }

    /**
     * Create the null limiter driver.
     */
    protected function createNullLimiter(array $config): NullLimiter
    {
        return new NullLimiter();
    }

    /**
     * Create the simple group limiter driver.
     */
    protected function createSimpleLimiter(array $config): SimpleGroupLimiter
    {
        return new SimpleGroupLimiter(
            $config['max_concurrent'] ?? 2,
            $config['lock_ttl'] ?? 3600,
            $config['active_key_prefix'] ?? 'balanced-queue:active'
        );
    }

    /**
     * Create the adaptive limiter driver.
     */
    protected function createAdaptiveLimiter(array $config): AdaptiveLimiter
    {
        return new AdaptiveLimiter(
            $config['base_limit'] ?? 2,
            $config['max_limit'] ?? 5,
            $config['lock_ttl'] ?? 3600,
            $config['utilization_threshold'] ?? 0.7,
            $config['active_key_prefix'] ?? 'balanced-queue:active',
            $config['metrics_key_prefix'] ?? 'balanced-queue:metrics'
        );
    }

    /**
     * Create a custom strategy from config.
     */
    protected function createCustomStrategy(string $name, array $config): PartitionStrategy
    {
        $class = $config['class'] ?? null;

        if (!$class || !class_exists($class)) {
            throw new InvalidArgumentException("Strategy [{$name}] is not defined or class does not exist.");
        }

        $instance = $this->container->make($class, $config);

        if (!$instance instanceof PartitionStrategy) {
            throw new InvalidArgumentException("Strategy [{$name}] must implement PartitionStrategy interface.");
        }

        return $instance;
    }

    /**
     * Create a custom limiter from config.
     */
    protected function createCustomLimiter(string $name, array $config): ConcurrencyLimiter
    {
        $class = $config['class'] ?? null;

        if (!$class || !class_exists($class)) {
            throw new InvalidArgumentException("Limiter [{$name}] is not defined or class does not exist.");
        }

        $instance = $this->container->make($class, $config);

        if (!$instance instanceof ConcurrencyLimiter) {
            throw new InvalidArgumentException("Limiter [{$name}] must implement ConcurrencyLimiter interface.");
        }

        return $instance;
    }

    /**
     * Create the driver (required by Manager, delegates to strategy).
     */
    public function createDriver($driver)
    {
        return $this->createStrategy($driver);
    }
}
