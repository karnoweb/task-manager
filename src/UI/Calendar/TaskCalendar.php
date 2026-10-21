<?php

namespace Karnoweb\TaskManager\UI\Calendar;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Karnoweb\Calendar\Contracts\ActionHandler;
use Karnoweb\Calendar\Contracts\EventProvider;
use Karnoweb\Calendar\Data\ActionCapabilities;
use Karnoweb\Calendar\Data\ActionCommand;
use Karnoweb\Calendar\Data\ActionResult;
use Karnoweb\Calendar\Data\CalendarContext;
use Karnoweb\Calendar\Data\CalendarEventData;
use Karnoweb\Calendar\Data\DateRange;
use Karnoweb\Calendar\Data\EventPage;
use Karnoweb\Calendar\Data\EventQuery;
use Karnoweb\Calendar\Data\EventReference;
use Karnoweb\Calendar\Enums\ActionStatus;
use Karnoweb\Calendar\Enums\CalendarAction;
use Karnoweb\Calendar\Enums\RecurrenceScope;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Enterprise\TextNormalizer;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Scheduling\DateService;
use Karnoweb\TaskManager\Scheduling\LocalTime;
use Karnoweb\TaskManager\Scheduling\PersianCalendar;
use Karnoweb\TaskManager\Scheduling\RecurrenceService;
use Karnoweb\TaskManager\TaskService;
use Karnoweb\TaskManager\UI\BoundCursor;
use Karnoweb\TaskManager\UI\ContextBridge;
use Karnoweb\TaskManager\UI\Surface;
use Karnoweb\TaskManager\Workflow\InstanceService;
use Karnoweb\TaskManager\Workflow\ProjectService;

/** Optional Calendar 2.x provider; fetching never materializes recurrence tasks. */
final class TaskCalendar implements ActionHandler, EventProvider
{
    public function __construct(private readonly Connection $db, private readonly TaskService $tasks,
        private readonly InstanceService $instances, private readonly ProjectService $projects,
        private readonly DateService $dates, private readonly RecurrenceService $recurrences,
        private readonly Surface $surface, private readonly ContextBridge $bridge, private readonly BoundCursor $cursors)
    {
    }

    private function context(CalendarContext $context): ExecutionContext
    {
        return $this->bridge->resolve($context, $this->surface);
    }

    private function utc(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }

    private function wall(string $value, string $zone, bool $allDay): string
    {
        if ($allDay || ! str_contains($value, 'T')) {
            return $value;
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
            throw new DomainError('calendar_instant_invalid');
        }
        try {
            $instant = new \DateTimeImmutable($value);
        } catch (\Throwable) {
            throw new DomainError('calendar_instant_invalid');
        }
        $local = $instant->setTimezone(LocalTime::zone($zone));
        if ($local->format('Y-m-d\TH:i:s') !== substr($value, 0, 19) || ($instant->getOffset() !== $local->getOffset() && ! str_ends_with($value, 'Z'))) {
            throw new DomainError('calendar_offset_mismatch');
        }

        return $local->format('Y-m-d H:i:s');
    }

    private function sources(EventQuery $q): array
    {
        if (array_diff(array_keys($q->filters), ['sources']) !== []) {
            throw new DomainError('calendar_filter_invalid');
        }
        $sources = $q->filters['sources'] ?? $this->surface->sources;
        if (! is_array($sources) || array_diff($sources, $this->surface->sources)) {
            throw new DomainError('not_found');
        }

        return array_values(array_unique($sources));
    }

    private function base(ExecutionContext $c, string $search): Builder
    {
        $q = $this->tasks->authorizedQuery($c)->whereNull('deleted_at')->whereNull('archived_at');
        if ($search !== '') {
            $q->where('title_search_normalized', 'like', '%'.TextNormalizer::normalize($search).'%');
        }

        return $q;
    }

    private function writable(ExecutionContext $c, array $task): bool
    {
        if (! in_array('schedule.update', $c->capabilities, true)) {
            return false;
        }
        try {
            return $this->tasks->authorizedQuery($c, 'edit')->where('id', $task['id'])->exists();
        } catch (DomainError) {
            return false;
        }
    }

