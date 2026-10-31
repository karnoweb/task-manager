<?php

namespace Karnoweb\TaskManager\Collaboration;

use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Karnoweb\TaskManager\CommandExecutor;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\AuthorizationProvider;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\Contracts\ResponsibilityResolver;
use Karnoweb\TaskManager\EventRecorder;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Schema\Tables;
use Karnoweb\TaskManager\TaskGraph\HierarchyService;
use Karnoweb\TaskManager\TaskGraph\ScopeGraphLock;
use Karnoweb\TaskManager\TaskService;
use Karnoweb\TaskManager\Workflow\ProjectService;
use Karnoweb\TaskManager\Workflow\WorkflowService;

final class ChecklistService
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly TaskService $tasks,
        private readonly ResponsibilityResolver $resolver, private readonly HierarchyService $hierarchy, private readonly ProjectService $projects, private readonly WorkflowService $workflows)
    {
    }

    private function authorize(ExecutionContext $context): void
    {
        $context->require('checklist.manage');
        $this->authorization->authorize($context, 'checklist.manage');
    }

    private function title(string $title): void
    {
        if (trim($title) === '' || mb_strlen($title) > 255) {
            throw new DomainError('checklist_title_invalid');
        }
    }

    public function create(ExecutionContext $context, string $taskKey, string $title, string $operation): array
    {
        $context->validateTenant();
        $this->authorize($context);
        $this->title($title);

        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'checklist_create', 'task' => $taskKey, 'title' => $title], function () use ($context, $taskKey, $title, $operation) {
            $task = $this->tasks->authorizedQuery($context, 'edit')->where('public_key', $taskKey)->whereNull('deleted_at')->lockForUpdate()->first();
            if (! $task) {
                throw new DomainError('not_found');
            }
            if ($this->db->table(Tables::name('checklists'))->where('task_id', $task->id)->whereNull('deleted_at')->count() >= 100) {
                throw new DomainError('checklist_limit');
            }
            $key = (string) Str::uuid();
            $this->db->table(Tables::name('checklists'))->insert(['public_key' => $key, 'scope_key' => $context->scope, 'task_id' => $task->id, 'title' => $title, 'created_at' => $this->now()]);
            $this->event($context, $task, 'checklist_created', $operation, ['checklist_key' => $key]);

            return $this->get($context, $key);
        }, fn ($result) => $this->get($context, $result['public_key']));
    }

    public function get(ExecutionContext $context, string $key): array
    {
        $context->validateTenant();
        if (! Str::isUuid($key)) {
            throw new DomainError('not_found');
        }
        $tasks = $this->tasks->authorizedQuery($context)->whereNull('deleted_at')->select('id');
        $row = $this->db->table(Tables::name('checklists'))->where('scope_key', $context->scope)->where('public_key', $key)->whereIn('task_id', $tasks)->first();
        if (! $row) {
            throw new DomainError('not_found');
        }

        return (array) $row;
    }

    public function change(ExecutionContext $context, string $key, int $version, string $title, bool $deleted, string $operation): array
    {
        $context->validateTenant();
        $this->authorize($context);
        $this->title($title);

        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'checklist_change', 'key' => $key, 'version' => $version, 'title' => $title, 'deleted' => $deleted], function () use ($context, $key, $version, $title, $deleted, $operation) {
            $before = $this->get($context, $key);
            $task = $this->tasks->authorizedQuery($context, 'edit')->where('id', $before['task_id'])->whereNull('deleted_at')->lockForUpdate()->first();
            if (! $task) {
                throw new DomainError('not_found');
            }
            $list = $this->db->table(Tables::name('checklists'))->where('id', $before['id'])->lockForUpdate()->first();
            if ($list->version !== $version) {
                throw new DomainError('version_conflict');
            }
            if (! $deleted && $list->deleted_at !== null && new \DateTimeImmutable($list->deleted_at, new \DateTimeZone('UTC')) < $this->clock->now()->modify('-30 days')) {
                throw new DomainError('restore_window_expired');
            }
            if (! $deleted && $list->deleted_at !== null && $this->db->table(Tables::name('checklists'))->where('task_id', $task->id)->whereNull('deleted_at')->count() >= 100) {
                throw new DomainError('checklist_limit');
            }
            $this->db->table(Tables::name('checklists'))->where('id', $list->id)->update(['title' => $title, 'deleted_at' => $deleted ? ($list->deleted_at ?? $this->now()) : null, 'version' => $version + 1]);
            $this->event($context, $task, 'checklist_changed', $operation, ['checklist_key' => $key, 'deleted' => $deleted]);

            return $this->get($context, $key);
        }, fn ($result) => $this->get($context, $result['public_key']));
    }

    public function addItem(ExecutionContext $context, string $listKey, int $version, string $title, ?string $assignee, ?\DateTimeImmutable $due, string $operation): array
    {
        $context->validateTenant();
        $this->authorize($context);
        $this->title($title);
        $dueAt = $due?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');

        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'checklist_item_add', 'list' => $listKey, 'version' => $version, 'title' => $title, 'assignee' => $assignee, 'due' => $dueAt], function () use ($context, $listKey, $version, $title, $assignee, $dueAt, $operation) {
            [$list, $task] = $this->lockList($context, $listKey);
            if ($list['version'] !== $version) {
                throw new DomainError('version_conflict');
            }
            $this->eligible($context, $task->id, $assignee);
            if ($this->db->table(Tables::name('checklist_items'))->where('checklist_id', $list['id'])->whereNull('deleted_at')->count() >= 1000) {
                throw new DomainError('checklist_item_limit');
            }
            $key = (string) Str::uuid();
            $position = (int) $this->db->table(Tables::name('checklist_items'))->where('checklist_id', $list['id'])->max('position') + 1;
            $this->db->table(Tables::name('checklist_items'))->insert(['public_key' => $key, 'checklist_id' => $list['id'], 'title' => $title, 'assignee_ref' => $assignee, 'due_at' => $dueAt, 'position' => $position]);
            $this->db->table(Tables::name('checklists'))->where('id', $list['id'])->increment('version');
            $this->event($context, $task, 'checklist_item_added', $operation, ['item_key' => $key]);

            return $this->item($context, $key);
        }, fn ($result) => $this->item($context, $result['public_key']));
    }

    public function item(ExecutionContext $context, string $key): array
    {
        $context->validateTenant();
        if (! Str::isUuid($key)) {
            throw new DomainError('not_found');
        }
        $lists = $this->db->table(Tables::name('checklists'))->where('scope_key', $context->scope)->whereNull('deleted_at')->whereIn('task_id', $this->tasks->authorizedQuery($context)->whereNull('deleted_at')->select('id'))->select('id');
        $row = $this->db->table(Tables::name('checklist_items'))->where('public_key', $key)->whereIn('checklist_id', $lists)->first();
        if (! $row) {
            throw new DomainError('not_found');
        }

        return (array) $row;
    }

    public function changeItem(ExecutionContext $context, string $key, int $version, array $changes, string $operation): array
    {
        $context->validateTenant();
        $this->authorize($context);
        if (! $changes || array_diff(array_keys($changes), ['title', 'done', 'assignee_ref', 'due_at', 'deleted'])) {
            throw new DomainError('checklist_item_invalid');
        }
        if (isset($changes['title'])) {
            if (! is_string($changes['title'])) {
                throw new DomainError('checklist_title_invalid');
            } $this->title($changes['title']);
        }
        foreach (['done', 'deleted'] as $flag) {
            if (isset($changes[$flag]) && ! is_bool($changes[$flag])) {
                throw new DomainError('checklist_item_invalid');
            }
        }
        if (array_key_exists('assignee_ref', $changes) && $changes['assignee_ref'] !== null && ! is_string($changes['assignee_ref'])) {
            throw new DomainError('assignee_ineligible');
        }
        if (array_key_exists('due_at', $changes)) {
            if ($changes['due_at'] !== null && ! $changes['due_at'] instanceof \DateTimeImmutable) {
                throw new DomainError('checklist_due_invalid');
            } $changes['due_at'] = $changes['due_at']?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        }

        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'checklist_item_change', 'key' => $key, 'version' => $version, 'changes' => $changes], function () use ($context, $key, $version, $changes, $operation) {
            $before = $this->item($context, $key);
            $listKey = $this->db->table(Tables::name('checklists'))->where('id', $before['checklist_id'])->value('public_key');
            [$list, $task] = $this->lockList($context, $listKey);
            $item = $this->db->table(Tables::name('checklist_items'))->where('id', $before['id'])->lockForUpdate()->first();
            if ($item->version !== $version) {
                throw new DomainError('version_conflict');
            }
            if ($item->deleted_at !== null && ($changes['deleted'] ?? null) !== false) {
                throw new DomainError('resource_deleted');
            }
            if (array_key_exists('assignee_ref', $changes)) {
                $this->eligible($context, $task->id, $changes['assignee_ref']);
            }
            $values = $changes;
            if (array_key_exists('deleted', $values)) {
                $values['deleted_at'] = $values['deleted'] ? $this->now() : null;
                unset($values['deleted']);
            }
            $this->db->table(Tables::name('checklist_items'))->where('id', $item->id)->update([...$values, 'version' => $version + 1]);
            $this->db->table(Tables::name('checklists'))->where('id', $list['id'])->increment('version');
            $this->event($context, $task, 'checklist_item_changed', $operation, ['item_key' => $key, 'changed_fields' => array_keys($changes)]);

            return $this->item($context, $key);
        }, fn ($result) => $this->item($context, $result['public_key']));
    }

    public function convert(ExecutionContext $context, string $key, int $version, string $operation): array
    {
        $context->validateTenant();
        $this->authorize($context);

        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'checklist_convert', 'key' => $key, 'version' => $version], function () use ($context, $key, $version, $operation) {
            ScopeGraphLock::acquire($this->db, $context);
            $before = $this->item($context, $key);
            $listKey = $this->db->table(Tables::name('checklists'))->where('id', $before['checklist_id'])->value('public_key');
            [$list, $parent] = $this->lockList($context, $listKey);
            $item = $this->db->table(Tables::name('checklist_items'))->where('id', $before['id'])->lockForUpdate()->first();
            if ($item->version !== $version) {
                throw new DomainError('version_conflict');
            }
            if ($item->deleted_at !== null) {
                throw new DomainError('resource_deleted');
            }
            if ($item->converted_task_id !== null) {
                return $this->tasks->get($context, $this->db->table(Tables::name('tasks'))->where('id', $item->converted_task_id)->value('public_key'));
            }
            $task = $this->tasks->create($context, $item->title, 'checklist-task:'.hash('sha256', $operation));
            if ($parent->project_id !== null) {
                $project = $this->db->table(Tables::name('projects'))->where('id', $parent->project_id)->first();
                $revision = $this->db->table(Tables::name('project_type_workflows'))->where('project_id', $project->id)->where('type_key', 'task')->value('workflow_revision_id') ?? $project->workflow_revision_id;
                $initial = $this->workflows->revision($context, $revision)['definition']['initial'];
                $preview = $this->projects->previewTaskMove($context, $task['public_key'], $project->project_key, 'task', ['pending' => $initial]);
                $task = $this->projects->moveTask($context, $preview, 'checklist-project:'.hash('sha256', $operation));
            }
            $task = $this->hierarchy->reparent($context, $task['public_key'], $task['version'], $parent->public_key, $parent->version, 'checklist-parent:'.hash('sha256', $operation));
            $this->db->table(Tables::name('checklist_items'))->where('id', $item->id)->update(['converted_task_id' => $task['id'], 'version' => $version + 1]);
            $this->db->table(Tables::name('checklists'))->where('id', $list['id'])->increment('version');
            $this->event($context, $parent, 'checklist_item_converted', $operation, ['item_key' => $key, 'converted_task_key' => $task['public_key']]);

            return $task;
        }, fn ($result) => $this->tasks->get($context, $result['public_key']));
    }

    public function items(ExecutionContext $context, string $listKey, ?string $assignee = null, ?\DateTimeImmutable $dueBefore = null, int $afterId = 0, int $limit = 100): array
    {
        $context->validateTenant();
        $list = $this->get($context, $listKey);
        if ($list['deleted_at'] !== null) {
            throw new DomainError('resource_deleted');
        }
        if ($afterId < 0 || $limit < 1 || $limit > 250) {
            throw new DomainError('pagination_invalid');
        }
        $query = $this->db->table(Tables::name('checklist_items'))->where('checklist_id', $list['id'])->whereNull('deleted_at');
        if ($assignee !== null) {
            $query->where('assignee_ref', $assignee);
        }
        if ($dueBefore !== null) {
            $query->where('due_at', '<=', $dueBefore->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'));
        }
        $total = (clone $query)->count();
        $done = (clone $query)->where('done', true)->count();
        $items = $query->where('id', '>', $afterId)->orderBy('id')->limit($limit)->get()->map(fn ($row) => (array) $row)->all();

        return ['total' => $total, 'done' => $done, 'percent' => $total === 0 ? 0.0 : round(100 * $done / $total, 2), 'items' => $items, 'next_after_id' => $items ? end($items)['id'] : null];
    }

    public function progress(ExecutionContext $context, string $taskKey): array
    {
        $context->validateTenant();
        $task = $this->tasks->get($context, $taskKey);
        $lists = $this->db->table(Tables::name('checklists'))->where('scope_key', $context->scope)->where('task_id', $task['id'])->whereNull('deleted_at')->select('id');
        $items = $this->db->table(Tables::name('checklist_items'))->whereIn('checklist_id', $lists)->whereNull('deleted_at');
        $total = (clone $items)->count();
        $done = (clone $items)->where('done', true)->count();

        return ['total' => $total, 'done' => $done, 'percent' => $total === 0 ? 0.0 : round(100 * $done / $total, 2)];
    }

    private function lockList(ExecutionContext $context, string $key): array
    {
        $before = $this->get($context, $key);
        $task = $this->tasks->authorizedQuery($context, 'edit')->where('id', $before['task_id'])->whereNull('deleted_at')->lockForUpdate()->first();
        if (! $task) {
            throw new DomainError('not_found');
        }
        $list = $this->db->table(Tables::name('checklists'))->where('id', $before['id'])->lockForUpdate()->first();
        if ($list->deleted_at !== null) {
            throw new DomainError('resource_deleted');
        }

        return [(array) $list, $task];
    }

    private function eligible(ExecutionContext $context, int $taskId, ?string $actor): void
    {
        if ($actor !== null && (! RecipientAccess::permitted($this->tasks, $context, $taskId, $actor) || ! $this->resolver->eligible($context, $actor, 'checklist'))) {
            throw new DomainError('assignee_ineligible');
        }
    }

    private function now(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function event(ExecutionContext $context, object $task, string $action, string $operation, array $payload): void
    {
        (new EventRecorder($this->db, $this->clock))->append($context, 'task', $task->public_key, $action, $operation, $payload, $task->id);
    }
}
