<?php

declare(strict_types=1);

namespace YanGusik\BalancedQueue;

use Illuminate\Queue\QueueManager;
use Illuminate\Support\ServiceProvider;
use YanGusik\BalancedQueue\Console\BalancedQueueClearCommand;
use YanGusik\BalancedQueue\Console\BalancedQueueTableCommand;
use YanGusik\BalancedQueue\Queue\BalancedQueueConnector;

class BalancedQueueServiceProvider extends ServiceProvider
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
        $this->registerCommands();
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
     * Register artisan commands.
     */
    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                BalancedQueueTableCommand::class,
                BalancedQueueClearCommand::class,
            ]);
        }
    }
}
