<?php

namespace Karnoweb\TaskManager\Integration;

use Illuminate\Database\Connection;
use Karnoweb\TaskManager\Approval\ApprovalService;
use Karnoweb\TaskManager\CommandExecutor;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\AuthorizationProvider;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\Enterprise\ExportService;
use Karnoweb\TaskManager\EventRecorder;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Handoff\HandoffService;
use Karnoweb\TaskManager\Responsibility\AssignmentService;
use Karnoweb\TaskManager\Schema\Tables;
use Karnoweb\TaskManager\SLA\SlaService;
use Karnoweb\TaskManager\TaskGraph\HierarchyService;
use Karnoweb\TaskManager\TaskService;
use Karnoweb\TaskManager\Workflow\Definition;
use Karnoweb\TaskManager\Workflow\InstanceService;
use Karnoweb\TaskManager\Workflow\ProjectService;
use Karnoweb\TaskManager\Workflow\WorkflowService;

final class AutomationActions
{
    public const ALLOWED = ['assign', 'notify', 'add_label', 'create_subtask', 'request_approval', 'transition', 'handoff', 'mark_dead_candidate', 'export', 'report_event'];

    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly TaskService $tasks, private readonly InstanceService $instances, private readonly AssignmentService $assignments, private readonly ApprovalService $approvals, private readonly HandoffService $handoffs, private readonly HierarchyService $hierarchy, private readonly ProjectService $projects, private readonly WorkflowService $workflows, private readonly ExportService $exports, private readonly SlaService $sla)
    {
        Tables::assertConnection($db);
    }

    public function validate(array $action): void
    {
        if (array_diff(array_keys($action), ['type', 'args']) || ! in_array($action['type'] ?? null, self::ALLOWED, true) || ! is_array($action['args'] ?? [])) {
            throw new DomainError('automation_action_not_allowed');
        }
        $allowed = match ($action['type']) {
            'assign' => ['primary', 'secondary'],'notify' => ['channel'],'add_label' => ['label'],'create_subtask' => ['title'],'request_approval' => ['policy'],'transition' => ['edge', 'payload'],'handoff' => ['pipeline_key', 'revision_id', 'slot', 'policy'],'mark_dead_candidate' => ['visit_id'],'export' => ['definition'],default => []
        };
        if (array_diff(array_keys($action['args'] ?? []), $allowed) || strlen(json_encode($action, JSON_THROW_ON_ERROR)) > 32000) {
            throw new DomainError('automation_action_invalid');
        }
        $args = $action['args'] ?? [];
        if ($action['type'] === 'add_label') {
            Definition::key($args['label'] ?? null);
        }if ($action['type'] === 'create_subtask' && (! is_string($args['title'] ?? null) || trim($args['title']) === '' || mb_strlen($args['title']) > 255)) {
            throw new DomainError('automation_action_invalid');
        }
        if ($action['type'] === 'notify' && ! in_array($args['channel'] ?? 'in_app', ['in_app', 'email', 'sms', 'push'], true)) {
            throw new DomainError('automation_action_invalid');
        }
        if ($action['type'] === 'transition' && (! is_string($args['edge'] ?? null) || ! is_array($args['payload'] ?? []))) {
            throw new DomainError('automation_action_invalid');
        }
        if ($action['type'] === 'assign' && ((isset($args['primary']) && ! is_string($args['primary'])) || ! is_array($args['secondary'] ?? []))) {
            throw new DomainError('automation_action_invalid');
        }
        if ($action['type'] === 'request_approval' && ! is_array($args['policy'] ?? null)) {
            throw new DomainError('automation_action_invalid');
        }
        if ($action['type'] === 'handoff' && (! is_string($args['pipeline_key'] ?? null) || ! is_int($args['revision_id'] ?? null) || ! is_string($args['slot'] ?? null) || ! is_array($args['policy'] ?? null))) {
            throw new DomainError('automation_action_invalid');
        }
        if ($action['type'] === 'mark_dead_candidate' && ! is_int($args['visit_id'] ?? null)) {
            throw new DomainError('automation_action_invalid');
        }
        if ($action['type'] === 'export' && ! is_array($args['definition'] ?? null)) {
            throw new DomainError('automation_action_invalid');
        }
    }

    private function instance(ExecutionContext $context, array $source): array
    {
        $key = $source['instance_key'];
        if ($key === null && $source['task_key'] !== null) {
            $task = $this->tasks->get($context, $source['task_key']);
            $key = $this->db->table(Tables::name('workflow_instances'))->where('task_id', $task['id'])->where('slot_key', 'main')->orderByDesc('id')->value('instance_key');
        }if ($key === null) {
            throw new DomainError('automation_instance_required');
        }

        return $this->instances->get($context, $key);
    }

    public function execute(ExecutionContext $context, array $event, array $source, array $action, string $operation): array
    {
        $context->validateTenant();
        $this->validate($action);
        $args = $action['args'] ?? [];
        $type = $action['type'];
        if ($type === 'notify' || $type === 'report_event') {
            (new EventRecorder($this->db, $this->clock))->append($context, $event['aggregate_type'], $event['aggregate_key'], $type === 'notify' ? 'automation_notify' : 'automation_report', $operation, ['channel' => $args['channel'] ?? 'in_app'], $event['task_id']);

            return ['state' => 'recorded'];
        }
        if ($type === 'export') {
            return $this->exports->enqueue($context, $args['definition'], $operation);
        }
        if ($type === 'create_subtask') {
            if ($source['task_key'] === null) {
                throw new DomainError('automation_task_required');
            }$parent = $this->tasks->get($context, $source['task_key']);
            $task = $this->tasks->create($context, $args['title'], 'create:'.$operation);
            if ($parent['project_id'] !== null) {
                $projectKey = $this->db->table(Tables::name('projects'))->where('id', $parent['project_id'])->value('project_key');
                $project = $this->projects->get($context, $projectKey, 'edit');
                $revision = $this->workflows->revision($context, $project['workflow_revision_id']);
                $preview = $this->projects->previewTaskMove($context, $task['public_key'], $projectKey, 'task', [$task['state'] => $revision['definition']['initial']]);
                $task = $this->projects->moveTask($context, $preview, 'project:'.$operation);
            }

            return $this->hierarchy->reparent($context, $task['public_key'], $task['version'], $parent['public_key'], $parent['version'], 'parent:'.$operation);
        }
        if ($type === 'add_label') {
            if ($source['task_key'] === null) {
                throw new DomainError('automation_task_required');
            }$context->require('task.edit');
            $this->authorization->authorize($context, 'task.edit');

            return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'task_label_add', 'task' => $source['task_key'], 'label' => $args['label']], function () use ($context, $source, $args, $operation) {
                $task = $this->tasks->authorizedQuery($context, 'edit')->where('public_key', $source['task_key'])->whereNull('deleted_at')->whereNull('archived_at')->lockForUpdate()->first();
                if (! $task) {
                    throw new DomainError('not_found');
                }$this->db->table(Tables::name('task_label_keys'))->insertOrIgnore(['task_id' => $task->id, 'label_key' => $args['label']]);
                $this->db->table(Tables::name('tasks'))->where('id', $task->id)->increment('version');
                (new EventRecorder($this->db, $this->clock))->append($context, 'task', $task->public_key, 'label_added', $operation, ['label' => $args['label']], $task->id);

                return ['task_key' => $task->public_key];
            }, fn ($result) => $this->tasks->get($context, $result['task_key']));
        }
        $instance = $this->instance($context, $source);

        return match ($type) {
            'assign' => $this->assignments->assign($context, $instance['instance_key'], $instance['version'], $operation, $args['primary'] ?? null, $args['secondary'] ?? []),
            'request_approval' => $this->approvals->begin($context, $instance['instance_key'], $instance['version'], $operation, $args['policy']),
            'transition' => $this->instances->transition($context, $instance['instance_key'], $instance['version'], $operation, $args['edge'], $args['payload'] ?? []),
            'handoff' => $this->handoffs->delegate($context, $instance['instance_key'], $instance['version'], $args['pipeline_key'], $args['revision_id'], $args['slot'], $args['policy'], $operation),
            'mark_dead_candidate' => $this->sla->markDeadCandidate($context, $instance['instance_key'], $args['visit_id']),
            default => throw new DomainError('automation_action_not_allowed')
        };
    }
}
