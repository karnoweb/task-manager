<?php

namespace Karnoweb\TaskManager;

use Illuminate\Support\ServiceProvider;

final class TaskManagerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/task-manager.php', 'task-manager');
        $this->app->bindIf(\Karnoweb\TaskManager\Contracts\Clock::class, SystemClock::class);
        $this->app->bind(TaskService::class, fn ($app) => new TaskService($app['db']->connection(), $app->make(\Karnoweb\TaskManager\Contracts\Clock::class)));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->publishes([__DIR__.'/../config/task-manager.php' => config_path('task-manager.php')], 'task-manager-config');
    }
}
