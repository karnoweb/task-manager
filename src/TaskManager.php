<?php

namespace Karnoweb\TaskManager;

use Karnoweb\TaskManager\Workflow\CaseService;
use Karnoweb\TaskManager\Workflow\InstanceService;
use Karnoweb\TaskManager\Workflow\PipelineService;
use Karnoweb\TaskManager\Workflow\ProjectService;
use Karnoweb\TaskManager\Workflow\WorkflowService;

final class TaskManager
{
    public function __construct(private readonly TaskService $tasks, private readonly WorkflowService $workflows, private readonly PipelineService $pipelines,
        private readonly InstanceService $instances, private readonly ProjectService $projects, private readonly CaseService $cases,
        private readonly \Karnoweb\TaskManager\SLA\SlaService $sla, private readonly \Karnoweb\TaskManager\SLA\StagePolicyService $stagePolicies,
        private readonly \Karnoweb\TaskManager\Responsibility\AssignmentService $assignments, private readonly \Karnoweb\TaskManager\Approval\ApprovalService $approvals,
        private readonly \Karnoweb\TaskManager\Handoff\HandoffService $handoffs, private readonly \Karnoweb\TaskManager\Handoff\LocalRouteService $routes,
        private readonly \Karnoweb\TaskManager\Handoff\ExternalSagaService $sagas, private readonly \Karnoweb\TaskManager\Group\GroupService $groups,
        private readonly \Karnoweb\TaskManager\Group\MilestoneService $milestones, private readonly \Karnoweb\TaskManager\TaskGraph\HierarchyService $hierarchy,
        private readonly \Karnoweb\TaskManager\TaskGraph\DependencyService $dependencies, private readonly \Karnoweb\TaskManager\TaskGraph\TaskPlanningService $planning,
        private readonly \Karnoweb\TaskManager\Collaboration\CollaborationService $collaboration, private readonly \Karnoweb\TaskManager\Collaboration\ChecklistService $checklists,
        private readonly \Karnoweb\TaskManager\Collaboration\AttachmentService $attachments, private readonly \Karnoweb\TaskManager\Planning\TimeTrackingService $time,
        private readonly \Karnoweb\TaskManager\Planning\SprintService $sprints,
        private readonly \Karnoweb\TaskManager\Planning\PositionService $positions,
        private readonly \Karnoweb\TaskManager\Planning\MarkerService $markers,
        private readonly \Karnoweb\TaskManager\Planning\ReportingService $reports,
        private readonly \Karnoweb\TaskManager\Planning\TemplateService $templates,
        private readonly \Karnoweb\TaskManager\Planning\BulkService $bulk) {}
    public function tasks(): TaskService { return $this->tasks; }
    public function workflows(): WorkflowService { return $this->workflows; }
    public function pipelines(): PipelineService { return $this->pipelines; }
    public function instances(): InstanceService { return $this->instances; }
    public function projects(): ProjectService { return $this->projects; }
    public function cases(): CaseService { return $this->cases; }
    public function sla(): \Karnoweb\TaskManager\SLA\SlaService { return $this->sla; }
    public function stagePolicies(): \Karnoweb\TaskManager\SLA\StagePolicyService { return $this->stagePolicies; }
    public function assignments(): \Karnoweb\TaskManager\Responsibility\AssignmentService { return $this->assignments; }
    public function approvals(): \Karnoweb\TaskManager\Approval\ApprovalService { return $this->approvals; }
    public function handoffs(): \Karnoweb\TaskManager\Handoff\HandoffService { return $this->handoffs; }
    public function routes(): \Karnoweb\TaskManager\Handoff\LocalRouteService { return $this->routes; }
    public function sagas(): \Karnoweb\TaskManager\Handoff\ExternalSagaService { return $this->sagas; }
    public function groups(): \Karnoweb\TaskManager\Group\GroupService { return $this->groups; }
    public function milestones(): \Karnoweb\TaskManager\Group\MilestoneService { return $this->milestones; }
    public function hierarchy(): \Karnoweb\TaskManager\TaskGraph\HierarchyService { return $this->hierarchy; }
    public function dependencies(): \Karnoweb\TaskManager\TaskGraph\DependencyService { return $this->dependencies; }
    public function planning(): \Karnoweb\TaskManager\TaskGraph\TaskPlanningService { return $this->planning; }
    public function collaboration(): \Karnoweb\TaskManager\Collaboration\CollaborationService { return $this->collaboration; }
    public function checklists(): \Karnoweb\TaskManager\Collaboration\ChecklistService { return $this->checklists; }
    public function attachments(): \Karnoweb\TaskManager\Collaboration\AttachmentService { return $this->attachments; }
    public function time(): \Karnoweb\TaskManager\Planning\TimeTrackingService { return $this->time; }
    public function sprints(): \Karnoweb\TaskManager\Planning\SprintService { return $this->sprints; }
    public function positions(): \Karnoweb\TaskManager\Planning\PositionService { return $this->positions; }
    public function markers(): \Karnoweb\TaskManager\Planning\MarkerService { return $this->markers; }
    public function reports(): \Karnoweb\TaskManager\Planning\ReportingService { return $this->reports; }
    public function templates(): \Karnoweb\TaskManager\Planning\TemplateService { return $this->templates; }
    public function bulk(): \Karnoweb\TaskManager\Planning\BulkService { return $this->bulk; }
    public function __call(string $method, array $arguments): mixed
    {
        if (!is_callable([$this->tasks, $method])) { throw new \BadMethodCallException('Unknown task method'); }
        return $this->tasks->{$method}(...$arguments);
    }
}