    private function taskEvent(ExecutionContext $c, array $task, string $kind): CalendarEventData
    {
        $occurrence = $this->db->table('tm_recurrence_occurrences')->where('task_id', $task['id'])->where('state', 'active')->first();
        $series = $occurrence ? $this->db->table('tm_recurrences')->where('id', $occurrence->recurrence_id)->first() : null;
        $ref = $occurrence ? new EventReference('tm.recurrence', $series->series_key, $occurrence->occurrence_key) : new EventReference('tm.'.$kind, $task['public_key']);
        $write = ! $occurrence && $this->writable($c, $task);
        if ($kind === 'due') {
            $start = $this->utc($task['due_at']);
            $end = $start->modify('+1 second');
            $allDay = false;
        } else {
            $allDay = (bool) $task['all_day'];
            $start = $this->utc($allDay ? $task['local_start_date'] : $task['starts_at']);
            $end = $this->utc($allDay ? $task['local_end_date'] : $task['ends_at']);
        }

        return new CalendarEventData($ref, $task['title'], $start, $end, $allDay, (string) $task['version'], new ActionCapabilities(canMove: $write, canResize: $write && $kind === 'schedule'), isRecurring: $occurrence !== null, category: $kind);
    }

    private function recurring(ExecutionContext $c, EventQuery $query, ?string $onlySeries = null): array
    {
        $seriesQuery = $this->db->table('tm_recurrences')->where('scope_key', $c->scope)->where('owner_ref', $c->actor)->where('state', 'active')->whereIn('task_id', $this->base($c, $query->search)->select('id'));
        if ($onlySeries !== null) {
            $seriesQuery->where('series_key', $onlySeries);
        }
        $series = $seriesQuery->orderBy('id')->limit(101)->get();
        if ($series->count() > 100) {
            throw new DomainError('calendar_recurrence_source_limit');
        }
        $events = [];
        foreach ($series as $s) {
            $template = json_decode($s->template, true, flags: JSON_THROW_ON_ERROR);
            $span = $template['starts_at'] === null ? 0 : max(1, $this->utc($template['ends_at'])->getTimestamp() - $this->utc($template['starts_at'])->getTimestamp());
            $from = $query->range->startInclusive->modify('-'.$span.' seconds');
            if ($query->range->endExclusive > $from->modify('+366 days')) {
                throw new DomainError('calendar_range_invalid');
            }
            $rows = $this->recurrences->preview($c, $s->series_key, $from, $query->range->endExclusive, 1000);
            if (count($rows) >= 1000) {
                throw new DomainError('calendar_recurrence_range_limit');
            }
            foreach ($rows as $row) {
                $materialized = $this->db->table('tm_recurrence_occurrences')->where('recurrence_id', $s->id)->where('occurrence_key', $row['occurrence_key'])->where('state', 'active')->first();
                if ($materialized !== null) {
                    $task = $this->base($c, $query->search)->where('id', $materialized->task_id)->first();
                    if (! $task) {
                        continue;
                    }
                    $event = $this->taskEvent($c, (array) $task, $task->starts_at === null ? 'due' : 'schedule');
                } else {
                    $start = $this->utc($row['scheduled_at']);
                    $allDay = (bool) $template['all_day'];
                    if ($allDay) {
                        $start = $this->utc($start->setTimezone(LocalTime::zone($s->timezone))->format('Y-m-d'));
                        $days = (new \DateTimeImmutable($template['local_start_date']))->diff(new \DateTimeImmutable($template['local_end_date']))->days;
                        $end = $start->modify('+'.$days.' days');
                    } else {
                        $end = $start->modify('+'.max(1, $span).' seconds');
                    }
                    $event = new CalendarEventData(new EventReference('tm.recurrence', $s->series_key, $row['occurrence_key']), $template['title'], $start, $end, $allDay, (string) $s->version, ActionCapabilities::none(), isRecurring: true, category: 'recurrence');
                }
                if ($event->overlaps($query->range)) {
                    $events[] = $event;
                }
                if (count($events) > 10000) {
                    throw new DomainError('calendar_recurrence_range_limit');
                }
            }
        }

        return $events;
    }

