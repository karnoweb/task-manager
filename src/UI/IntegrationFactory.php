<?php

namespace Karnoweb\TaskManager\UI;

use Illuminate\Contracts\Container\Container;
use Karnoweb\TaskManager\Exceptions\DomainError;

/** Explicit opt-in; the core provider never registers UI routes, assets or CRM observers. */
final readonly class IntegrationFactory
{
    public function __construct(private Container $app) {}
    public function board(Surface $surface, ContextBridge $bridge, BoundCursor $cursors, ?\Closure $handoff=null): Kanban\TaskBoard
    {
        if (!interface_exists(\Karnoweb\Kanban\Contracts\DataProvider::class)) { throw new DomainError('kanban_package_required'); }
        return new Kanban\TaskBoard($this->app['db']->connection(),$this->app->make(\Karnoweb\TaskManager\TaskService::class),$this->app->make(\Karnoweb\TaskManager\Workflow\InstanceService::class),$this->app->make(\Karnoweb\TaskManager\Workflow\WorkflowService::class),$this->app->make(\Karnoweb\TaskManager\Workflow\PipelineService::class),$this->app->make(\Karnoweb\TaskManager\SubjectRegistry::class),$this->app->make(\Karnoweb\TaskManager\Contracts\AuthorizationProvider::class),$surface,$bridge,$cursors,$handoff);
    }
    public function calendar(Surface $surface, ContextBridge $bridge, BoundCursor $cursors): Calendar\TaskCalendar
    {
        if (!interface_exists(\Karnoweb\Calendar\Contracts\EventProvider::class)) { throw new DomainError('calendar_package_required'); }
        return new Calendar\TaskCalendar($this->app['db']->connection(),$this->app->make(\Karnoweb\TaskManager\TaskService::class),$this->app->make(\Karnoweb\TaskManager\Workflow\InstanceService::class),$this->app->make(\Karnoweb\TaskManager\Workflow\ProjectService::class),$this->app->make(\Karnoweb\TaskManager\Scheduling\DateService::class),$this->app->make(\Karnoweb\TaskManager\Scheduling\RecurrenceService::class),$surface,$bridge,$cursors);
    }
    public function crm(): Crm\CutoverService
    {
        if (!class_exists(\Karnoweb\Crm\Models\Deal::class) || !$this->app->bound(Crm\HostAccess::class)) { throw new DomainError('crm_host_binding_required'); }
        return new Crm\CutoverService($this->app['db']->connection(),$this->app->make(\Karnoweb\TaskManager\Contracts\Clock::class),$this->app->make(\Karnoweb\TaskManager\Contracts\AuthorizationProvider::class),$this->app->make(Crm\HostAccess::class),$this->app->make(\Karnoweb\TaskManager\TaskService::class),$this->app->make(\Karnoweb\TaskManager\Workflow\InstanceService::class),$this->app->make(\Karnoweb\TaskManager\Workflow\WorkflowService::class),$this->app->make(\Karnoweb\TaskManager\Workflow\ProjectService::class),$this->app->make(\Karnoweb\TaskManager\Workflow\PipelineService::class),$this->app->make(\Karnoweb\TaskManager\Scheduling\DateService::class));
    }
    public function guardLegacyCrm(): void
    {
        $this->crm();$guard=new Crm\LegacyWriteGuard($this->app->make(Crm\HostAccess::class));
        \Karnoweb\Crm\Models\Deal::observe($guard);\Karnoweb\Crm\Models\Followup::observe($guard);\Karnoweb\Crm\Models\RecurringTask::observe($guard);
    }
    public function followups(): Crm\FollowupService
    {
        return new Crm\FollowupService($this->app['db']->connection(),$this->app->make(\Karnoweb\TaskManager\TaskService::class),$this->app->make(\Karnoweb\TaskManager\SubjectRegistry::class),$this->app->make(\Karnoweb\TaskManager\Contracts\Clock::class));
    }
}
