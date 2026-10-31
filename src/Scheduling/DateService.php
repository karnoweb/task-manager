<?php

namespace Karnoweb\TaskManager\Scheduling;

use Illuminate\Database\Connection;
use Karnoweb\TaskManager\CommandExecutor;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\AuthorizationProvider;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\EventRecorder;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Schema\Tables;
use Karnoweb\TaskManager\TaskGraph\ScopeGraphLock;
use Karnoweb\TaskManager\TaskService;

final class DateService
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly TaskService $tasks)
    {
        Tables::assertConnection($db);
    }

    /** Local wall times are YYYY-MM-DD HH:MM:SS; all-day dates are YYYY-MM-DD, end exclusive. */
    public function change(ExecutionContext $context, string $key, int $version, ?string $start, ?string $end, ?string $due, string $timezone, bool $allDay, string $policy, string $operation): array
    {
        $context->validateTenant();
        $context->require('schedule.update');
        $this->authorization->authorize($context, 'schedule.update');
        LocalTime::zone($timezone);
        if (($start === null) !== ($end === null)) {
            throw new DomainError('schedule_range_incomplete');
        }
        if ($allDay) {
            foreach ([$start, $end] as $date) {
                if ($date !== null) {
                    LocalTime::date($date);
                }
            }
        }
        $a = $start === null ? null : LocalTime::resolve($start.($allDay ? ' 00:00:00' : ''), $timezone, $policy);
        $b = $end === null ? null : LocalTime::resolve($end.($allDay ? ' 00:00:00' : ''), $timezone, $policy);
        $d = $due === null ? null : LocalTime::resolve($due, $timezone, $policy);
        if ($a !== null && $b <= $a) {
            throw new DomainError('schedule_range_invalid');
        }
        $data = ['starts_at' => LocalTime::stamp($a), 'ends_at' => LocalTime::stamp($b), 'due_at' => LocalTime::stamp($d), 'timezone' => $timezone, 'all_day' => $allDay,
            'local_start_date' => $allDay ? $start : null, 'local_end_date' => $allDay ? $end : null];

        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'task_dates', 'key' => $key, 'version' => $version, 'data' => $data, 'policy' => $policy], function () use ($context, $key, $version, $data, $operation) {
            ScopeGraphLock::acquire($this->db, $context);
            $task = $this->tasks->authorizedQuery($context, 'edit')->where('public_key', $key)->whereNull('deleted_at')->whereNull('archived_at')->lockForUpdate()->first();
            if (! $task) {
                throw new DomainError('not_found');
            } if ($task->version !== $version) {
                throw new DomainError('version_conflict');
            }
            $this->db->table(Tables::name('tasks'))->where('id', $task->id)->update([...$data, 'planned_start_at' => $data['starts_at'], 'planned_finish_at' => $data['ends_at'], 'version' => $version + 1, 'date_revision' => $task->date_revision + 1]);
            ReminderSchedule::refresh($this->db, $task, $data);
            (new EventRecorder($this->db, $this->clock))->append($context, 'task', $key, 'task_dates_changed', $operation, $data, $task->id);

            return $this->tasks->get($context, $key);
        }, fn ($result) => $this->tasks->get($context, $result['public_key']));
    }

    public function calendar(ExecutionContext $context, \DateTimeImmutable $from, \DateTimeImmutable $until, int $afterId = 0, int $limit = 100): array
    {
        $context->validateTenant();
        if ($until <= $from || $until > $from->modify('+366 days') || $afterId < 0 || $limit < 1 || $limit > 100) {
            throw new DomainError('calendar_range_invalid');
        }
        $query = $this->tasks->authorizedQuery($context)->whereNull('deleted_at')->whereNull('archived_at')->where(fn ($q) => $q->where(fn ($r) => $r->where('starts_at', '<', LocalTime::stamp($until))->where('ends_at', '>', LocalTime::stamp($from)))
            ->orWhere(fn ($r) => $r->where('due_at', '>=', LocalTime::stamp($from))->where('due_at', '<', LocalTime::stamp($until))));
        $total = (clone $query)->count();
        $rows = $query->where('id', '>', $afterId)->orderBy('id')->limit($limit)->get(['id', 'public_key', 'starts_at', 'ends_at', 'due_at', 'timezone', 'all_day', 'local_start_date', 'local_end_date']);
        $events = [];
        foreach ($rows as $row) {
            if ($row->starts_at !== null && $row->starts_at < LocalTime::stamp($until) && $row->ends_at > LocalTime::stamp($from)) {
                $events[] = ['id' => $row->public_key.':schedule', 'kind' => 'task_schedule', 'task_key' => $row->public_key, 'start' => $row->all_day ? $row->local_start_date : $row->starts_at, 'end' => $row->all_day ? $row->local_end_date : $row->ends_at, 'all_day' => (bool) $row->all_day, 'timezone' => $row->timezone];
            }
            if ($row->due_at !== null && $row->due_at >= LocalTime::stamp($from) && $row->due_at < LocalTime::stamp($until)) {
                $events[] = ['id' => $row->public_key.':due', 'kind' => 'task_due', 'task_key' => $row->public_key, 'at' => $row->due_at, 'timezone' => $row->timezone];
            }
        }

        return ['total_tasks' => $total, 'events' => $events, 'after_id' => $rows->last()?->id];
    }
}
