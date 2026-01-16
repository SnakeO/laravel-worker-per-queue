<?php

namespace Snakeo\WorkerPerQueue;

use Illuminate\Support\ServiceProvider;
use Snakeo\WorkerPerQueue\Commands\QueueWorkAllCommand;

class WorkerPerQueueServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/worker-per-queue.php',
            'worker-per-queue'
        );
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                QueueWorkAllCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/worker-per-queue.php' => config_path('worker-per-queue.php'),
            ], 'worker-per-queue-config');
        }
    }
}