    public function fetch(EventQuery $query, CalendarContext $context): EventPage
    {
        $c = $this->context($context);
        $sources = $this->sources($query);
        if ($query->range->endExclusive > $query->range->startInclusive->modify('+366 days') || $query->limit > 100) {
            throw new DomainError('calendar_range_invalid');
        }
        // Bind offset to the complete current projection so an intervening edit fails explicitly.
        $revision = $this->db->table('tm_outbox')->where('scope_key', $c->scope)->selectRaw('COUNT(*) AS n, MAX(id) AS last_id')->first();
        $binding = [$c->scope, $c->actor, $c->capabilities, $this->surface, $sources, $query->range->signature(), $query->search, $query->limit, (array) $revision];
        $position = $this->cursors->decode($query->cursor, $binding);
        $sourceIndex = $position['source'] ?? 0;
        $after = $position['after'] ?? 0;
        $items = [];
        if (! is_int($sourceIndex) || ! is_int($after) || $sourceIndex < 0 || $after < 0) {
            throw new DomainError('cursor_invalid');
        }
        $from = LocalTime::stamp($query->range->startInclusive);
        $until = LocalTime::stamp($query->range->endExclusive);
        for (; $sourceIndex < count($sources); $sourceIndex++, $after = 0) {
            $source = $sources[$sourceIndex];
            $needed = $query->limit - count($items);
            if ($source === 'tm.recurrence') {
                $events = $this->recurring($c, $query);
                usort($events, fn ($a, $b) => [$a->start->format('Uu'), $a->reference->eventId, $a->reference->occurrenceKey] <=> [$b->start->format('Uu'), $b->reference->eventId, $b->reference->occurrenceKey]);
                $projection = hash('sha256', json_encode(array_map(fn ($e) => [$e->reference, $e->version, $e->start->format('c')], $events), JSON_THROW_ON_ERROR));
                if (isset($position['projection']) && $position['projection'] !== $projection) {
                    throw new DomainError('cursor_invalid');
                }
                foreach (array_slice($events, $after, $needed + 1) as $event) {
                    if (count($items) === $query->limit) {
                        return new EventPage($items, true, $this->cursors->encode($binding, ['source' => $sourceIndex, 'after' => $after, 'projection' => $projection]), null, $query->requestKey);
                    } $items[] = $event;
                    $after++;
                }

                continue;
            }
            if (in_array($source, ['tm.schedule', 'tm.due'], true)) {
                $kind = substr($source, 3);
                $q = $this->base($c, $query->search)->whereNotIn('id', $this->db->table('tm_recurrence_occurrences')->select('task_id'))->whereNotIn('id', $this->db->table('tm_recurrences')->select('task_id'));
                if ($kind === 'schedule') {
                    $q->where('starts_at', '<', $until)->where('ends_at', '>', $from);
                } else {
                    $q->where('due_at', '>=', $from)->where('due_at', '<', $until);
                }
                $rows = $q->where('id', '>', $after)->orderBy('id')->limit($needed + 1)->get();
                foreach ($rows as $row) {
                    if (count($items) === $query->limit) {
                        return new EventPage($items, true, $this->cursors->encode($binding, ['source' => $sourceIndex, 'after' => $after]), null, $query->requestKey);
                    } $items[] = $this->taskEvent($c, (array) $row, $kind);
                    $after = $row->id;
                }
            } elseif ($source === 'tm.sla') {
                $ids = $this->instances->authorizedQuery($c)->select('id');
                $rows = $this->db->table('tm_stage_visits')->where('scope_key', $c->scope)->whereIn('instance_id', $ids)->whereNull('exited_at')->whereNull('paused_at')->where('deadline_at', '>=', $from)->where('deadline_at', '<', $until)->where('id', '>', $after)->orderBy('id')->limit($needed + 1)->get();
                foreach ($rows as $r) {
                    if (count($items) === $query->limit) {
                        return new EventPage($items, true, $this->cursors->encode($binding, ['source' => $sourceIndex, 'after' => $after]), null, $query->requestKey);
                    } $items[] = new CalendarEventData(new EventReference('tm.sla', (string) $r->id), 'SLA '.$r->stage_key, $this->utc($r->deadline_at), $this->utc($r->deadline_at)->modify('+1 second'), version: (string) $r->sla_version, category: 'sla');
                    $after = $r->id;
                }
            } elseif ($source === 'tm.milestones') {
                $projects = $this->projects->authorizedQuery($c)->select('id');
                $rows = $this->db->table('tm_project_markers')->where('scope_key', $c->scope)->whereIn('project_id', $projects)->where('due_at', '>=', $from)->where('due_at', '<', $until)->where('id', '>', $after)->orderBy('id')->limit($needed + 1)->get();
                foreach ($rows as $r) {
                    if (count($items) === $query->limit) {
                        return new EventPage($items, true, $this->cursors->encode($binding, ['source' => $sourceIndex, 'after' => $after]), null, $query->requestKey);
                    } $items[] = new CalendarEventData(new EventReference('tm.milestones', $r->marker_key), $r->name, $this->utc($r->due_at), $this->utc($r->due_at)->modify('+1 second'), version: (string) $r->version, category: $r->kind);
                    $after = $r->id;
                }
            } else {
                throw new DomainError('calendar_source_invalid');
            }
        }

        return new EventPage($items, false, null, null, $query->requestKey);
    }

