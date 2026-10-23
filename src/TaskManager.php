<?php

namespace Karnoweb\TaskManager;

use Karnoweb\TaskManager\Approval\ApprovalService;
use Karnoweb\TaskManager\Collaboration\AttachmentService;
use Karnoweb\TaskManager\Collaboration\ChecklistService;
use Karnoweb\TaskManager\Collaboration\CollaborationService;
use Karnoweb\TaskManager\Enterprise\CustomFieldService;
use Karnoweb\TaskManager\Enterprise\ExportService;
use Karnoweb\TaskManager\Enterprise\PresetService;
use Karnoweb\TaskManager\Enterprise\QueryService;
use Karnoweb\TaskManager\Enterprise\ReportService;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Group\GroupService;
use Karnoweb\TaskManager\Group\MilestoneService;
use Karnoweb\TaskManager\Handoff\ExternalSagaService;
use Karnoweb\TaskManager\Handoff\HandoffService;
use Karnoweb\TaskManager\Handoff\LocalRouteService;
use Karnoweb\TaskManager\Integration\AutomationService;
use Karnoweb\TaskManager\Integration\EventPump;
use Karnoweb\TaskManager\Integration\WebhookService;
use Karnoweb\TaskManager\Planning\BulkService;
use Karnoweb\TaskManager\Planning\MarkerService;
use Karnoweb\TaskManager\Planning\PositionService;
use Karnoweb\TaskManager\Planning\ReportingService;
use Karnoweb\TaskManager\Planning\SprintService;
use Karnoweb\TaskManager\Planning\TemplateService;
use Karnoweb\TaskManager\Planning\TimeTrackingService;
use Karnoweb\TaskManager\Responsibility\AssignmentService;
use Karnoweb\TaskManager\Scheduling\DateService;
use Karnoweb\TaskManager\Scheduling\RecurrenceService;
use Karnoweb\TaskManager\Scheduling\ReminderService;
use Karnoweb\TaskManager\SLA\SlaService;
use Karnoweb\TaskManager\SLA\StagePolicyService;
use Karnoweb\TaskManager\TaskGraph\DependencyService;
use Karnoweb\TaskManager\TaskGraph\HierarchyService;
use Karnoweb\TaskManager\TaskGraph\TaskPlanningService;
use Karnoweb\TaskManager\UI\IntegrationFactory;
use Karnoweb\TaskManager\Workflow\CaseService;
use Karnoweb\TaskManager\Workflow\EntryRoutingService;
use Karnoweb\TaskManager\Workflow\InstanceService;
use Karnoweb\TaskManager\Workflow\PipelineService;
use Karnoweb\TaskManager\Workflow\ProjectService;
use Karnoweb\TaskManager\Workflow\WorkflowService;

final class TaskManager
{
    public function __construct(private readonly TaskService $tasks, private readonly WorkflowService $workflows, private readonly PipelineService $pipelines,
        private readonly InstanceService $instances, private readonly ProjectService $projects, private readonly CaseService $cases,
        private readonly SlaService $sla, private readonly StagePolicyService $stagePolicies,
        private readonly AssignmentService $assignments, private readonly ApprovalService $approvals,
        private readonly HandoffService $handoffs, private readonly LocalRouteService $routes,
        private readonly ExternalSagaService $sagas, private readonly GroupService $groups,
        private readonly MilestoneService $milestones, private readonly HierarchyService $hierarchy,
        private readonly DependencyService $dependencies, private readonly TaskPlanningService $planning,
        private readonly CollaborationService $collaboration, private readonly ChecklistService $checklists,
        private readonly AttachmentService $attachments, private readonly TimeTrackingService $time,
        private readonly SprintService $sprints,
        private readonly PositionService $positions,
        private readonly MarkerService $markers,
        private readonly ReportingService $reports,
        private readonly TemplateService $templates,
        private readonly BulkService $bulk,
        private readonly DateService $dates,
        private readonly RecurrenceService $recurrences,
        private readonly ReminderService $reminders,
        private readonly CustomFieldService $fields,
        private readonly QueryService $queries,
        private readonly PresetService $presets,
        private readonly AutomationService $automation,
        private readonly EventPump $events,
        private readonly WebhookService $webhooks,
        private readonly ExportService $exports,
        private readonly ReportService $enterpriseReports,
        private readonly IntegrationFactory $integrations,
        private readonly ?EntryRoutingService $entryRouting = null)
    {
    }

