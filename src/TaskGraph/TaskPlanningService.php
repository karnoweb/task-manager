<?php

namespace Karnoweb\TaskManager\TaskGraph;

use Illuminate\Database\Connection;
use Karnoweb\TaskManager\CommandExecutor;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\AuthorizationProvider;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\EventRecorder;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Planning\PointValue;
use Karnoweb\TaskManager\Scheduling\ReminderSchedule;
use Karnoweb\TaskManager\Schema\Tables;
use Karnoweb\TaskManager\SLA\CalendarRegistry;
use Karnoweb\TaskManager\TaskService;
use Karnoweb\TaskManager\Workflow\ProjectService;

final class TaskPlanningService
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization,
        private readonly TaskService $tasks, private readonly ProjectService $projects, private readonly CalendarRegistry $calendars)
    {
    }

    private function authorize(ExecutionContext $context, string $capability): void
    {
        $context->require($capability);
        $this->authorization->authorize($context, $capability);
    }

    public function estimate(ExecutionContext $context, string $key, int $version, ?int $minutes, ?string $points, string $operation): array
    {
        $context->validateTenant();
        $this->authorize($context, 'schedule.update');
        if (($minutes !== null && ($minutes < 0 || $minutes > 5256000)) || ($points !== null && ! preg_match('/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?$/D', $points))) {
            throw new DomainError('estimate_invalid');
        }

        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'task_estimate', 'key' => $key, 'version' => $version, 'minutes' => $minutes, 'points' => $points], function () use ($context, $key, $version, $minutes, $points, $operation) {
            ScopeGraphLock::acquire($this->db, $context);
            $task = $this->tasks->authorizedQuery($context, 'edit')->where('public_key', $key)->lockForUpdate()->first();
            if (! $task) {
                throw new DomainError('not_found');
            }
            if ($task->version !== $version) {
                throw new DomainError('version_conflict');
            }
            $this->db->table(Tables::name('tasks'))->where('id', $task->id)->update(['estimate_minutes' => $minutes, 'story_points' => $points, 'story_points_minor' => PointValue::minor($points), 'version' => $version + 1]);
            ScopeGraphLock::changed($this->db, $context);
            $this->event($context, $key, 'task_estimate_changed', $operation, ['estimate_minutes' => $minutes, 'story_points' => $points], $task->id);

            return $this->tasks->get($context, $key);
        }, fn ($result) => $this->tasks->get($context, $result['public_key']));
    }

    public function previewSchedule(ExecutionContext $context, string $key, ?\DateTimeImmutable $start, ?\DateTimeImmutable $finish): array
    {
        $context->validateTenant();
        $this->authorize($context, 'schedule.update');
        $root = $this->tasks->get($context, $key);
        if ($start !== null && $finish !== null && $finish < $start) {
            throw new DomainError('schedule_range_invalid');
        }
        $ids = [$root['id']];
        $seen = [$root['id'] => true];
        $frontier = $ids;
        while ($frontier) {
            $next = $this->db->table(Tables::name('task_dependencies'))->where('scope_key', $context->scope)->where('blocking', true)->whereNull('deleted_at')->whereIn('predecessor_id', $frontier)->distinct()->pluck('successor_id')->all();
            $frontier = [];
            foreach ($next as $id) {
                if (! isset($seen[$id])) {
                    $ids[] = $id;
                    $seen[$id] = true;
                    $frontier[] = $id;
                }
            }
            if (count($ids) > 1000) {
                throw new DomainError('schedule_batch_required');
            }
        }
        $rows = $this->tasks->authorizedQuery($context, 'edit')->whereIn('id', $ids)->whereNull('deleted_at')->whereNull('archived_at')->orderBy('id')->get()->keyBy('id');
        if ($rows->count() !== count($ids)) {
            throw new DomainError('dependency_scope_access_denied');
        }
        $links = $this->db->table(Tables::name('task_dependencies'))->where('scope_key', $context->scope)->where('blocking', true)->whereNull('deleted_at')->whereIn('successor_id', $ids)->orderBy('id')->limit(10001)->get();
        if ($links->count() > 10000) {
            throw new DomainError('dependency_graph_limit');
        }
        $externalIds = array_values(array_diff($links->pluck('predecessor_id')->unique()->all(), $ids));
        $external = $this->tasks->authorizedQuery($context)->whereIn('id', $externalIds)->whereNull('deleted_at')->orderBy('id')->get()->keyBy('id');
        if ($external->count() !== count($externalIds)) {
            throw new DomainError('dependency_scope_access_denied');
        }
        $all = $rows->all() + $external->all();
        $dates = [];
        $snapshots = [];
        $externalVersions = [];
        foreach ($all as $id => $row) {
            $dates[$id] = ['start' => $this->date($row->planned_start_at), 'finish' => $this->date($row->planned_finish_at)];
        }
        $dates[$root['id']] = ['start' => $start?->setTimezone(new \DateTimeZone('UTC')), 'finish' => $finish?->setTimezone(new \DateTimeZone('UTC'))];
        $ordered = $this->topological($ids, $links->all());
        $warnings = [];
        foreach ($ordered as $id) {
            $oldStart = $dates[$id]['start'];
            $oldFinish = $dates[$id]['finish'];
            $duration = $oldStart !== null && $oldFinish !== null ? $oldFinish->getTimestamp() - $oldStart->getTimestamp() : ($rows[$id]->estimate_minutes === null ? null : $rows[$id]->estimate_minutes * 60);
            foreach ($links->where('successor_id', $id) as $link) {
                $anchor = $dates[$link->predecessor_id][in_array($link->type, ['FS', 'FF'], true) ? 'finish' : 'start'];
                if ($anchor === null) {
                    if ($link->enforcement === 'strict') {
                        throw new DomainError('dependency_date_missing');
                    }
                    $warnings[] = ['task_key' => $rows[$id]->public_key, 'reason_code' => 'dependency_date_missing'];

                    continue;
                }
                $bound = CalendarLag::shift($anchor, $link->lag_minutes, $link->clock, $link->calendar_ref, $this->calendars);
                $field = in_array($link->type, ['FS', 'SS'], true) ? 'start' : 'finish';
                $current = $dates[$id][$field];
                if ($current !== null && $current >= $bound) {
                    continue;
                }
                if ($link->enforcement === 'warning') {
                    $warnings[] = ['task_key' => $rows[$id]->public_key, 'reason_code' => 'dependency_planned_violation'];

                    continue;
                }
                if ($id === $root['id']) {
                    throw new DomainError('dependency_planned_violation');
                }
                $dates[$id][$field] = $bound;
                if ($duration !== null) {
                    $dates[$id][$field === 'start' ? 'finish' : 'start'] = $bound->modify(($field === 'start' ? '+' : '-').$duration.' seconds');
                }
            }
            if ($dates[$id]['start'] !== null && $dates[$id]['finish'] !== null && $dates[$id]['finish'] < $dates[$id]['start']) {
                throw new DomainError('schedule_range_invalid');
            }
        }
        foreach ($rows as $id => $row) {
            $snapshots[] = ['task_key' => $row->public_key, 'version' => $row->version, 'before_start' => $this->stamp($this->date($row->planned_start_at)), 'before_finish' => $this->stamp($this->date($row->planned_finish_at)), 'start' => $this->stamp($dates[$id]['start']), 'finish' => $this->stamp($dates[$id]['finish'])];
        }
        foreach ($external as $row) {
            $externalVersions[] = ['task_key' => $row->public_key, 'version' => $row->version];
        }

        return ['task_key' => $key, 'scope' => $context->scope, 'actor' => $context->actor,
            'start' => $this->stamp($start), 'finish' => $this->stamp($finish), 'items' => $snapshots, 'external_versions' => $externalVersions,
            'link_versions' => $links->map(fn ($row) => ['id' => $row->id, 'version' => $row->version])->all(), 'warnings' => $warnings];
    }

    public function executeSchedule(ExecutionContext $context, array $preview, string $operation): array
    {
        $context->validateTenant();
        $this->authorize($context, 'schedule.update');
        if (! is_string($preview['task_key'] ?? null) || ! array_key_exists('start', $preview) || ! array_key_exists('finish', $preview)) {
            throw new DomainError('preview_invalid');
        }

        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'schedule_execute', 'preview' => $preview], function () use ($context, $preview, $operation) {
            ScopeGraphLock::acquire($this->db, $context);
            $fresh = $this->previewSchedule($context, $preview['task_key'], $this->date($preview['start']), $this->date($preview['finish']));
            if (PreviewValue::ordered($fresh) !== PreviewValue::ordered($preview)) {
                throw new DomainError('preview_stale');
            }
            $changed = 0;
            foreach ($fresh['items'] as $item) {
                if ($item['before_start'] === $item['start'] && $item['before_finish'] === $item['finish']) {
                    continue;
                }
                $task = $this->tasks->authorizedQuery($context, 'edit')->where('public_key', $item['task_key'])->lockForUpdate()->first();
                if (! $task || $task->version !== $item['version']) {
                    throw new DomainError('preview_stale');
                }
                $calendar = ['starts_at' => null, 'ends_at' => null, 'all_day' => false, 'local_start_date' => null, 'local_end_date' => null, 'due_at' => $task->due_at];
                if ($item['start'] !== null && $item['finish'] !== null && $item['finish'] > $item['start']) {
                    $calendar['starts_at'] = $item['start'];
                    $calendar['ends_at'] = $item['finish'];
                    $a = (new \DateTimeImmutable($item['start'], new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone($task->timezone));
                    $b = (new \DateTimeImmutable($item['finish'], new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone($task->timezone));
                    if ($task->all_day && $a->format('H:i:s') === '00:00:00' && $b->format('H:i:s') === '00:00:00') {
                        $calendar['all_day'] = true;
                        $calendar['local_start_date'] = $a->format('Y-m-d');
                        $calendar['local_end_date'] = $b->format('Y-m-d');
                    }
                }
                $this->db->table(Tables::name('tasks'))->where('id', $task->id)->update([...$calendar, 'planned_start_at' => $item['start'], 'planned_finish_at' => $item['finish'], 'version' => $task->version + 1, 'date_revision' => $task->date_revision + 1]);
                ReminderSchedule::refresh($this->db, $task, $calendar);
                $changed++;
                $this->event($context, $task->public_key, 'task_schedule_changed', $operation, ['start' => $item['start'], 'finish' => $item['finish']], $task->id);
            }
            ScopeGraphLock::changed($this->db, $context);

            return ['task_key' => $preview['task_key'], 'changed' => $changed, 'warnings' => $fresh['warnings']];
        }, function ($result) use ($context, $preview) {
            foreach ($preview['items'] as $item) {
                $this->tasks->get($context, $item['task_key']);
            }
        });
    }

    public function criticalPath(ExecutionContext $context, string $projectKey, ?\DateTimeImmutable $anchor = null): array
    {
        $context->validateTenant();
        $this->authorize($context, 'report.view');
        $project = $this->projects->get($context, $projectKey);
        $rows = $this->tasks->authorizedQuery($context)->where('project_id', $project['id'])->whereNull('deleted_at')->orderBy('id')->limit(1001)->get()->keyBy('id');
        if ($rows->count() > 1000) {
            throw new DomainError('report_batch_required');
        }
        if ($rows->isEmpty() || $rows->contains(fn ($row) => $row->estimate_minutes === null)) {
            return ['state' => 'insufficient_data', 'reason' => 'estimates_missing'];
        }
        $ids = $rows->keys()->all();
        $links = $this->db->table(Tables::name('task_dependencies'))->where('scope_key', $context->scope)->where('blocking', true)->whereNull('deleted_at')->where(fn ($q) => $q->whereIn('predecessor_id', $ids)->orWhereIn('successor_id', $ids))->orderBy('id')->limit(10001)->get();
        if ($links->count() > 10000) {
            throw new DomainError('dependency_graph_limit');
        }
        foreach ($links as $link) {
            if (! isset($rows[$link->predecessor_id], $rows[$link->successor_id])) {
                return ['state' => 'insufficient_data', 'reason' => 'external_or_hidden_dependency'];
            }
        }
        $anchor ??= $this->date($rows->pluck('planned_start_at')->filter()->min());
        if ($anchor === null) {
            return ['state' => 'insufficient_data', 'reason' => 'anchor_missing'];
        }
        $starts = [];
        $finishes = [];
        $previous = [];
        foreach ($this->topological($ids, $links->all()) as $id) {
            $start = $anchor;
            $duration = $rows[$id]->estimate_minutes * 60;
            $previous[$id] = null;
            foreach ($links->where('successor_id', $id) as $link) {
                $predAnchor = in_array($link->type, ['FS', 'FF'], true) ? $finishes[$link->predecessor_id] : $starts[$link->predecessor_id];
                $required = CalendarLag::shift($predAnchor, $link->lag_minutes, $link->clock, $link->calendar_ref, $this->calendars);
                if (in_array($link->type, ['FF', 'SF'], true)) {
                    $required = $required->modify('-'.$duration.' seconds');
                }
                if ($required > $start) {
                    $start = $required;
                    $previous[$id] = $link->predecessor_id;
                }
            }
            $starts[$id] = $start;
            $finishes[$id] = $start->modify('+'.$duration.' seconds');
        }
        $last = $ids[0];
        foreach ($ids as $id) {
            if ($finishes[$id] > $finishes[$last]) {
                $last = $id;
            }
        }
        $path = [];
        $walk = $last;
        while ($walk !== null) {
            array_unshift($path, $rows[$walk]->public_key);
            $walk = $previous[$walk];
        }

        return ['state' => 'calculated', 'path' => $path, 'start' => $this->stamp($anchor), 'finish' => $this->stamp($finishes[$last]), 'duration_minutes' => ($finishes[$last]->getTimestamp() - $anchor->getTimestamp()) / 60,
            'calculation' => 'elapsed_estimates_with_versioned_dependency_lags', 'visibility' => 'permitted_subgraph'];
    }

    private function topological(array $ids, array $links): array
    {
        $incoming = array_fill_keys($ids, 0);
        $outgoing = [];
        foreach ($links as $link) {
            if (isset($incoming[$link->predecessor_id], $incoming[$link->successor_id])) {
                $incoming[$link->successor_id]++;
                $outgoing[$link->predecessor_id][] = $link->successor_id;
            }
        }
        $queue = [];
        foreach ($incoming as $id => $count) {
            if ($count === 0) {
                $queue[] = $id;
            }
        } sort($queue);
        $result = [];
        while ($queue) {
            $id = array_shift($queue);
            $result[] = $id;
            foreach ($outgoing[$id] ?? [] as $child) {
                if (--$incoming[$child] === 0) {
                    $queue[] = $child;
                }
            }
        }
        if (count($result) !== count($ids)) {
            throw new DomainError('dependency_cycle');
        }

        return $result;
    }

    private function date(?string $value): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            throw new DomainError('schedule_date_invalid');
        }
    }

    private function stamp(?\DateTimeImmutable $date): ?string
    {
        return $date?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function event(ExecutionContext $context, string $key, string $action, string $operation, array $payload, int $id): void
    {
        (new EventRecorder($this->db, $this->clock))->append($context, 'task', $key, $action, $operation, $payload, $id);
    }
}