    public function find(EventReference $reference, CalendarContext $context): ?CalendarEventData
    {
        $c = $this->context($context);
        $this->surface->allows($reference->sourceKey);
        try {
            if (in_array($reference->sourceKey, ['tm.schedule', 'tm.due'], true) && $reference->occurrenceKey === null) {
                $task = $this->tasks->get($c, $reference->eventId);
                if ($task['deleted_at'] !== null || $task['archived_at'] !== null || $task[$reference->sourceKey === 'tm.due' ? 'due_at' : 'starts_at'] === null) {
                    return null;
                }
                if ($this->db->table('tm_recurrence_occurrences')->where('task_id', $task['id'])->exists() || $this->db->table('tm_recurrences')->where('task_id', $task['id'])->exists()) {
                    return null;
                }

                return $this->taskEvent($c, $task, substr($reference->sourceKey, 3));
            }
            if ($reference->sourceKey === 'tm.recurrence' && $reference->occurrenceKey !== null) {
                $s = $this->recurrences->get($c, $reference->eventId);
                $o = $this->db->table('tm_recurrence_occurrences')->where('recurrence_id', $s['id'])->where('occurrence_key', $reference->occurrenceKey)->where('state', 'active')->first();
                if (! $o) {
                    if (! preg_match('/^(gregorian|jalali):(\d{4})-(\d{2})-(\d{2})T(\d{2}:\d{2}:\d{2})$/D', $reference->occurrenceKey, $parts) || $parts[1] !== $s['calendar_system']) {
                        return null;
                    }
                    $day = $parts[1] === 'jalali' ? PersianCalendar::date((int) $parts[2], (int) $parts[3], (int) $parts[4]) : $parts[2].'-'.$parts[3].'-'.$parts[4];
                    $at = LocalTime::resolve($day.' '.$parts[5], $s['timezone'], $s['rule']['dst_policy']);
                    $q = new EventQuery(new DateRange($at->modify('-1 day'), $at->modify('+1 day'), $s['timezone']));
                    foreach ($this->recurring($c, $q, $s['series_key']) as $event) {
                        if ($event->reference->equals($reference)) {
                            return $event;
                        }
                    }

return null;
                }
                $task = $this->base($c, '')->where('id', $o->task_id)->first();

                return $task ? $this->taskEvent($c, (array) $task, $task->starts_at === null ? 'due' : 'schedule') : null;
            }
            if ($reference->sourceKey === 'tm.sla' && ctype_digit($reference->eventId)) {
                $r = $this->db->table('tm_stage_visits')->where('scope_key', $c->scope)->where('id', (int) $reference->eventId)->whereIn('instance_id', $this->instances->authorizedQuery($c)->select('id'))->whereNull('exited_at')->whereNull('paused_at')->first();

                return $r && $r->deadline_at ? new CalendarEventData($reference, 'SLA '.$r->stage_key, $this->utc($r->deadline_at), $this->utc($r->deadline_at)->modify('+1 second'), version: (string) $r->sla_version, category: 'sla') : null;
            }
            if ($reference->sourceKey === 'tm.milestones') {
                $r = $this->db->table('tm_project_markers')->where('scope_key', $c->scope)->where('marker_key', $reference->eventId)->whereIn('project_id', $this->projects->authorizedQuery($c)->select('id'))->first();

                return $r && $r->due_at ? new CalendarEventData($reference, $r->name, $this->utc($r->due_at), $this->utc($r->due_at)->modify('+1 second'), version: (string) $r->version, category: $r->kind) : null;
            }

            return null;
        } catch (DomainError $e) {
            if ($e->getMessage() === 'not_found') {
                return null;
            } throw $e;
        }
    }