    public function entryRouting(): EntryRoutingService
    {
        return $this->entryRouting ?? throw new DomainError('routing_not_configured');
    }

    public function integrations(): IntegrationFactory
    {
        return $this->integrations;
    }

    public function tasks(): TaskService
    {
        return $this->tasks;
    }

    public function workflows(): WorkflowService
    {
        return $this->workflows;
    }

    public function pipelines(): PipelineService
    {
        return $this->pipelines;
    }

    public function instances(): InstanceService
    {
        return $this->instances;
    }

    public function projects(): ProjectService
    {
        return $this->projects;
    }

    public function cases(): CaseService
    {
        return $this->cases;
    }

    public function sla(): SlaService
    {
        return $this->sla;
    }

    public function stagePolicies(): StagePolicyService
    {
        return $this->stagePolicies;
    }

    public function assignments(): AssignmentService
    {
        return $this->assignments;
    }

    public function approvals(): ApprovalService
    {
        return $this->approvals;
    }

    public function handoffs(): HandoffService
    {
        return $this->handoffs;
    }

    public function routes(): LocalRouteService
    {
        return $this->routes;
    }

    public function sagas(): ExternalSagaService
    {
        return $this->sagas;
    }

    public function groups(): GroupService
    {
        return $this->groups;
    }

    public function milestones(): MilestoneService
    {
        return $this->milestones;
    }

    public function hierarchy(): HierarchyService
    {
        return $this->hierarchy;
    }

    public function dependencies(): DependencyService
    {
        return $this->dependencies;
    }

    public function planning(): TaskPlanningService
    {
        return $this->planning;
    }

    public function collaboration(): CollaborationService
    {
        return $this->collaboration;
    }

    public function checklists(): ChecklistService
    {
        return $this->checklists;
    }

    public function attachments(): AttachmentService
    {
        return $this->attachments;
    }

    public function time(): TimeTrackingService
    {
        return $this->time;
    }

    public function sprints(): SprintService
    {
        return $this->sprints;
    }

    public function positions(): PositionService
    {
        return $this->positions;
    }

    public function markers(): MarkerService
    {
        return $this->markers;
    }

    public function reports(): ReportingService
    {
        return $this->reports;
    }

    public function templates(): TemplateService
    {
        return $this->templates;
    }

    public function bulk(): BulkService
    {
        return $this->bulk;
    }

    public function dates(): DateService
    {
        return $this->dates;
    }

    public function recurrences(): RecurrenceService
    {
        return $this->recurrences;
    }

    public function reminders(): ReminderService
    {
        return $this->reminders;
    }

    public function fields(): CustomFieldService
    {
        return $this->fields;
    }

    public function queries(): QueryService
    {
        return $this->queries;
    }

    public function presets(): PresetService
    {
        return $this->presets;
    }

    public function automation(): AutomationService
    {
        return $this->automation;
    }

    public function events(): EventPump
    {
        return $this->events;
    }

    public function webhooks(): WebhookService
    {
        return $this->webhooks;
    }

    public function exports(): ExportService
    {
        return $this->exports;
    }

    public function enterpriseReports(): ReportService
    {
        return $this->enterpriseReports;
    }

    public function __call(string $method, array $arguments): mixed
    {
        if (! is_callable([$this->tasks, $method])) {
            throw new \BadMethodCallException('Unknown task method');
        }

        return $this->tasks->{$method}(...$arguments);
    }
}
