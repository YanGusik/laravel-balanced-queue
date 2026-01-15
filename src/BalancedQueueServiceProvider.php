<?php

declare(strict_types=1);

namespace YanGusik\BalancedQueue;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\ServiceProvider;
use YanGusik\BalancedQueue\Queue\BalancedQueueConnector;

class BalancedQueueServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/balanced-queue.php',
            'balanced-queue'
        );

        $this->app->singleton(BalancedQueueManager::class, function ($app) {
            return new BalancedQueueManager($app);
        });

        $this->app->alias(BalancedQueueManager::class, 'balanced-queue');
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/balanced-queue.php' => config_path('balanced-queue.php'),
        ], 'balanced-queue-config');

        $this->registerQueueConnector();
    }

    /**
     * Register the balanced queue connector.
     */
    protected function registerQueueConnector(): void
    {
        /** @var QueueManager $queue */
        $queue = $this->app['queue'];

        $queue->addConnector('balanced', function () {
            return new BalancedQueueConnector(
                $this->app['redis'],
                $this->app->make(BalancedQueueManager::class)
            );
        });
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
            BalancedQueueManager::class,
            'balanced-queue',
        ];
    }
}