    public function execute(ActionCommand $command, CalendarContext $context): ActionResult
    {
        try {
            $c = $this->context($context);
            $this->surface->allows($command->targetSourceKey());
            if (! in_array($command->action, [CalendarAction::Move, CalendarAction::Resize], true)) {
                return ActionResult::failure(ActionStatus::Unsupported);
            }
            $ref = $command->reference;
            if (! $ref || ! in_array($ref->sourceKey, ['tm.schedule', 'tm.due'], true) || $ref->occurrenceKey !== null || ($command->scope !== null && $command->scope !== RecurrenceScope::This)) {
                return ActionResult::failure(ActionStatus::ReadOnly);
            }
            if ($command->expectedVersion === null || ! ctype_digit($command->expectedVersion)) {
                throw new DomainError('expected_version_required');
            }
            $event = $this->find($ref, $context);
            if (! $event) {
                throw new DomainError('not_found');
            }
            $task = $this->tasks->get($c, $ref->eventId);
            $p = $command->payload;
            if (array_diff(array_keys($p), ['start', 'end', 'timezone', 'allDay', 'dst_policy', 'mode']) !== []) {
                throw new DomainError('calendar_payload_invalid');
            }
            foreach (['timezone', 'dst_policy', 'mode'] as $name) {
                if (isset($p[$name]) && ! is_string($p[$name])) {
                    throw new DomainError('calendar_payload_invalid');
                }
            }
            if ($ref->sourceKey === 'tm.due') {
                if ($command->action === CalendarAction::Resize || ! is_string($p['start'] ?? null)) {
                    return ActionResult::failure(ActionStatus::ReadOnly);
                }
                $start = $task['all_day'] ? $task['local_start_date'] : ($task['starts_at'] === null ? null : $this->utc($task['starts_at'])->setTimezone(LocalTime::zone($task['timezone']))->format('Y-m-d H:i:s'));
                $end = $task['all_day'] ? $task['local_end_date'] : ($task['ends_at'] === null ? null : $this->utc($task['ends_at'])->setTimezone(LocalTime::zone($task['timezone']))->format('Y-m-d H:i:s'));
                $due = LocalTime::resolve($this->wall($p['start'], $p['timezone'] ?? $task['timezone'], false), $p['timezone'] ?? $task['timezone'], $p['dst_policy'] ?? 'reject')->setTimezone(LocalTime::zone($task['timezone']))->format('Y-m-d H:i:s');
                $zone = $task['timezone'];
                $allDay = (bool) $task['all_day'];
            } else {
                $start = $p['start'] ?? null;
                $end = $p['end'] ?? null;
                if (! is_string($start) || ! is_string($end)) {
                    throw new DomainError('schedule_range_incomplete');
                }
                $zone = $p['timezone'] ?? $task['timezone'];
                $allDay = $p['allDay'] ?? (bool) $task['all_day'];
                if (! is_bool($allDay)) {
                    throw new DomainError('calendar_payload_invalid');
                }
                $start = $this->wall($start, $zone, $allDay);
                $end = $this->wall($end, $zone, $allDay);
                $due = $task['due_at'] === null ? null : $this->utc($task['due_at'])->setTimezone(LocalTime::zone($zone))->format('Y-m-d H:i:s');
            }
            $result = $this->dates->change($c,$task['public_key'],(int) $command->expectedVersion,$start,$end,$due,$zone,$allDay,$p['dst_policy'] ?? 'reject',$command->operationId);

            return ActionResult::success([$ref],newVersion: (string) $result['version']);
        } catch (DomainError $e) {
            $status = match ($e->getMessage()) {
                'not_found' => ActionStatus::NotFound, 'capability_denied','access_denied' => ActionStatus::Forbidden, 'version_conflict','idempotency_key_conflict' => ActionStatus::Conflict, default => ActionStatus::DomainRejected
            };

            return ActionResult::failure($status,preg_match('/^[a-z][a-z0-9_]{0,79}$/D',$e->getMessage()) ? $e->getMessage() : 'domain_rejected');
        }
    }
}
