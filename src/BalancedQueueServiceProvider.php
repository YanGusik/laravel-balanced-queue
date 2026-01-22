<?php

declare(strict_types=1);

namespace YanGusik\BalancedQueue;

use Illuminate\Queue\QueueManager;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use YanGusik\BalancedQueue\Console\BalancedQueueClearCommand;
use YanGusik\BalancedQueue\Console\BalancedQueueTableCommand;
use YanGusik\BalancedQueue\Http\Controllers\PrometheusController;
use YanGusik\BalancedQueue\Http\Middleware\IpWhitelist;
use YanGusik\BalancedQueue\Queue\BalancedQueueConnector;
use YanGusik\BalancedQueue\Support\PrometheusExporter;

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

        $this->app->singleton(PrometheusExporter::class, function () {
            return new PrometheusExporter();
        });
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
        $this->registerPrometheusRoute();
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

    /**
     * Register the Prometheus metrics route.
     */
    protected function registerPrometheusRoute(): void
    {
        if (! config('balanced-queue.prometheus.enabled', false)) {
            return;
        }

        /** @var Router $router */
        $router = $this->app['router'];

        // Register the middleware alias
        $router->aliasMiddleware('balanced-queue.ip_whitelist', IpWhitelist::class);

        $route = config('balanced-queue.prometheus.route', '/balanced-queue/metrics');
        $middleware = $this->getPrometheusMiddleware();

        $router->get($route, [PrometheusController::class, 'metrics'])
            ->middleware($middleware)
            ->name('balanced-queue.prometheus.metrics');
    }

    /**
     * Get the middleware to apply to the Prometheus route.
     *
     * @return array<string>
     */
    protected function getPrometheusMiddleware(): array
    {
        $middleware = config('balanced-queue.prometheus.middleware');

        if ($middleware === null || $middleware === '') {
            return [];
        }

        if ($middleware === 'ip_whitelist') {
            return ['balanced-queue.ip_whitelist'];
        }

        // Support for auth.basic and other Laravel middleware
        return [$middleware];
    }
}
