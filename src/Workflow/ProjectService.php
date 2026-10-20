<?php

namespace Karnoweb\TaskManager\Workflow;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use Karnoweb\TaskManager\CommandExecutor;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\AuthorizationProvider;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\EventRecorder;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\TaskService;

final class ProjectService
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly WorkflowService $workflows, private readonly TaskService $tasks, private readonly \Karnoweb\TaskManager\Contracts\StageLifecycle $lifecycle) {}
    public function get(ExecutionContext $context, string $key, string $capability = 'view', bool $lock = false): array
    {
        if (!Str::isUuid($key)) { throw new DomainError('not_found'); }
        $context->require('project.'.$capability); $this->authorization->authorize($context, 'project.'.$capability);
        $query = $this->db->table('tm_projects')->where('scope_key', $context->scope)->where('project_key', $key)
            ->whereExists(function (Builder $q) use ($context, $capability) {
                $q->selectRaw('1')->from('tm_project_members')->whereColumn('project_id', 'tm_projects.id')->where('actor_ref', $context->actor)
                    ->whereIn('role', $capability === 'view' ? ['manager', 'member', 'viewer'] : ($capability === 'manage' ? ['manager'] : ['manager', 'member']));
            });
        $this->authorization->constrain($query, $context, 'project', $capability);
        if ($lock) { $query->lockForUpdate(); }
        $project = $query->first(); if (!$project) { throw new DomainError('not_found'); }
        return (array) $project;
    }
    public function create(ExecutionContext $context, string $key, string $name, int $revisionId, string $operation): array
    {
        $context->require('project.manage'); $this->authorization->authorize($context, 'project.manage');
        if (!preg_match('/^[A-Z][A-Z0-9]{0,19}$/D', $key) || trim($name) === '' || mb_strlen($name) > 255) { throw new DomainError('project_invalid'); }
        $this->workflows->revision($context, $revisionId);
        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'project_create', 'key' => $key, 'name' => $name, 'revision' => $revisionId], function () use ($context, $key, $name, $revisionId, $operation) {
            $public = (string) Str::uuid();
            $id = $this->db->table('tm_projects')->insertGetId(['project_key' => $public, 'scope_key' => $context->scope, 'key' => $key, 'name' => $name, 'owner_ref' => $context->actor, 'workflow_revision_id' => $revisionId]);
            $this->db->table('tm_project_members')->insert(['project_id' => $id, 'actor_ref' => $context->actor, 'role' => 'manager']);
            $this->event($context, $public, 'project_created', $operation, []);
            return $this->get($context, $public);
        }, fn ($r) => $this->get($context, $r['project_key']));
    }
    public function change(ExecutionContext $context, string $key, int $version, string $operation, string $action, array $data): array
    {
        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'project_'.$action, 'key' => $key, 'version' => $version, 'data' => $data], function () use ($context, $key, $version, $operation, $action, $data) {
            $project = $this->get($context, $key, 'manage', true);
            if ($project['version'] !== $version) { throw new DomainError('version_conflict'); }
            $changes = ['version' => $version + 1];
            if ($action === 'member') {
                $actor = $data['actor'] ?? ''; $role = $data['role'] ?? null;
                if (!is_string($actor) || trim($actor) === '' || strlen($actor) > 120 || !in_array($role, ['manager', 'member', 'viewer', null], true)) { throw new DomainError('member_invalid'); }
                if ($actor === $project['owner_ref'] && $role !== 'manager') { throw new DomainError('owner_membership_required'); }
                $this->db->table('tm_project_members')->where('project_id', $project['id'])->where('actor_ref', $actor)->delete();
                if ($role !== null) { $this->db->table('tm_project_members')->insert(['project_id' => $project['id'], 'actor_ref' => $actor, 'role' => $role]); }
            } elseif ($action === 'archive' || $action === 'restore') { $changes['status'] = $action === 'archive' ? 'archived' : 'active'; }
            elseif ($action === 'type_workflow') {
                Definition::key($data['type'] ?? null); $revision = $this->workflows->revision($context, $data['revision'] ?? 0);
                $this->db->table('tm_project_type_workflows')->updateOrInsert(['project_id' => $project['id'], 'type_key' => $data['type']], ['workflow_revision_id' => $revision['id']]);
            } else { throw new DomainError('action_invalid'); }
            $this->db->table('tm_projects')->where('id', $project['id'])->update($changes);
            $this->event($context, $key, 'project_'.$action, $operation, ['version' => $version + 1]);
            return $this->get($context, $key);
        }, fn ($r) => $this->get($context, $r['project_key'], 'manage'));
    }

    public function previewTaskMove(ExecutionContext $context, string $taskKey, string $projectKey, string $type, array $stateMap): array
    {
        $task = $this->tasks->get($context, $taskKey); $project = $this->get($context, $projectKey, 'edit');
        if ($project['status'] !== 'active') { throw new DomainError('project_archived'); }
        Definition::key($type);
        $revisionId = $this->db->table('tm_project_type_workflows')->where('project_id', $project['id'])->where('type_key', $type)->value('workflow_revision_id') ?? $project['workflow_revision_id'];
        $definition = $this->workflows->revision($context, $revisionId)['definition'];
        $state = $stateMap[$task['state']] ?? null;
        if (!is_string($state) || !isset($definition['states'][$state])) { throw new DomainError('state_mapping_required'); }
        return ['task_key' => $taskKey, 'task_version' => $task['version'], 'project_key' => $projectKey, 'project_version' => $project['version'], 'revision_id' => $revisionId, 'state' => $state, 'type' => $type];
    }

    public function moveTask(ExecutionContext $context, array $preview, string $operation, ?string $parentKey = null): array
    {
        $context->require('project.edit');
        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'task_move_project', 'preview' => $preview, 'parent' => $parentKey], function () use ($context, $preview, $operation, $parentKey) {
            $project = $this->get($context, $preview['project_key'], 'manage', true);
            $task = $this->tasks->authorizedQuery($context, 'edit')->where('public_key', $preview['task_key'])->lockForUpdate()->first();
            if (!$task) { throw new DomainError('not_found'); }
            if ($task->version !== $preview['task_version'] || $project['version'] !== $preview['project_version']) { throw new DomainError('preview_stale'); }
            if ($project['status'] !== 'active') { throw new DomainError('project_archived'); }
            $effectiveRevision = $this->db->table('tm_project_type_workflows')->where('project_id', $project['id'])->where('type_key', $preview['type'])->value('workflow_revision_id') ?? $project['workflow_revision_id'];
            if ($effectiveRevision !== $preview['revision_id']) { throw new DomainError('preview_stale'); }
            $definition = $this->workflows->revision($context, $effectiveRevision)['definition'];
            if (!isset($definition['states'][$preview['state'] ?? ''])) { throw new DomainError('state_mapping_required'); }
            if ($this->db->table('tm_tasks')->where('parent_task_id', $task->id)->exists()) { throw new DomainError('subtree_preview_required'); }
            $parentId = null;
            if ($parentKey !== null) {
                $parent = $this->tasks->authorizedQuery($context, 'edit')->where('public_key', $parentKey)->first();
                if (!$parent || $parent->id === $task->id || $parent->project_id !== $project['id']) { throw new DomainError('parent_project_mismatch'); }
                $parentId = $parent->id;
                $seen = [$task->id => true]; $ancestor = $parent; $depth = 0;
                while ($ancestor !== null) {
                    if (isset($seen[$ancestor->id])) { throw new DomainError('task_hierarchy_cycle'); }
                    $seen[$ancestor->id] = true;
                    if (++$depth > 3) { throw new DomainError('task_depth_exceeded'); }
                    $ancestor = $ancestor->parent_task_id === null ? null : $this->db->table('tm_tasks')->where('scope_key', $context->scope)->where('project_id', $project['id'])->where('id', $ancestor->parent_task_id)->first();
                }
            }
            $this->db->table('tm_sequences')->insertOrIgnore(['scope_key' => $context->scope, 'sequence_key' => 'project:'.$project['id']]);
            $sequence = $this->db->table('tm_sequences')->where('scope_key', $context->scope)->where('sequence_key', 'project:'.$project['id'])->lockForUpdate()->first();
            $number = $sequence->current + 1;
            $this->db->table('tm_sequences')->where('scope_key', $context->scope)->where('sequence_key', 'project:'.$project['id'])->update(['current' => $number]);
            $display = $project['key'].'-'.$number;
            $this->db->table('tm_task_key_aliases')->where('task_id', $task->id)->update(['is_current' => false]);
            $this->db->table('tm_task_key_aliases')->insert(['scope_key' => $context->scope, 'task_id' => $task->id, 'display_key' => $display]);
            $this->db->table('tm_tasks')->where('id', $task->id)->update(['project_id' => $project['id'], 'type_key' => $preview['type'], 'display_key' => $display, 'parent_task_id' => $parentId, 'version' => $task->version + 1]);
            $this->db->table('tm_task_grants')->where('task_id', $task->id)->delete();
            $instance = $this->db->table('tm_workflow_instances')->where('task_id', $task->id)->where('slot_key', 'main')->orderByDesc('id')->lockForUpdate()->first();
            if ($instance->pipeline_id !== null) { throw new DomainError('pipeline_move_required'); }
            $this->lifecycle->beforeTransition($context, (array) $instance, ['key' => 'project_move', 'from' => $instance->current_state_key, 'to' => $preview['state'], 'force_exit' => true], []);
            $terminal = ($definition['states'][$preview['state']]['terminal'] ?? 'none') !== 'none';
            $this->db->table('tm_workflow_instances')->where('id', $instance->id)->update(['workflow_revision_id' => $effectiveRevision, 'workflow_key' => $this->db->table('tm_workflow_definitions')->where('id', $effectiveRevision)->value('key'),
                'current_state_key' => $preview['state'], 'version' => $instance->version + 1, 'lifecycle' => $terminal ? 'closed' : 'active', 'completed_at' => $terminal ? $this->now() : null]);
            if ($terminal) { $this->db->table('tm_active_process_slots')->where('instance_id', $instance->id)->delete(); }
            else { $this->reserveSlot($context, $instance, $task->public_key); }
            $this->db->table('tm_instance_transitions')->insert(['scope_key' => $context->scope, 'instance_id' => $instance->id, 'from_state' => $instance->current_state_key, 'to_state' => $preview['state'], 'actor_ref' => $context->actor, 'operation_id' => $operation, 'occurred_at' => $this->now()]);
            (new EventRecorder($this->db, $this->clock))->append($context, 'task', $task->public_key, 'task_project_moved', $operation, ['before' => ['project' => $task->project_id, 'display' => $task->display_key], 'after' => ['project' => $project['id'], 'display' => $display]], $task->id);
            return $this->tasks->get($context, $task->public_key);
        }, fn ($r) => $this->tasks->get($context, $r['public_key']));
    }
    private function reserveSlot(ExecutionContext $context, object $instance, string $taskKey): void
    {
        $this->db->table('tm_active_process_slots')->insertOrIgnore(['scope_key' => $context->scope, 'subject_type' => 'task', 'subject_id' => $taskKey, 'slot_key' => 'main', 'instance_id' => $instance->id]);
        $slot = $this->db->table('tm_active_process_slots')->where('scope_key', $context->scope)->where('subject_type', 'task')->where('subject_id', $taskKey)->where('slot_key', 'main')->first();
        if (!$slot || $slot->instance_id !== $instance->id) { throw new DomainError('active_slot_conflict'); }
    }

    public function previewWorkflowMigration(ExecutionContext $context, string $projectKey, int $revisionId, array $stateMap, ?string $type = null): array
    {
        $project = $this->get($context, $projectKey, 'manage'); $definition = $this->workflows->revision($context, $revisionId)['definition'];
        $tasks = $this->tasks->authorizedQuery($context, 'edit')->where('project_id', $project['id']);
        if ($type !== null) { Definition::key($type); $tasks->where('type_key', $type); }
        else { $tasks->whereNotIn('type_key', $this->db->table('tm_project_type_workflows')->where('project_id', $project['id'])->select('type_key')); }
        $instances = $this->db->table('tm_workflow_instances')->where('scope_key', $context->scope)->where('slot_key', 'main')->where('lifecycle', 'active')->whereIn('task_id', $tasks->select('id'))->orderBy('id')->limit(1001)->get();
        if ($instances->count() > 1000) { throw new DomainError('migration_batch_required'); }
        $items = [];
        foreach ($instances as $instance) {
            $state = $stateMap[$instance->current_state_key] ?? null;
            if (!is_string($state) || !isset($definition['states'][$state])) { throw new DomainError('state_mapping_required'); }
            if ($instance->pipeline_id !== null) { throw new DomainError('pipeline_move_required'); }
            $items[] = ['instance_id' => $instance->id, 'version' => $instance->version, 'state' => $instance->current_state_key];
        }
        return ['project_key' => $projectKey, 'project_version' => $project['version'], 'revision_id' => $revisionId, 'type' => $type, 'state_map' => $stateMap, 'items' => $items];
    }

    public function migrateWorkflow(ExecutionContext $context, array $preview, string $operation): array
    {
        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'migrate_project_workflow', 'preview' => $preview], function () use ($context, $preview, $operation) {
            $project = $this->get($context, $preview['project_key'], 'manage', true);
            if ($project['version'] !== $preview['project_version']) { throw new DomainError('preview_stale'); }
            $current = $this->previewWorkflowMigration($context, $preview['project_key'], $preview['revision_id'], $preview['state_map'], $preview['type']);
            if ($current !== $preview) { throw new DomainError('preview_stale'); }
            $revision = $this->workflows->revision($context, $preview['revision_id']);
            $ids = array_column($preview['items'], 'instance_id');
            $taskIds = $this->db->table('tm_workflow_instances')->whereIn('id', $ids)->pluck('task_id')->all();
            $this->db->table('tm_tasks')->whereIn('id', $taskIds)->orderBy('id')->lockForUpdate()->get();
            $instances = $this->db->table('tm_workflow_instances')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            $expected = array_column($preview['items'], null, 'instance_id');
            foreach ($instances as $instance) {
                if ($instance->version !== $expected[$instance->id]['version'] || $instance->current_state_key !== $expected[$instance->id]['state']) { throw new DomainError('preview_stale'); }
                $task = $this->tasks->authorizedQuery($context, 'edit')->where('id', $instance->task_id)->first();
                if (!$task || $task->project_id !== $project['id']) { throw new DomainError('not_found'); }
                $state = $preview['state_map'][$instance->current_state_key];
                $this->lifecycle->beforeTransition($context, (array) $instance, ['key' => 'workflow_migrate', 'from' => $instance->current_state_key, 'to' => $state, 'force_exit' => true], []);
                $terminal = ($revision['definition']['states'][$state]['terminal'] ?? 'none') !== 'none';
                $this->db->table('tm_workflow_instances')->where('id', $instance->id)->update(['workflow_revision_id' => $revision['id'], 'workflow_key' => $revision['key'],
                    'current_state_key' => $state, 'version' => $instance->version + 1, 'lifecycle' => $terminal ? 'closed' : 'active', 'completed_at' => $terminal ? $this->now() : null]);
                if ($terminal) { $this->db->table('tm_active_process_slots')->where('instance_id', $instance->id)->delete(); }
                else { $this->reserveSlot($context, $instance, $task->public_key); }
                $this->db->table('tm_tasks')->where('id', $task->id)->increment('version');
                $this->db->table('tm_instance_transitions')->insert(['scope_key' => $context->scope, 'instance_id' => $instance->id, 'from_state' => $instance->current_state_key,
                    'to_state' => $state, 'actor_ref' => $context->actor, 'operation_id' => $operation, 'occurred_at' => $this->now()]);
                (new EventRecorder($this->db, $this->clock))->append($context, 'instance', $instance->instance_key, 'workflow_migrated', $operation, ['before' => $instance->current_state_key, 'after' => $state, 'revision' => $revision['id']], $task->id);
            }
            if ($preview['type'] === null) { $this->db->table('tm_projects')->where('id', $project['id'])->update(['workflow_revision_id' => $revision['id'], 'version' => $project['version'] + 1]); }
            else {
                $this->db->table('tm_project_type_workflows')->updateOrInsert(['project_id' => $project['id'], 'type_key' => $preview['type']], ['workflow_revision_id' => $revision['id']]);
                $this->db->table('tm_projects')->where('id', $project['id'])->increment('version');
            }
            $this->event($context, $project['project_key'], 'project_workflow_migrated', $operation, ['revision' => $revision['id'], 'count' => count($ids)]);
            return $this->get($context, $project['project_key']);
        }, fn ($r) => $this->get($context, $r['project_key'], 'manage'));
    }
    private function event(ExecutionContext $context, string $key, string $action, string $operation, array $payload): void { (new EventRecorder($this->db, $this->clock))->append($context, 'project', $key, $action, $operation, $payload); }
    private function now(): string { return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'); }
}
