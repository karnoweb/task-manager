<?php

namespace Karnoweb\TaskManager\UI;

use Illuminate\Contracts\Container\Container;
use Karnoweb\Calendar\Contracts\EventProvider;
use Karnoweb\Crm\Models\Deal;
use Karnoweb\Crm\Models\Followup;
use Karnoweb\Crm\Models\RecurringTask;
use Karnoweb\Kanban\Contracts\DataProvider;
use Karnoweb\TaskManager\Contracts\AuthorizationProvider;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Scheduling\DateService;
use Karnoweb\TaskManager\Scheduling\RecurrenceService;
use Karnoweb\TaskManager\SubjectRegistry;
use Karnoweb\TaskManager\TaskService;
use Karnoweb\TaskManager\Workflow\InstanceService;
use Karnoweb\TaskManager\Workflow\PipelineService;
use Karnoweb\TaskManager\Workflow\ProjectService;
use Karnoweb\TaskManager\Workflow\WorkflowService;

/** Explicit opt-in; the core provider never registers UI routes, assets or CRM observers. */
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

    public function crm(): Crm\CutoverService
    {
        if (! class_exists(Deal::class) || ! $this->app->bound(Crm\HostAccess::class)) {
            throw new DomainError('crm_host_binding_required');
        }

        return new Crm\CutoverService($this->app['db']->connection(), $this->app->make(Clock::class), $this->app->make(AuthorizationProvider::class), $this->app->make(Crm\HostAccess::class), $this->app->make(TaskService::class), $this->app->make(InstanceService::class), $this->app->make(WorkflowService::class), $this->app->make(ProjectService::class), $this->app->make(PipelineService::class), $this->app->make(DateService::class));
    }

    public function guardLegacyCrm(): void
    {
        $this->crm();
        $guard = new Crm\LegacyWriteGuard($this->app->make(Crm\HostAccess::class));
        Deal::observe($guard);
        Followup::observe($guard);
        RecurringTask::observe($guard);
    }

    public function followups(): Crm\FollowupService
    {
        return new Crm\FollowupService($this->app['db']->connection(), $this->app->make(TaskService::class), $this->app->make(SubjectRegistry::class), $this->app->make(Clock::class));
    }
}
