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
use Karnoweb\TaskManager\Workflow\StateIndex;

final class SprintService
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly TaskService $tasks, private readonly ProjectService $projects) {}
    private function authorize(ExecutionContext $context): void { $context->require('sprint.manage'); $this->authorization->authorize($context, 'sprint.manage'); }
    public function get(ExecutionContext $context, string $key, bool $write = false, bool $lock = false): array
    {
        if (!Str::isUuid($key)) { throw new DomainError('not_found'); }
        if ($write) { $this->authorize($context); }
        $query = $this->db->table('tm_sprints')->where('scope_key', $context->scope)->where('sprint_key', $key); if ($lock) { $query->lockForUpdate(); }
        $row = $query->first(); if (!$row) { throw new DomainError('not_found'); }
        $projectKey = $this->db->table('tm_projects')->where('id', $row->project_id)->where('scope_key', $context->scope)->value('project_key');
        $project = $this->projects->get($context, $projectKey ?? '', $write ? 'edit' : 'view');
        if ($write && $project['status'] !== 'active') { throw new DomainError('project_archived'); }
        return [...(array) $row, 'project_key' => $project['project_key']];
    }
    public function create(ExecutionContext $context, string $projectKey, string $name, ?\DateTimeImmutable $start, ?\DateTimeImmutable $end, string $operation): array
    {
        $this->authorize($context);
        if (trim($name) === '' || mb_strlen($name) > 255 || ($start !== null && $end !== null && $end <= $start)) { throw new DomainError('sprint_invalid'); }
        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'sprint_create', 'project' => $projectKey, 'name' => $name, 'start' => $this->stamp($start), 'end' => $this->stamp($end)], function () use ($context, $projectKey, $name, $start, $end, $operation) {
            ScopeGraphLock::acquire($this->db, $context); $project = $this->projects->get($context, $projectKey, 'edit', true); if ($project['status'] !== 'active') { throw new DomainError('project_archived'); }
            $key = (string) Str::uuid(); $this->db->table('tm_sprints')->insert(['sprint_key' => $key, 'scope_key' => $context->scope, 'project_id' => $project['id'], 'name' => $name, 'planned_start_at' => $this->stamp($start), 'planned_end_at' => $this->stamp($end)]);
            $this->event($context, $key, 'sprint_created', $operation, []); return $this->get($context, $key);
        }, fn ($result) => $this->get($context, $result['sprint_key']));
    }
    public function enroll(ExecutionContext $context, string $key, int $version, array $references, string $operation): array
    {
        $this->authorize($context);
        if (!array_is_list($references) || !$references || count($references) > 250) { throw new DomainError('batch_size_invalid'); }
        foreach ($references as $reference) { if (!is_array($reference) || array_diff(array_keys($reference), ['task_key', 'version']) || !is_string($reference['task_key'] ?? null) || !is_int($reference['version'] ?? null)) { throw new DomainError('sprint_member_invalid'); } }
        usort($references, fn ($a, $b) => strcmp($a['task_key'], $b['task_key']));
        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'sprint_enroll', 'key' => $key, 'version' => $version, 'tasks' => $references], function () use ($context, $key, $version, $references, $operation) {
            ScopeGraphLock::acquire($this->db, $context); $sprint = $this->get($context, $key, true, true); $this->version($sprint, $version); $this->open($sprint);
            foreach ($references as $reference) {
                $task = $this->tasks->authorizedQuery($context, 'edit')->where('public_key', $reference['task_key'])->whereNull('deleted_at')->whereNull('archived_at')->lockForUpdate()->first(); if (!$task) { throw new DomainError('not_found'); }
                if ($task->version !== $reference['version']) { throw new DomainError('version_conflict'); }
                if ($task->project_id !== $sprint['project_id']) { throw new DomainError('sprint_project_mismatch'); }
                $this->enrollTask($context, $sprint, $task, $version + 1);
            }
            $this->db->table('tm_sprints')->where('id', $sprint['id'])->increment('version'); ScopeGraphLock::changed($this->db, $context);
            $this->event($context, $key, $sprint['state'] === 'active' ? 'sprint_scope_added' : 'sprint_members_enrolled', $operation, ['membership_revision' => $version + 1]); return $this->get($context, $key);
        }, fn ($result) => $this->get($context, $result['sprint_key'], true));
    }
    public function remove(ExecutionContext $context, string $key, int $version, string $taskKey, int $taskVersion, string $operation): array
    {
        $this->authorize($context);
        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'sprint_remove', 'key' => $key, 'version' => $version, 'task' => $taskKey, 'task_version' => $taskVersion], function () use ($context, $key, $version, $taskKey, $taskVersion, $operation) {
            ScopeGraphLock::acquire($this->db, $context); $sprint = $this->get($context, $key, true, true); $this->version($sprint, $version); $this->open($sprint);
            $task = $this->tasks->authorizedQuery($context, 'edit')->where('public_key', $taskKey)->lockForUpdate()->first(); if (!$task) { throw new DomainError('not_found'); } if ($task->version !== $taskVersion) { throw new DomainError('version_conflict'); }
            $membership = $this->db->table('tm_sprint_memberships')->where('sprint_id', $sprint['id'])->where('task_id', $task->id)->first(); if (!$membership) { throw new DomainError('not_found'); }
            $this->db->table('tm_sprint_tasks')->where('id', $membership->membership_id)->update(['left_at' => $this->stamp($this->clock->now())]);
            $this->db->table('tm_sprint_memberships')->where('membership_id', $membership->membership_id)->delete(); $this->db->table('tm_active_sprint_tasks')->where('membership_id', $membership->membership_id)->delete();
            $this->db->table('tm_tasks')->where('id', $task->id)->increment('version'); $this->db->table('tm_sprints')->where('id', $sprint['id'])->increment('version'); ScopeGraphLock::changed($this->db, $context);
            $this->event($context, $key, 'sprint_scope_removed', $operation, ['membership_revision' => $version + 1]); return $this->get($context, $key);
        }, fn ($result) => $this->get($context, $result['sprint_key'], true));
    }
    public function start(ExecutionContext $context, string $key, int $version, string $operation): array
    {
        $this->authorize($context);
        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'sprint_start', 'key' => $key, 'version' => $version], function () use ($context, $key, $version, $operation) {
            ScopeGraphLock::acquire($this->db, $context); $sprint = $this->get($context, $key, true, true); $this->version($sprint, $version); if ($sprint['state'] !== 'planned') { throw new DomainError('sprint_state_invalid'); }
            foreach ($this->members($context, $sprint) as $member) { $this->reserve($sprint, $member->id, $member->membership_id); }
            $this->db->table('tm_sprints')->where('id', $sprint['id'])->update(['state' => 'active', 'version' => $version + 1, 'started_at' => $this->stamp($this->clock->now())]);
            $sprint = $this->get($context, $key, true); $this->capture($context, $sprint, 'committed', $operation); $this->event($context, $key, 'sprint_started', $operation, ['membership_revision' => $version + 1]); return $this->get($context, $key);
        }, fn ($result) => $this->get($context, $result['sprint_key'], true));
    }
    public function snapshot(ExecutionContext $context, string $key, int $version, string $operation): array
    {
        $this->authorize($context);
        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'sprint_daily', 'key' => $key, 'version' => $version], function () use ($context, $key, $version, $operation) {
            ScopeGraphLock::acquire($this->db, $context); $sprint = $this->get($context, $key, true, true); $this->version($sprint, $version); if ($sprint['state'] !== 'active') { throw new DomainError('sprint_state_invalid'); }
            return $this->capture($context, $sprint, 'daily', $operation);
        }, fn ($result) => $this->get($context, $key));
    }
    public function close(ExecutionContext $context, string $key, int $version, string $policy, ?string $targetKey, ?int $targetVersion, string $operation): array
    {
        $this->authorize($context); if (!in_array($policy, ['backlog', 'carry', 'cancel'], true) || ($policy === 'carry' && ($targetKey === null || $targetVersion === null)) || ($policy !== 'carry' && ($targetKey !== null || $targetVersion !== null))) { throw new DomainError('sprint_carry_policy_invalid'); }
        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'sprint_close', 'key' => $key, 'version' => $version, 'policy' => $policy, 'target' => $targetKey, 'target_version' => $targetVersion], function () use ($context, $key, $version, $policy, $targetKey, $targetVersion, $operation) {
            ScopeGraphLock::acquire($this->db, $context); $sprint = $this->get($context, $key, true, true); $this->version($sprint, $version);
            if ($sprint['state'] !== 'active' && !($policy === 'cancel' && $sprint['state'] === 'planned')) { throw new DomainError('sprint_state_invalid'); }
            $target = $targetKey === null ? null : $this->get($context, $targetKey, true, true);
            if ($target !== null) { $this->version($target, $targetVersion); $this->open($target); if ($target['id'] === $sprint['id'] || $target['project_id'] !== $sprint['project_id']) { throw new DomainError('sprint_project_mismatch'); } }
            $members = $this->members($context, $sprint); $this->capture($context, $sprint, $policy === 'cancel' ? 'cancelled' : 'closed', $operation);
            $this->db->table('tm_active_sprint_tasks')->where('sprint_id', $sprint['id'])->delete(); $this->db->table('tm_sprint_memberships')->where('sprint_id', $sprint['id'])->delete();
            $this->db->table('tm_sprint_tasks')->where('sprint_id', $sprint['id'])->whereNull('left_at')->update(['left_at' => $this->stamp($this->clock->now())]);
            $this->db->table('tm_sprints')->where('id', $sprint['id'])->update(['state' => $policy === 'cancel' ? 'cancelled' : 'completed', 'closed_at' => $this->stamp($this->clock->now()), 'version' => $version + 1]);
            if ($target !== null) {
                foreach ($members as $member) {
                    if (in_array($member->current_category, ['done', 'cancelled'], true)) { continue; }
                    if (!$this->db->table('tm_sprint_memberships')->where('sprint_id', $target['id'])->where('task_id', $member->id)->exists()) { $this->enrollTask($context, $target, $member, $target['version'] + 1); }
                }
                $this->db->table('tm_sprints')->where('id', $target['id'])->increment('version'); $this->event($context, $target['sprint_key'], 'sprint_scope_carried', $operation, []);
            }
            ScopeGraphLock::changed($this->db, $context); $this->event($context, $key, 'sprint_closed', $operation, ['policy' => $policy]); return $this->get($context, $key);
        }, fn ($result) => $this->get($context, $result['sprint_key'], true));
    }
    public function report(ExecutionContext $context, string $key): array
    {
        $context->require('report.view'); $this->authorization->authorize($context, 'report.view'); $sprint = $this->get($context, $key);
        $snapshots = $this->db->table('tm_sprint_daily_snapshots')->where('sprint_id', $sprint['id'])->orderBy('captured_at')->orderBy('id')->limit(1001)->get(); if ($snapshots->count() > 1000) { throw new DomainError('snapshot_page_required'); }
        $aggregates = $this->db->table('tm_sprint_snapshot_items')->whereIn('snapshot_id', $snapshots->pluck('id')->all())->where('is_leaf', true)
            ->whereIn('task_id', $this->tasks->authorizedQuery($context)->select('id'))->groupBy('snapshot_id')
            ->selectRaw("snapshot_id, COUNT(*) AS leaf_tasks, SUM(CASE WHEN category = 'done' THEN 1 ELSE 0 END) AS done_tasks, COALESCE(SUM(points_minor), 0) AS points_minor, COALESCE(SUM(CASE WHEN category = 'done' THEN points_minor ELSE 0 END), 0) AS done_points_minor, SUM(CASE WHEN points_minor IS NULL THEN 1 ELSE 0 END) AS unknown_points, SUM(CASE WHEN category = 'unknown' THEN 1 ELSE 0 END) AS unknown_states")->get()->keyBy('snapshot_id');
        $history = []; $committed = null; $current = null; $velocity = null;
        foreach ($snapshots as $snapshot) {
            $totals = []; foreach (['leaf_tasks', 'done_tasks', 'points_minor', 'done_points_minor', 'unknown_points', 'unknown_states'] as $field) { $totals[$field] = (int) ($aggregates[$snapshot->id]->{$field} ?? 0); }
            $totals['remaining_points_minor'] = $totals['points_minor'] - $totals['done_points_minor']; $entry = ['snapshot_key' => $snapshot->snapshot_key, 'kind' => $snapshot->kind, 'date' => $snapshot->date_key, 'captured_at' => $snapshot->captured_at, ...$totals];
            $history[] = $entry; if ($snapshot->kind === 'committed') { $committed = $entry; } $current = $entry; if ($snapshot->kind === 'closed') { $velocity = $totals['unknown_points'] > 0 || $totals['unknown_states'] > 0 ? null : $totals['done_points_minor']; }
        }
        $committedId = $snapshots->firstWhere('kind', 'committed')?->id;
        $live = in_array($sprint['state'], ['planned', 'active'], true) ? $this->liveTotals($context, $sprint, $committedId) : null;
        return ['sprint_key' => $key, 'state' => $sprint['state'], 'committed' => $committed, 'current' => $current, 'live_scope' => $live, 'history' => $history,
            'scope_change_points' => $committed === null ? null : PointValue::format(($live ?? $current)['points_minor'] - $committed['points_minor']),
            'historical_scope_change_points' => $current === null || $committed === null ? null : PointValue::format($current['points_minor'] - $committed['points_minor']),
            'velocity' => $velocity === null ? null : PointValue::format($velocity), 'visibility' => 'currently_permitted_tasks'];
    }
    private function liveTotals(ExecutionContext $context, array $sprint, ?int $committedId): array
    {
        $rows = $this->db->table('tm_tasks')->where('tm_tasks.scope_key', $context->scope)->whereIn('tm_tasks.id', $this->tasks->authorizedQuery($context)->select('id'))->where('project_id', $sprint['project_id'])->whereNull('tm_tasks.deleted_at')
            ->join('tm_sprint_memberships as live_membership', fn ($join) => $join->on('live_membership.task_id', '=', 'tm_tasks.id')->where('live_membership.sprint_id', $sprint['id']))
            ->join('tm_sprint_tasks as live_history', 'live_history.id', '=', 'live_membership.membership_id')
            ->select(['tm_tasks.id', 'tm_tasks.type_key', 'live_history.points_minor_at_join'])->addSelect(['current_category' => StateIndex::taskCategoryQuery($this->db, $context->scope)])
            ->orderBy('tm_tasks.id')->limit(1001)->get();
        if ($rows->count() > 1000) { throw new DomainError('sprint_batch_required'); }
        $ids = $rows->pluck('id')->all();
        $parents = $this->db->table('tm_tasks')->where('scope_key', $context->scope)->whereNull('deleted_at')->whereIn('parent_task_id', $ids)->distinct()->pluck('parent_task_id')->all();
        $frozen = $committedId === null ? collect() : $this->db->table('tm_sprint_snapshot_items')->where('snapshot_id', $committedId)->whereIn('task_id', $ids)->get()->keyBy('task_id');
        $totals = ['leaf_tasks' => 0, 'done_tasks' => 0, 'points_minor' => 0, 'done_points_minor' => 0, 'unknown_points' => 0, 'unknown_states' => 0];
        foreach ($rows as $row) {
            if ($row->type_key === 'epic' || in_array($row->id, $parents, true)) { continue; }
            $points = isset($frozen[$row->id]) ? $frozen[$row->id]->points_minor : $row->points_minor_at_join;
            ++$totals['leaf_tasks']; if ($points === null) { ++$totals['unknown_points']; } else { $totals['points_minor'] += (int) $points; }
            if ($row->current_category === null || $row->current_category === 'unknown') { ++$totals['unknown_states']; }
            if ($row->current_category === 'done') { ++$totals['done_tasks']; $totals['done_points_minor'] += (int) ($points ?? 0); }
        }
        $totals['remaining_points_minor'] = $totals['points_minor'] - $totals['done_points_minor']; return $totals;
    }

    public function backlog(ExecutionContext $context, string $projectKey, int $afterId = 0, int $limit = 100): array
    {
        $project = $this->projects->get($context, $projectKey); if ($afterId < 0 || $limit < 1 || $limit > 250) { throw new DomainError('pagination_invalid'); }
        $query = $this->tasks->authorizedQuery($context)->where('project_id', $project['id'])->whereNull('deleted_at')->whereNull('archived_at')->whereNotIn('id', $this->db->table('tm_active_sprint_tasks')->select('task_id'))->whereNotIn('id', StateIndex::currentTaskIds($this->db, $context->scope, ['done', 'cancelled']));
        $total = (clone $query)->count(); $items = $query->where('id', '>', $afterId)->orderBy('id')->limit($limit)->get()->map(fn ($row) => (array) $row)->all(); return ['total' => $total, 'items' => $items, 'next_after_id' => $items ? end($items)['id'] : null];
    }
    private function capture(ExecutionContext $context, array $sprint, string $kind, string $operation): array
    {
        $date = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d'); $existing = $this->db->table('tm_sprint_daily_snapshots')->where('sprint_id', $sprint['id'])->where('kind', $kind)->where('date_key', $date)->first();
        if ($existing) { return ['snapshot_key' => $existing->snapshot_key, 'kind' => $kind, 'date' => $date]; }
        $members = $this->members($context, $sprint); $committedId = $this->db->table('tm_sprint_daily_snapshots')->where('sprint_id', $sprint['id'])->where('kind', 'committed')->value('id');
        $committed = $committedId === null ? collect() : $this->db->table('tm_sprint_snapshot_items')->where('snapshot_id', $committedId)->get()->keyBy('task_id');
        $parents = $this->db->table('tm_tasks')->where('scope_key', $context->scope)->whereIn('parent_task_id', $members->pluck('id')->all())->whereNull('deleted_at')->distinct()->pluck('parent_task_id')->all();
        $key = (string) Str::uuid(); $id = $this->db->table('tm_sprint_daily_snapshots')->insertGetId(['snapshot_key' => $key, 'sprint_id' => $sprint['id'], 'kind' => $kind, 'date_key' => $date, 'captured_at' => $this->stamp($this->clock->now()), 'membership_revision' => $sprint['version']]);
        $items = [];
        foreach ($members as $member) {
            $points = $kind === 'committed' ? $member->story_points_minor : (isset($committed[$member->id]) ? $committed[$member->id]->points_minor : $member->points_minor_at_join);
            $items[] = ['snapshot_id' => $id, 'membership_id' => $member->membership_id, 'task_id' => $member->id, 'task_version' => $member->version, 'category' => $member->current_category ?? 'unknown',
                'is_leaf' => $member->type_key !== 'epic' && !in_array($member->id, $parents, true), 'points_minor' => $points];
        }
        foreach (array_chunk($items, 250) as $chunk) { $this->db->table('tm_sprint_snapshot_items')->insert($chunk); }
        $this->event($context, $sprint['sprint_key'], 'sprint_snapshot_captured', $operation, ['snapshot_key' => $key, 'kind' => $kind]); return ['snapshot_key' => $key, 'kind' => $kind, 'date' => $date];
    }
    private function members(ExecutionContext $context, array $sprint): \Illuminate\Support\Collection
    {
        $memberships = $this->db->table('tm_sprint_memberships')->where('sprint_id', $sprint['id'])->orderBy('task_id')->limit(1001)->get(); if ($memberships->count() > 1000) { throw new DomainError('sprint_batch_required'); }
        $rows = $this->tasks->authorizedQuery($context, 'edit')->where('project_id', $sprint['project_id'])->whereNull('deleted_at')->whereIn('id', $memberships->pluck('task_id')->all())->select('tm_tasks.*')->addSelect(['current_category' => StateIndex::taskCategoryQuery($this->db, $context->scope)])->orderBy('id')->get()->keyBy('id');
        if ($rows->count() !== $memberships->count()) { throw new DomainError('sprint_member_access_denied'); }
        $history = $this->db->table('tm_sprint_tasks')->whereIn('id', $memberships->pluck('membership_id')->all())->get()->keyBy('id');
        foreach ($memberships as $member) { $rows[$member->task_id]->membership_id = $member->membership_id; $rows[$member->task_id]->points_minor_at_join = $history[$member->membership_id]->points_minor_at_join; }
        return $rows->values();
    }
    private function enrollTask(ExecutionContext $context, array $sprint, object $task, int $revision): void
    {
        if ($this->db->table('tm_sprint_memberships')->where('sprint_id', $sprint['id'])->where('task_id', $task->id)->exists()) { throw new DomainError('sprint_member_exists'); }
        $id = $this->db->table('tm_sprint_tasks')->insertGetId(['sprint_id' => $sprint['id'], 'task_id' => $task->id, 'joined_at' => $this->stamp($this->clock->now()), 'points_minor_at_join' => $task->story_points_minor, 'membership_revision' => $revision]);
        $this->db->table('tm_sprint_memberships')->insert(['sprint_id' => $sprint['id'], 'task_id' => $task->id, 'membership_id' => $id]);
        if ($sprint['state'] === 'active') { $this->reserve($sprint, $task->id, $id); }
        $this->db->table('tm_tasks')->where('id', $task->id)->increment('version');
    }
    private function reserve(array $sprint, int $taskId, int $membershipId): void
    {
        if ($this->db->table('tm_active_sprint_tasks')->where('task_id', $taskId)->exists()) { throw new DomainError('task_in_active_sprint'); }
        $this->db->table('tm_active_sprint_tasks')->insert(['task_id' => $taskId, 'sprint_id' => $sprint['id'], 'membership_id' => $membershipId]);
    }
    private function version(array $sprint, int $version): void { if ($sprint['version'] !== $version) { throw new DomainError('version_conflict'); } }
    private function open(array $sprint): void { if (!in_array($sprint['state'], ['planned', 'active'], true)) { throw new DomainError('sprint_state_invalid'); } }
    private function stamp(?\DateTimeImmutable $date): ?string { return $date?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'); }
    private function event(ExecutionContext $context, string $key, string $action, string $operation, array $payload): void { (new EventRecorder($this->db, $this->clock))->append($context, 'sprint', $key, $action, $operation, $payload); }
}
