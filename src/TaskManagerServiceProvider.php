<?php

namespace Karnoweb\TaskManager;

use Illuminate\Support\ServiceProvider;

final class TaskManagerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/task-manager.php', 'task-manager');
        $this->app->bindIf(\Karnoweb\TaskManager\Contracts\Clock::class, SystemClock::class);
        $this->app->bindIf(\Karnoweb\TaskManager\Contracts\AuthorizationProvider::class, ContextAuthorization::class);
        $this->app->bind(TaskService::class, fn ($app) => new TaskService($app['db']->connection(), $app->make(\Karnoweb\TaskManager\Contracts\Clock::class), $app->make(\Karnoweb\TaskManager\Contracts\AuthorizationProvider::class)));
        $this->app->bindIf(SubjectRegistry::class, fn ($app) => new SubjectRegistry(['task' => new \Karnoweb\TaskManager\Workflow\TaskSubjectHandler($app->make(TaskService::class))]));
        $this->app->bind(TaskResourceService::class, fn ($app) => new TaskResourceService($app['db']->connection(), $app->make(\Karnoweb\TaskManager\Contracts\Clock::class), $app->make(TaskService::class)));
        $this->app->bind(\Karnoweb\TaskManager\Workflow\WorkflowService::class, fn ($app) => new \Karnoweb\TaskManager\Workflow\WorkflowService($app['db']->connection(), $app->make(\Karnoweb\TaskManager\Contracts\Clock::class), $app->make(\Karnoweb\TaskManager\Contracts\AuthorizationProvider::class)));
        $this->app->bind(\Karnoweb\TaskManager\Workflow\PipelineService::class, fn ($app) => new \Karnoweb\TaskManager\Workflow\PipelineService($app['db']->connection(), $app->make(\Karnoweb\TaskManager\Contracts\Clock::class), $app->make(\Karnoweb\TaskManager\Contracts\AuthorizationProvider::class), $app->make(\Karnoweb\TaskManager\Workflow\WorkflowService::class)));
        $this->app->bind(\Karnoweb\TaskManager\Workflow\InstanceService::class, fn ($app) => new \Karnoweb\TaskManager\Workflow\InstanceService($app['db']->connection(), $app->make(\Karnoweb\TaskManager\Contracts\Clock::class), $app->make(\Karnoweb\TaskManager\Contracts\AuthorizationProvider::class), $app->make(SubjectRegistry::class), $app->make(\Karnoweb\TaskManager\Workflow\WorkflowService::class), $app->make(\Karnoweb\TaskManager\Workflow\PipelineService::class)));
        $this->app->bind(\Karnoweb\TaskManager\Workflow\ProjectService::class, fn ($app) => new \Karnoweb\TaskManager\Workflow\ProjectService($app['db']->connection(), $app->make(\Karnoweb\TaskManager\Contracts\Clock::class), $app->make(\Karnoweb\TaskManager\Contracts\AuthorizationProvider::class), $app->make(\Karnoweb\TaskManager\Workflow\WorkflowService::class), $app->make(TaskService::class)));
        $this->app->bind(\Karnoweb\TaskManager\Workflow\CaseService::class, fn ($app) => new \Karnoweb\TaskManager\Workflow\CaseService($app['db']->connection(), $app->make(\Karnoweb\TaskManager\Contracts\Clock::class), $app->make(\Karnoweb\TaskManager\Contracts\AuthorizationProvider::class), $app->make(\Karnoweb\TaskManager\Workflow\InstanceService::class)));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->publishes([__DIR__.'/../config/task-manager.php' => config_path('task-manager.php')], 'task-manager-config');
    }
}
