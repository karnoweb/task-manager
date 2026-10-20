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
        $this->app->bindIf(\Karnoweb\TaskManager\Handoff\SubjectRouteRegistry::class, fn () => new \Karnoweb\TaskManager\Handoff\SubjectRouteRegistry());
        foreach ([\Karnoweb\TaskManager\Handoff\LocalRouteService::class, \Karnoweb\TaskManager\Handoff\ExternalSagaService::class] as $routeService) {
            $this->app->bind($routeService, fn ($app) => new $routeService($app['db']->connection(), $app->make(\Karnoweb\TaskManager\Contracts\Clock::class), $app->make(\Karnoweb\TaskManager\Contracts\AuthorizationProvider::class), $app->make(\Karnoweb\TaskManager\Workflow\InstanceService::class), $app->make(\Karnoweb\TaskManager\Workflow\CaseService::class), $app->make(\Karnoweb\TaskManager\Handoff\SubjectRouteRegistry::class)));
        }
        $this->app->bind(TaskService::class, fn ($app) => new TaskService($app['db']->connection(), $app->make(\Karnoweb\TaskManager\Contracts\Clock::class), $app->make(\Karnoweb\TaskManager\Contracts\AuthorizationProvider::class), $app->make(\Karnoweb\TaskManager\Contracts\StageLifecycle::class)));
        $this->app->bindIf(SubjectRegistry::class, fn ($app) => new SubjectRegistry(['task' => new \Karnoweb\TaskManager\Workflow\TaskSubjectHandler($app->make(TaskService::class))]));
        $this->app->bindIf(\Karnoweb\TaskManager\SLA\CalendarRegistry::class, fn () => new \Karnoweb\TaskManager\SLA\CalendarRegistry());
        $this->app->bind(\Karnoweb\TaskManager\SLA\SlaEngine::class, fn ($app) => new \Karnoweb\TaskManager\SLA\SlaEngine($app['db']->connection(), $app->make(\Karnoweb\TaskManager\Contracts\Clock::class), $app->make(\Karnoweb\TaskManager\SLA\CalendarRegistry::class)));
        $this->app->bindIf(\Karnoweb\TaskManager\Contracts\ResponsibilityResolver::class, \Karnoweb\TaskManager\Responsibility\ContextResponsibilityResolver::class);
        $this->app->bind(\Karnoweb\TaskManager\Responsibility\AssignmentEngine::class, fn ($app) => new \Karnoweb\TaskManager\Responsibility\AssignmentEngine($app['db']->connection(), $app->make(\Karnoweb\TaskManager\Contracts\Clock::class), $app->make(\Karnoweb\TaskManager\Contracts\ResponsibilityResolver::class)));
        $this->app->bind(\Karnoweb\TaskManager\Approval\ApprovalEngine::class, fn ($app) => new \Karnoweb\TaskManager\Approval\ApprovalEngine($app['db']->connection(), $app->make(\Karnoweb\TaskManager\Contracts\Clock::class), $app->make(\Karnoweb\TaskManager\Contracts\ResponsibilityResolver::class)));
        $this->app->bindIf(\Karnoweb\TaskManager\Contracts\StageLifecycle::class, fn ($app) => new StageLifecycleChain([new \Karnoweb\TaskManager\Handoff\HandoffLifecycle(), $app->make(\Karnoweb\TaskManager\Approval\ApprovalEngine::class), $app->make(\Karnoweb\TaskManager\Responsibility\AssignmentEngine::class), $app->make(\Karnoweb\TaskManager\SLA\SlaEngine::class)]));
        $this->app->bind(\Karnoweb\TaskManager\Handoff\HandoffService::class, fn ($app) => new \Karnoweb\TaskManager\Handoff\HandoffService($app['db']->connection(), $app->make(\Karnoweb\TaskManager\Contracts\Clock::class), $app->make(\Karnoweb\TaskManager\Contracts\AuthorizationProvider::class), $app->make(\Karnoweb\TaskManager\Workflow\InstanceService::class), $app->make(\Karnoweb\TaskManager\Workflow\WorkflowService::class), $app->make(\Karnoweb\TaskManager\Workflow\CaseService::class), $app->make(\Karnoweb\TaskManager\SLA\SlaService::class), $app->make(\Karnoweb\TaskManager\Responsibility\AssignmentEngine::class)));
        $this->app->bind(\Karnoweb\TaskManager\Approval\ApprovalService::class, fn ($app) => new \Karnoweb\TaskManager\Approval\ApprovalService($app['db']->connection(), $app->make(\Karnoweb\TaskManager\Contracts\Clock::class), $app->make(\Karnoweb\TaskManager\Contracts\AuthorizationProvider::class), $app->make(\Karnoweb\TaskManager\Approval\ApprovalEngine::class), $app->make(\Karnoweb\TaskManager\Workflow\InstanceService::class), $app->make(\Karnoweb\TaskManager\Workflow\PipelineService::class)));
        $this->app->bind(\Karnoweb\TaskManager\Responsibility\AssignmentService::class, fn ($app) => new \Karnoweb\TaskManager\Responsibility\AssignmentService($app['db']->connection(), $app->make(\Karnoweb\TaskManager\Contracts\Clock::class), $app->make(\Karnoweb\TaskManager\Contracts\AuthorizationProvider::class), $app->make(\Karnoweb\TaskManager\Responsibility\AssignmentEngine::class), $app->make(\Karnoweb\TaskManager\Workflow\InstanceService::class), $app->make(\Karnoweb\TaskManager\Workflow\PipelineService::class)));
        $this->app->bind(\Karnoweb\TaskManager\SLA\StagePolicyService::class, fn ($app) => new \Karnoweb\TaskManager\SLA\StagePolicyService($app['db']->connection(), $app->make(\Karnoweb\TaskManager\Contracts\Clock::class), $app->make(\Karnoweb\TaskManager\Contracts\AuthorizationProvider::class), $app->make(\Karnoweb\TaskManager\SLA\CalendarRegistry::class), $app->make(\Karnoweb\TaskManager\Workflow\PipelineService::class)));
        $this->app->bind(\Karnoweb\TaskManager\SLA\SlaService::class, fn ($app) => new \Karnoweb\TaskManager\SLA\SlaService($app['db']->connection(), $app->make(\Karnoweb\TaskManager\Contracts\Clock::class), $app->make(\Karnoweb\TaskManager\Contracts\AuthorizationProvider::class), $app->make(\Karnoweb\TaskManager\SLA\SlaEngine::class), $app->make(\Karnoweb\TaskManager\Workflow\InstanceService::class)));
        $this->app->bind(TaskResourceService::class, fn ($app) => new TaskResourceService($app['db']->connection(), $app->make(\Karnoweb\TaskManager\Contracts\Clock::class), $app->make(TaskService::class)));
        $this->app->bind(\Karnoweb\TaskManager\Workflow\WorkflowService::class, fn ($app) => new \Karnoweb\TaskManager\Workflow\WorkflowService($app['db']->connection(), $app->make(\Karnoweb\TaskManager\Contracts\Clock::class), $app->make(\Karnoweb\TaskManager\Contracts\AuthorizationProvider::class)));
        $this->app->bind(\Karnoweb\TaskManager\Workflow\PipelineService::class, fn ($app) => new \Karnoweb\TaskManager\Workflow\PipelineService($app['db']->connection(), $app->make(\Karnoweb\TaskManager\Contracts\Clock::class), $app->make(\Karnoweb\TaskManager\Contracts\AuthorizationProvider::class), $app->make(\Karnoweb\TaskManager\Workflow\WorkflowService::class)));
        $this->app->bind(\Karnoweb\TaskManager\Workflow\InstanceService::class, fn ($app) => new \Karnoweb\TaskManager\Workflow\InstanceService($app['db']->connection(), $app->make(\Karnoweb\TaskManager\Contracts\Clock::class), $app->make(\Karnoweb\TaskManager\Contracts\AuthorizationProvider::class), $app->make(SubjectRegistry::class), $app->make(\Karnoweb\TaskManager\Workflow\WorkflowService::class), $app->make(\Karnoweb\TaskManager\Workflow\PipelineService::class), lifecycle: $app->make(\Karnoweb\TaskManager\Contracts\StageLifecycle::class)));
        $this->app->bind(\Karnoweb\TaskManager\Workflow\ProjectService::class, fn ($app) => new \Karnoweb\TaskManager\Workflow\ProjectService($app['db']->connection(), $app->make(\Karnoweb\TaskManager\Contracts\Clock::class), $app->make(\Karnoweb\TaskManager\Contracts\AuthorizationProvider::class), $app->make(\Karnoweb\TaskManager\Workflow\WorkflowService::class), $app->make(TaskService::class), $app->make(\Karnoweb\TaskManager\Contracts\StageLifecycle::class)));
        $this->app->bind(\Karnoweb\TaskManager\Workflow\CaseService::class, fn ($app) => new \Karnoweb\TaskManager\Workflow\CaseService($app['db']->connection(), $app->make(\Karnoweb\TaskManager\Contracts\Clock::class), $app->make(\Karnoweb\TaskManager\Contracts\AuthorizationProvider::class), $app->make(\Karnoweb\TaskManager\Workflow\InstanceService::class)));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->publishes([__DIR__.'/../config/task-manager.php' => config_path('task-manager.php')], 'task-manager-config');
    }
}
