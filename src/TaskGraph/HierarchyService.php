<?php

namespace Karnoweb\TaskManager\TaskGraph;

use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Karnoweb\TaskManager\CommandExecutor;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\AuthorizationProvider;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\EventRecorder;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Schema\Tables;
use Karnoweb\TaskManager\TaskService;
use Karnoweb\TaskManager\Workflow\ProjectService;
use Karnoweb\TaskManager\Workflow\StateIndex;

final class HierarchyService
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly TaskService $tasks, private readonly ProjectService $projects, private readonly AuthorizationProvider $authorization)
    {
        Tables::assertConnection($db);
    }

    public function reparent(ExecutionContext $context, string $key, int $version, ?string $parentKey, ?int $parentVersion, string $operation): array
    {
        $context->validateTenant();
        $context->require('task.reparent');
        $this->authorization->authorize($context, 'task.reparent');

        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'task_reparent', 'key' => $key, 'version' => $version, 'parent' => $parentKey, 'parent_version' => $parentVersion], function () use ($context, $key, $version, $parentKey, $parentVersion, $operation) {
            ScopeGraphLock::acquire($this->db, $context);
            $task = $this->lock($context, $key, $version);
            $parent = $parentKey === null ? null : $this->lock($context, $parentKey, $parentVersion ?? 0);
            if ($parent !== null && $parent['project_id'] !== $task['project_id']) {
                throw new DomainError('parent_project_mismatch');
            }
            $ids = $this->subtreeIds($context, $task['id']);
            $this->authorizeSubtree($context, $ids);
            $limit = $task['project_id'] === null ? 3 : (int) $this->db->table(Tables::name('projects'))->where('id', $task['project_id'])->value('hierarchy_max_depth');
            HierarchyIndex::move($this->db, $context, $task['id'], $parent['id'] ?? null, $limit);
            $this->db->table(Tables::name('tasks'))->whereIn('id', $ids)->increment('version');
            $this->db->table(Tables::name('tasks'))->where('id', $task['id'])->update(['parent_task_id' => $parent['id'] ?? null, 'updated_at' => $this->now()]);
            $this->event($context, $key, 'task_reparented', $operation, ['parent_key' => $parentKey, 'subtree_count' => count($ids)], $task['id']);

            return $this->tasks->get($context, $key);
        }, fn ($result) => $this->tasks->get($context, $result['public_key']));
    }

    public function configureDepth(ExecutionContext $context, string $projectKey, int $version, int $depth, string $operation): array
    {
        $context->validateTenant();
        if ($depth < 1 || $depth > 8) {
            throw new DomainError('task_depth_invalid');
        }

        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'hierarchy_depth', 'project' => $projectKey, 'version' => $version, 'depth' => $depth], function () use ($context, $projectKey, $version, $depth, $operation) {
            ScopeGraphLock::acquire($this->db, $context);
            $project = $this->projects->get($context, $projectKey, 'manage', true);
            if ($project['version'] !== $version) {
                throw new DomainError('version_conflict');
            }
            $existing = $this->db->table(Tables::name('task_closure'))->where('scope_key', $context->scope)->whereIn('descendant_task_id', $this->db->table(Tables::name('tasks'))->where('project_id', $project['id'])->select('id'))->max('depth');
            if ($existing > $depth) {
                throw new DomainError('task_depth_exceeded');
            }
            $this->db->table(Tables::name('projects'))->where('id', $project['id'])->update(['hierarchy_max_depth' => $depth, 'version' => $version + 1]);
            ScopeGraphLock::changed($this->db, $context);
            (new EventRecorder($this->db, $this->clock))->append($context, 'project', $projectKey, 'hierarchy_policy_changed', $operation, ['max_depth' => $depth]);

            return $this->projects->get($context, $projectKey);
        }, fn ($result) => $this->projects->get($context, $result['project_key'], 'manage'));
    }

    public function tree(ExecutionContext $context, string $key, int $limit = 100, int $afterId = 0): array
    {
        $context->validateTenant();
        $task = $this->tasks->get($context, $key);
        if ($limit < 1 || $limit > 250 || $afterId < 0) {
            throw new DomainError('pagination_invalid');
        }
        $query = $this->tasks->authorizedQuery($context)->whereNull('deleted_at')->whereIn('id', $this->db->table(Tables::name('task_closure'))->where('scope_key', $context->scope)->where('ancestor_task_id', $task['id'])->select('descendant_task_id'));
        $total = (clone $query)->count();
        $items = $query->where('id', '>', $afterId)->orderBy('id')->limit($limit)->get(['id', 'public_key', 'parent_task_id', 'title', 'project_id', 'type_key', 'version'])->map(fn ($row) => (array) $row)->all();

        return ['total' => $total, 'items' => $items, 'next_after_id' => $items ? end($items)['id'] : null];
    }

    public function progress(ExecutionContext $context, string $key, string $mode = 'direct'): array
    {
        $context->validateTenant();
        $task = $this->tasks->get($context, $key);
        if (! in_array($mode, ['direct', 'leaf_weighted'], true)) {
            throw new DomainError('progress_policy_invalid');
        }
        $query = $this->tasks->authorizedQuery($context)->whereNull('deleted_at')->whereIn('id', $this->db->table(Tables::name('task_closure'))->where('scope_key', $context->scope)->where('ancestor_task_id', $task['id'])->where('depth', '>', 0)->select('descendant_task_id'));
        if ($mode === 'direct') {
            $query->where('parent_task_id', $task['id']);
        } else {
            $query->whereNotIn('id', $this->db->table(Tables::name('tasks'))->where('scope_key', $context->scope)->whereNull('deleted_at')->whereNotNull('parent_task_id')->select('parent_task_id'));
        }
        $total = (clone $query)->count();
        $successful = StateIndex::currentTaskIds($this->db, $context->scope, ['done']);
        $done = (clone $query)->whereIn('id', $successful)->count();

        return ['mode' => $mode, 'total' => $total, 'done' => $done, 'percent' => $total === 0 ? 0.0 : round(100 * $done / $total, 2), 'auto_completed' => false];
    }

    public function previewDelete(ExecutionContext $context, string $key, string $policy = 'deny_if_children'): array
    {
        $context->validateTenant();
        $context->require('task.delete');
        $task = $this->tasks->get($context, $key);
        $this->authorization->authorize($context, 'task.delete');
        if (! in_array($policy, ['deny_if_children', 'cascade_soft_delete', 'detach'], true)) {
            throw new DomainError('delete_policy_invalid');
        }
        $ids = $this->subtreeIds($context, $task['id']);
        $rows = $this->authorizeSubtree($context, $ids);
        if ($policy === 'deny_if_children' && count($ids) > 1) {
            throw new DomainError('parent_has_children');
        }

        return ['task_key' => $key, 'policy' => $policy, 'scope' => $context->scope, 'actor' => $context->actor,
            'items' => $rows->map(fn ($row) => ['key' => $row->public_key, 'id' => $row->id, 'version' => $row->version, 'parent_id' => $row->parent_task_id])->all()];
    }

    public function executeDelete(ExecutionContext $context, array $preview, string $operation): array
    {
        $context->validateTenant();
        $context->require('task.delete');
        if (! is_string($preview['task_key'] ?? null) || ! is_string($preview['policy'] ?? null)) {
            throw new DomainError('preview_invalid');
        }

        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'hierarchy_delete', 'preview' => $preview], function () use ($context, $preview, $operation) {
            ScopeGraphLock::acquire($this->db, $context);
            if ($this->previewDelete($context, $preview['task_key'], $preview['policy']) !== $preview) {
                throw new DomainError('preview_stale');
            }
            $items = $preview['items'];
            $root = $this->tasks->get($context, $preview['task_key']);
            if ($preview['policy'] === 'detach') {
                foreach ($items as $item) {
                    if ($item['parent_id'] === $root['id']) {
                        HierarchyIndex::move($this->db, $context, $item['id'], null, 8);
                        $this->db->table(Tables::name('tasks'))->where('id', $item['id'])->update(['parent_task_id' => null, 'version' => $item['version'] + 1]);
                    }
                }
                $items = array_values(array_filter($items, fn ($item) => $item['id'] === $root['id']));
            }
            $depths = $this->db->table(Tables::name('task_closure'))->where('ancestor_task_id', $root['id'])->pluck('depth', 'descendant_task_id')->all();
            usort($items, fn ($a, $b) => ($depths[$b['id']] ?? 0) <=> ($depths[$a['id']] ?? 0));
            foreach ($items as $item) {
                $this->tasks->mutate($context, $item['key'], $item['version'], 'hierarchy-delete:'.hash('sha256', $operation.':'.$item['id']), 'delete');
            }
            ScopeGraphLock::changed($this->db, $context);

            return $this->tasks->get($context, $preview['task_key']);
        }, fn ($result) => $this->tasks->get($context, $result['public_key']));
    }

    private function lock(ExecutionContext $context, string $key, int $version): array
    {
        $task = $this->tasks->authorizedQuery($context, 'edit')->where('public_key', $key)->lockForUpdate()->first();
        if (! $task) {
            throw new DomainError('not_found');
        }
        if ($task->deleted_at !== null || $task->archived_at !== null) {
            throw new DomainError('task_inactive');
        }
        if ($task->version !== $version) {
            throw new DomainError('version_conflict');
        }

        return (array) $task;
    }

    private function subtreeIds(ExecutionContext $context, int $id): array
    {
        $ids = $this->db->table(Tables::name('task_closure'))->where('scope_key', $context->scope)->where('ancestor_task_id', $id)->orderBy('descendant_task_id')->limit(1001)->pluck('descendant_task_id')->all();
        if (! $ids) {
            throw new DomainError('hierarchy_index_missing');
        }
        if (count($ids) > 1000) {
            throw new DomainError('subtree_batch_required');
        }

        return $ids;
    }

    private function authorizeSubtree(ExecutionContext $context, array $ids): Collection
    {
        $rows = $this->tasks->authorizedQuery($context, 'edit')->whereIn('id', $ids)->orderBy('id')->get();
        if ($rows->count() !== count($ids)) {
            throw new DomainError('subtree_access_denied');
        }

        return $rows;
    }

    private function event(ExecutionContext $context, string $key, string $action, string $operation, array $data, int $taskId): void
    {
        (new EventRecorder($this->db, $this->clock))->append($context, 'task', $key, $action, $operation, $data, $taskId);
    }

    private function now(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
