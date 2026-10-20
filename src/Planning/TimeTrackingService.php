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
use Karnoweb\TaskManager\TaskService;

final class TimeTrackingService
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly TaskService $tasks) {}
    private function authorize(ExecutionContext $context, string $capability): void { $context->require($capability); $this->authorization->authorize($context, $capability); }
    private function actor(ExecutionContext $context, ?string $actor): string
    {
        $this->authorize($context, 'time.log'); $actor ??= $context->actor;
        if (trim($actor) === '' || strlen($actor) > 120) { throw new DomainError('actor_invalid'); }
        if ($actor !== $context->actor) { $this->authorize($context, 'time.manage_others'); } return $actor;
    }
    private function lockActor(ExecutionContext $context, string $actor): void
    {
        $this->db->table('tm_actor_time_locks')->insertOrIgnore(['scope_key' => $context->scope, 'actor_ref' => $actor]);
        $this->db->table('tm_actor_time_locks')->where('scope_key', $context->scope)->where('actor_ref', $actor)->lockForUpdate()->first();
    }
    public function start(ExecutionContext $context, string $taskKey, string $operation): array
    {
        $actor = $this->actor($context, null);
        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'timer_start', 'task' => $taskKey], function () use ($context, $taskKey, $actor, $operation) {
            $this->lockActor($context, $actor); $task = $this->lockTask($context, $taskKey, true);
            if ($this->db->table('tm_active_timers')->where('scope_key', $context->scope)->where('actor_ref', $actor)->exists()) { throw new DomainError('timer_active'); }
            $key = (string) Str::uuid(); $id = $this->db->table('tm_task_timers')->insertGetId(['timer_key' => $key, 'scope_key' => $context->scope, 'task_id' => $task->id, 'actor_ref' => $actor, 'started_at' => $this->stamp($this->clock->now())]);
            $this->db->table('tm_active_timers')->insert(['scope_key' => $context->scope, 'actor_ref' => $actor, 'timer_id' => $id]); $this->event($context, $task, 'timer_started', $operation, ['timer_key' => $key]);
            return $this->timer($context, $key);
        }, fn ($result) => $this->timer($context, $result['timer_key']));
    }
    public function timer(ExecutionContext $context, string $key): array
    {
        if (!Str::isUuid($key)) { throw new DomainError('not_found'); }
        $query = $this->db->table('tm_task_timers')->where('scope_key', $context->scope)->where('timer_key', $key)->whereIn('task_id', $this->tasks->authorizedQuery($context)->whereNull('deleted_at')->select('id'));
        if (!in_array('time.manage_others', $context->capabilities, true)) { $query->where('actor_ref', $context->actor); } else { $this->authorize($context, 'time.manage_others'); }
        $row = $query->first(); if (!$row) { throw new DomainError('not_found'); } return (array) $row;
    }
    public function cancelOwn(ExecutionContext $context, string $key, int $version, string $operation): array
    {
        $this->authorize($context, 'time.log');
        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'timer_cancel_own', 'key' => $key, 'version' => $version], function () use ($context, $key, $version, $operation) {
            $this->lockActor($context, $context->actor);
            $row = $this->db->table('tm_task_timers')->where('scope_key', $context->scope)->where('actor_ref', $context->actor)->where('timer_key', $key)->lockForUpdate()->first();
            if (!$row) { throw new DomainError('not_found'); } if ($row->version !== $version) { throw new DomainError('version_conflict'); } if ($row->state !== 'active') { throw new DomainError('timer_not_active'); }
            $this->db->table('tm_task_timers')->where('id', $row->id)->update(['state' => 'cancelled', 'version' => $version + 1]); $this->db->table('tm_active_timers')->where('timer_id', $row->id)->delete();
            (new EventRecorder($this->db, $this->clock))->append($context, 'timer', $key, 'timer_cancelled', $operation, ['reason_code' => 'actor_cancelled']); return ['timer_key' => $key, 'state' => 'cancelled'];
        }, function ($result) use ($context) { if (!$this->db->table('tm_task_timers')->where('scope_key', $context->scope)->where('actor_ref', $context->actor)->where('timer_key', $result['timer_key'])->exists()) { throw new DomainError('not_found'); } });
    }
    public function stop(ExecutionContext $context, string $key, int $version, string $operation, string $overlapPolicy = 'reject', ?string $actor = null): array
    {
        $actor = $this->actor($context, $actor); $this->policy($overlapPolicy);
        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'timer_stop', 'key' => $key, 'version' => $version, 'actor' => $actor, 'overlap' => $overlapPolicy], function () use ($context, $key, $version, $actor, $operation, $overlapPolicy) {
            $this->lockActor($context, $actor); $before = $this->timer($context, $key); if ($before['actor_ref'] !== $actor) { throw new DomainError('not_found'); }
            $taskKey = $this->db->table('tm_tasks')->where('id', $before['task_id'])->value('public_key'); $task = $this->lockTask($context, $taskKey);
            $timer = $this->db->table('tm_task_timers')->where('id', $before['id'])->lockForUpdate()->first();
            if ($timer->version !== $version) { throw new DomainError('version_conflict'); } if ($timer->state !== 'active') { throw new DomainError('timer_not_active'); }
            $start = new \DateTimeImmutable($timer->started_at, new \DateTimeZone('UTC')); $end = $this->clock->now(); if ($end < $start) { throw new DomainError('clock_rollback'); }
            $warning = $this->overlap($context, $actor, $start, $end, $overlapPolicy, null, false);
            $entryKey = $this->insert($context, $task, $actor, $start, $end, null, $warning, $timer->id);
            $this->db->table('tm_task_timers')->where('id', $timer->id)->update(['state' => 'stopped', 'ended_at' => $this->stamp($end), 'version' => $version + 1]);
            $this->db->table('tm_active_timers')->where('timer_id', $timer->id)->delete(); $this->event($context, $task, 'timer_stopped', $operation, ['timer_key' => $key, 'entry_key' => $entryKey, 'duration_seconds' => $end->getTimestamp() - $start->getTimestamp()]);
            return $this->entry($context, $entryKey);
        }, fn ($result) => $this->entry($context, $result['public_key']));
    }
    public function log(ExecutionContext $context, string $taskKey, \DateTimeImmutable $start, \DateTimeImmutable $end, ?string $note, string $operation, string $overlapPolicy = 'reject', ?string $actor = null): array
    {
        $actor = $this->actor($context, $actor); $this->range($start, $end, $note); $this->policy($overlapPolicy);
        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'time_log', 'task' => $taskKey, 'actor' => $actor, 'start' => $this->stamp($start), 'end' => $this->stamp($end), 'note' => $note, 'overlap' => $overlapPolicy], function () use ($context, $taskKey, $start, $end, $note, $actor, $overlapPolicy, $operation) {
            $this->lockActor($context, $actor); $task = $this->lockTask($context, $taskKey, true); $warning = $this->overlap($context, $actor, $start, $end, $overlapPolicy);
            $key = $this->insert($context, $task, $actor, $start, $end, $note, $warning); $this->event($context, $task, 'time_logged', $operation, ['entry_key' => $key, 'duration_seconds' => $end->getTimestamp() - $start->getTimestamp(), 'overlap_warning' => $warning]); return $this->entry($context, $key);
        }, fn ($result) => $this->entry($context, $result['public_key']));
    }
    public function amend(ExecutionContext $context, string $key, int $version, \DateTimeImmutable $start, \DateTimeImmutable $end, ?string $note, bool $deleted, string $operation, string $overlapPolicy = 'reject'): array
    {
        $this->range($start, $end, $note); $this->policy($overlapPolicy); $before = $this->entry($context, $key); $actor = $this->actor($context, $before['actor_ref']);
        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'time_amend', 'key' => $key, 'version' => $version, 'start' => $this->stamp($start), 'end' => $this->stamp($end), 'note' => $note, 'deleted' => $deleted, 'overlap' => $overlapPolicy], function () use ($context, $key, $version, $start, $end, $note, $deleted, $actor, $operation, $overlapPolicy) {
            $this->lockActor($context, $actor); $entry = $this->entry($context, $key); $taskKey = $this->db->table('tm_tasks')->where('id', $entry['task_id'])->value('public_key'); $task = $this->lockTask($context, $taskKey);
            if ($entry['version'] !== $version) { throw new DomainError('version_conflict'); } if ($entry['timer_id'] !== null) { throw new DomainError('timer_entry_immutable'); }
            $warning = $deleted ? false : $this->overlap($context, $actor, $start, $end, $overlapPolicy, $entry['id']);
            $this->db->table('tm_task_time_entries')->where('id', $entry['id'])->update(['started_at' => $this->stamp($start), 'ended_at' => $this->stamp($end), 'duration_seconds' => $end->getTimestamp() - $start->getTimestamp(), 'note' => $note, 'deleted_at' => $deleted ? $this->stamp($this->clock->now()) : null, 'version' => $version + 1, 'overlap_warning' => $warning]);
            $this->db->table('tm_tasks')->where('id', $task->id)->increment('time_revision'); $this->event($context, $task, 'time_amended', $operation, ['entry_key' => $key, 'deleted' => $deleted, 'overlap_warning' => $warning]); return $this->entry($context, $key);
        }, fn ($result) => $this->entry($context, $result['public_key']));
    }
    public function entry(ExecutionContext $context, string $key): array
    {
        if (!Str::isUuid($key)) { throw new DomainError('not_found'); }
        $query = $this->db->table('tm_task_time_entries')->where('scope_key', $context->scope)->where('public_key', $key)->whereIn('task_id', $this->tasks->authorizedQuery($context)->whereNull('deleted_at')->select('id'));
        if (!in_array('time.manage_others', $context->capabilities, true)) { $query->where('actor_ref', $context->actor); } else { $this->authorize($context, 'time.manage_others'); }
        $row = $query->first(); if (!$row) { throw new DomainError('not_found'); } return (array) $row;
    }
    public function stats(ExecutionContext $context, string $taskKey, bool $truthFallback = true): array
    {
        $this->authorize($context, 'report.view'); $task = $this->tasks->get($context, $taskKey); $row = $this->db->table('tm_task_stats')->where('task_id', $task['id'])->first();
        $stale = !$row || $row->source_revision !== $task['time_revision'] || $row->source_task_version !== $task['version'];
        if ($stale && !$truthFallback) { return ['state' => 'stale', 'projection_stale' => true, 'spent_seconds' => null, 'remaining_seconds' => null]; }
        if ($stale) { return ['state' => 'from_truth', 'projection_stale' => true, ...$this->totals($task)]; }
        return ['state' => 'projected', 'projection_stale' => false, 'spent_seconds' => $row->spent_seconds, 'remaining_seconds' => $row->remaining_seconds];
    }
    public function rebuild(ExecutionContext $context, string $taskKey, string $operation): array
    {
        $this->authorize($context, 'report.view');
        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'time_projection_rebuild', 'task' => $taskKey], function () use ($context, $taskKey, $operation) {
            $task = $this->lockTask($context, $taskKey); $totals = $this->totals((array) $task);
            $this->db->table('tm_task_stats')->updateOrInsert(['task_id' => $task->id], ['source_revision' => $task->time_revision, 'source_task_version' => $task->version, ...$totals, 'rebuilt_at' => $this->stamp($this->clock->now())]);
            $this->event($context, $task, 'time_projection_rebuilt', $operation, ['source_revision' => $task->time_revision]); return $this->stats($context, $taskKey);
        }, fn ($result) => $this->tasks->get($context, $taskKey));
    }
    private function totals(array $task): array
    {
        $spent = (int) $this->db->table('tm_task_time_entries')->where('scope_key', $task['scope_key'])->where('task_id', $task['id'])->whereNull('deleted_at')->sum('duration_seconds');
        return ['spent_seconds' => $spent, 'remaining_seconds' => $task['estimate_minutes'] === null ? null : max(0, $task['estimate_minutes'] * 60 - $spent)];
    }
    private function insert(ExecutionContext $context, object $task, string $actor, \DateTimeImmutable $start, \DateTimeImmutable $end, ?string $note, bool $warning, ?int $timerId = null): string
    {
        $key = (string) Str::uuid(); $this->db->table('tm_task_time_entries')->insert(['public_key' => $key, 'scope_key' => $context->scope, 'task_id' => $task->id, 'actor_ref' => $actor, 'started_at' => $this->stamp($start), 'ended_at' => $this->stamp($end), 'duration_seconds' => $end->getTimestamp() - $start->getTimestamp(), 'note' => $note, 'timer_id' => $timerId, 'overlap_warning' => $warning]);
        $this->db->table('tm_tasks')->where('id', $task->id)->increment('time_revision'); return $key;
    }
    private function overlap(ExecutionContext $context, string $actor, \DateTimeImmutable $start, \DateTimeImmutable $end, string $policy, ?int $except = null, bool $active = true): bool
    {
        $query = $this->db->table('tm_task_time_entries')->where('scope_key', $context->scope)->where('actor_ref', $actor)->whereNull('deleted_at')->where('started_at', '<', $this->stamp($end))->where('ended_at', '>', $this->stamp($start));
        if ($except !== null) { $query->where('id', '!=', $except); } $overlap = $query->exists();
        if ($active) { $overlap = $overlap || $this->db->table('tm_task_timers')->where('scope_key', $context->scope)->where('actor_ref', $actor)->where('state', 'active')->where('started_at', '<', $this->stamp($end))->exists(); }
        if ($overlap && $policy === 'reject') { throw new DomainError('time_overlap'); } return $overlap;
    }
    private function lockTask(ExecutionContext $context, string $key, bool $active = false): object
    {
        $query = $this->tasks->authorizedQuery($context)->where('public_key', $key)->whereNull('deleted_at'); if ($active) { $query->whereNull('archived_at'); }
        $task = $query->lockForUpdate()->first(); if (!$task) { throw new DomainError('not_found'); } return $task;
    }
    private function range(\DateTimeImmutable $start, \DateTimeImmutable $end, ?string $note): void
    {
        if ($end <= $start || $end > $this->clock->now() || $end->getTimestamp() - $start->getTimestamp() > 31 * 86400 || ($note !== null && mb_strlen($note) > 2000)) { throw new DomainError('time_range_invalid'); }
    }
    private function policy(string $policy): void { if (!in_array($policy, ['reject', 'warning'], true)) { throw new DomainError('time_overlap_policy_invalid'); } }
    private function stamp(\DateTimeImmutable $date): string { return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'); }
    private function event(ExecutionContext $context, object $task, string $action, string $operation, array $payload): void { (new EventRecorder($this->db, $this->clock))->append($context, 'task', $task->public_key, $action, $operation, $payload, $task->id); }
}
