<?php

namespace Karnoweb\TaskManager\Planning;

use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Karnoweb\TaskManager\CommandExecutor;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\AuthorizationProvider;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\EventRecorder;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\TaskGraph\ScopeGraphLock;
use Karnoweb\TaskManager\TaskService;
use Karnoweb\TaskManager\Workflow\ProjectService;

final class PositionService
{
    private const STEP = 102400000000;
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly TaskService $tasks, private readonly ProjectService $projects, private readonly SprintService $sprints) {}
    private function authorize(ExecutionContext $context): void { $context->require('task.reorder'); $this->authorization->authorize($context, 'task.reorder'); }
    public function create(ExecutionContext $context, string $kind, string $reference, string $operation): array
    {
        $this->authorize($context); $this->root($context, $kind, $reference, true);
        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'position_context', 'kind' => $kind, 'reference' => $reference], function () use ($context, $kind, $reference, $operation) {
            ScopeGraphLock::acquire($this->db, $context); $this->root($context, $kind, $reference, true);
            $created = $this->db->table('tm_task_position_contexts')->insertOrIgnore(['context_key' => (string) Str::uuid(), 'scope_key' => $context->scope, 'kind' => $kind, 'context_ref' => $reference]);
            $key = $this->db->table('tm_task_position_contexts')->where('scope_key', $context->scope)->where('kind', $kind)->where('context_ref', $reference)->value('context_key');
            if ($created) { (new EventRecorder($this->db, $this->clock))->append($context, 'position_context', $key, 'position_context_created', $operation, ['kind' => $kind]); } return $this->get($context, $key);
        }, fn ($result) => $this->get($context, $result['context_key']));
    }
    public function get(ExecutionContext $context, string $key, bool $write = false): array
    {
        if (!Str::isUuid($key)) { throw new DomainError('not_found'); }
        $row = $this->db->table('tm_task_position_contexts')->where('scope_key', $context->scope)->where('context_key', $key)->first(); if (!$row) { throw new DomainError('not_found'); }
        $this->root($context, $row->kind, $row->context_ref, $write); return (array) $row;
    }
    public function place(ExecutionContext $context, string $key, int $version, string $taskKey, int $taskVersion, ?string $after, ?string $before, string $operation): array
    {
        $this->authorize($context);
        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'position_place', 'context' => $key, 'version' => $version, 'task' => $taskKey, 'task_version' => $taskVersion, 'after' => $after, 'before' => $before], function () use ($context, $key, $version, $taskKey, $taskVersion, $after, $before, $operation) {
            ScopeGraphLock::acquire($this->db, $context); $position = $this->get($context, $key, true); $current = $this->db->table('tm_task_position_contexts')->where('id', $position['id'])->lockForUpdate()->first(); if ($current->version !== $version) { throw new DomainError('version_conflict'); }
            $root = $this->root($context, $position['kind'], $position['context_ref'], true); $task = $this->eligible($this->tasks->authorizedQuery($context, 'edit'), $position, $root)->where('public_key', $taskKey)->lockForUpdate()->first(); if (!$task) { throw new DomainError('position_task_context_mismatch'); }
            if ($task->version !== $taskVersion) { throw new DomainError('version_conflict'); }
            if ($after === $taskKey || $before === $taskKey || ($after !== null && $after === $before)) { throw new DomainError('position_neighbors_invalid'); }
            // Prune only structural departures, never rows hidden by the current actor's permissions.
            $eligibleIds = $this->eligible($this->db->table('tm_tasks')->where('scope_key', $context->scope), $position, $root)->select('id');
            $this->db->table('tm_task_positions')->where('context_id', $position['id'])->whereNotIn('task_id', $eligibleIds)->delete();
            [$left, $right] = $this->bounds($context, $position, $root, $task->id, $after, $before);
            if ($right - $left <= 1 || abs($left) > 100000000000000000 || abs($right) > 100000000000000000) {
                $rows = $this->db->table('tm_task_positions')->where('context_id', $position['id'])->orderBy('rank_units')->orderBy('task_id')->limit(1001)->get(); if ($rows->count() > 1000) { throw new DomainError('position_rebalance_batch_required'); }
                if ($this->eligible($this->tasks->authorizedQuery($context, 'edit'), $position, $root)->whereIn('id', $rows->pluck('task_id')->all())->count() !== $rows->count()) { throw new DomainError('position_rebalance_access_denied'); }
                foreach ($rows as $index => $row) { $this->db->table('tm_task_positions')->where('id', $row->id)->update(['rank_units' => ($index + 1) * self::STEP, 'version' => $row->version + 1]); }
                [$left, $right] = $this->bounds($context, $position, $root, $task->id, $after, $before);
            }
            $rank = $left + intdiv($right - $left, 2); $existing = $this->db->table('tm_task_positions')->where('context_id', $position['id'])->where('task_id', $task->id)->first();
            if ($existing) { $this->db->table('tm_task_positions')->where('id', $existing->id)->update(['rank_units' => $rank, 'version' => $existing->version + 1]); }
            else { $this->db->table('tm_task_positions')->insert(['context_id' => $position['id'], 'task_id' => $task->id, 'rank_units' => $rank]); }
            $this->db->table('tm_tasks')->where('id', $task->id)->increment('version'); $this->db->table('tm_task_position_contexts')->where('id', $position['id'])->increment('version');
            (new EventRecorder($this->db, $this->clock))->append($context, 'task', $taskKey, 'task_reordered', $operation, ['context_key' => $key, 'context_version' => $version + 1, 'rank_value' => $this->rank($rank)], $task->id);
            return ['context_key' => $key, 'version' => $version + 1, 'task_key' => $taskKey, 'task_version' => $taskVersion + 1, 'rank_value' => $this->rank($rank)];
        }, function ($result) use ($context) { $this->get($context, $result['context_key']); $this->tasks->get($context, $result['task_key']); });
    }
    public function listing(ExecutionContext $context, string $key, int $limit = 100, ?array $cursor = null): array
    {
        $position = $this->get($context, $key); $root = $this->root($context, $position['kind'], $position['context_ref'], false);
        if ($limit < 1 || $limit > 250) { throw new DomainError('pagination_invalid'); }
        if ($cursor !== null && (($cursor['context_key'] ?? null) !== $key || ($cursor['version'] ?? null) !== $position['version'] || ($cursor['actor'] ?? null) !== $context->actor || ($cursor['scope'] ?? null) !== $context->scope || !is_int($cursor['rank_units'] ?? null) || !is_int($cursor['task_id'] ?? null))) { throw new DomainError('cursor_stale'); }
        $query = $this->db->table('tm_task_positions')->where('context_id', $position['id'])->whereIn('task_id', $this->eligible($this->tasks->authorizedQuery($context), $position, $root)->select('id'));
        $total = (clone $query)->count(); if ($cursor !== null) { $query->where(fn ($q) => $q->where('rank_units', '>', $cursor['rank_units'])->orWhere(fn ($tie) => $tie->where('rank_units', $cursor['rank_units'])->where('task_id', '>', $cursor['task_id']))); }
        $rows = $query->orderBy('rank_units')->orderBy('task_id')->limit($limit)->get(); $keys = $this->db->table('tm_tasks')->whereIn('id', $rows->pluck('task_id')->all())->pluck('public_key', 'id')->all();
        $items = $rows->map(fn ($row) => ['task_key' => $keys[$row->task_id], 'rank_value' => $this->rank($row->rank_units), 'version' => $row->version])->all(); $last = $rows->last();
        return ['total' => $total, 'items' => $items, 'cursor' => $last === null ? null : ['context_key' => $key, 'version' => $position['version'], 'actor' => $context->actor, 'scope' => $context->scope, 'rank_units' => $last->rank_units, 'task_id' => $last->task_id]];
    }
    private function root(ExecutionContext $context, string $kind, string $ref, bool $write): array
    {
        if ($kind === 'backlog') { $root = $this->projects->get($context, $ref, $write ? 'edit' : 'view'); if ($write && $root['status'] !== 'active') { throw new DomainError('project_archived'); } return $root; }
        if ($kind === 'sprint') { $root = $this->sprints->get($context, $ref, $write); if ($write && !in_array($root['state'], ['planned', 'active'], true)) { throw new DomainError('sprint_state_invalid'); } return $root; }
        if ($kind === 'children') { $root = $this->tasks->get($context, $ref); if ($write && !$this->tasks->authorizedQuery($context, 'edit')->where('id', $root['id'])->whereNull('deleted_at')->exists()) { throw new DomainError('not_found'); } return $root; }
        throw new DomainError('position_context_invalid');
    }
    private function eligible(\Illuminate\Database\Query\Builder $query, array $position, array $root): \Illuminate\Database\Query\Builder
    {
        $query->whereNull('deleted_at');
        if ($position['kind'] === 'backlog') { $query->where('project_id', $root['id'])->whereNull('archived_at')->whereNotIn('id', $this->db->table('tm_active_sprint_tasks')->select('task_id'))->whereNotIn('id', \Karnoweb\TaskManager\Workflow\StateIndex::currentTaskIds($this->db, $root['scope_key'], ['done', 'cancelled'])); }
        elseif ($position['kind'] === 'sprint') { $query->where('project_id', $root['project_id'])->whereIn('id', $this->db->table('tm_sprint_memberships')->where('sprint_id', $root['id'])->select('task_id')); }
        else { $query->where('parent_task_id', $root['id']); } return $query;
    }
    private function bounds(ExecutionContext $context, array $position, array $root, int $taskId, ?string $after, ?string $before): array
    {
        $base = $this->db->table('tm_task_positions')->where('context_id', $position['id'])->where('task_id', '!=', $taskId); $left = null; $right = null;
        foreach (['after' => $after, 'before' => $before] as $side => $key) {
            if ($key === null) { continue; }
            $anchor = $this->eligible($this->tasks->authorizedQuery($context, 'edit'), $position, $root)->where('public_key', $key)->first(); if (!$anchor) { throw new DomainError('not_found'); }
            $rank = (clone $base)->where('task_id', $anchor->id)->value('rank_units'); if ($rank === null) { throw new DomainError('position_neighbors_changed'); }
            if ($side === 'after') { $left = $rank; } else { $right = $rank; }
        }
        if ($left === null && $right === null) { $left = (int) ((clone $base)->max('rank_units') ?? 0); $right = $left + self::STEP * 2; }
        elseif ($left === null) { $left = (clone $base)->where('rank_units', '<', $right)->max('rank_units') ?? $right - self::STEP * 2; }
        elseif ($right === null) { $right = (clone $base)->where('rank_units', '>', $left)->min('rank_units') ?? $left + self::STEP * 2; }
        elseif ($left >= $right || (clone $base)->where('rank_units', '>', $left)->where('rank_units', '<', $right)->exists()) { if ($left !== $right) { throw new DomainError('position_neighbors_changed'); } }
        return [(int) $left, (int) $right];
    }
    private function rank(int $units): string { return ($units < 0 ? '-' : '').intdiv(abs($units), 100000000).'.'.str_pad((string) (abs($units) % 100000000), 8, '0', STR_PAD_LEFT); }
}
