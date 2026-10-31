<?php

namespace Karnoweb\TaskManager\UI;

use Illuminate\Contracts\Container\Container;
use Karnoweb\Calendar\Contracts\EventProvider;
use Karnoweb\Kanban\Contracts\DataProvider;
use Karnoweb\TaskManager\Contracts\AuthorizationProvider;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Scheduling\DateService;
use Karnoweb\TaskManager\Scheduling\RecurrenceService;
use Karnoweb\TaskManager\SubjectRegistry;
use Karnoweb\TaskManager\TaskService;
use Karnoweb\TaskManager\Workflow\InstanceService;
use Karnoweb\TaskManager\Workflow\PipelineService;
use Karnoweb\TaskManager\Workflow\ProjectService;
use Karnoweb\TaskManager\Workflow\SubjectTaskService;
use Karnoweb\TaskManager\Workflow\WorkflowService;

/** Explicit opt-in; the core provider never registers UI routes or assets and never depends on a consuming domain package. */
final readonly class IntegrationFactory
{
    public function __construct(private Container $app)
    {
    }

    public function board(Surface $surface, ContextBridge $bridge, BoundCursor $cursors, ?\Closure $handoff = null): Kanban\TaskBoard
    {
        if (! interface_exists(DataProvider::class)) {
            throw new DomainError('kanban_package_required');
        }

        return new Kanban\TaskBoard($this->app['db']->connection(), $this->app->make(TaskService::class), $this->app->make(InstanceService::class), $this->app->make(WorkflowService::class), $this->app->make(PipelineService::class), $this->app->make(SubjectRegistry::class), $this->app->make(AuthorizationProvider::class), $surface, $bridge, $cursors, $handoff);
    }

    public function calendar(Surface $surface, ContextBridge $bridge, BoundCursor $cursors): Calendar\TaskCalendar
    {
        if (! interface_exists(EventProvider::class)) {
            throw new DomainError('calendar_package_required');
        }

        return new Calendar\TaskCalendar($this->app['db']->connection(), $this->app->make(TaskService::class), $this->app->make(InstanceService::class), $this->app->make(ProjectService::class), $this->app->make(DateService::class), $this->app->make(RecurrenceService::class), $surface, $bridge, $cursors);
    }

    /** Tasks associated with any registered subject alias; domain packages register their own handlers. */
    public function followups(): SubjectTaskService
    {
        return $this->app->make(SubjectTaskService::class);
    }
}
