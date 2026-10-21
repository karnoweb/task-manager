<?php

namespace Karnoweb\TaskManager\Scheduling;

use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Karnoweb\TaskManager\CommandExecutor;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\AuthorizationProvider;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\EventRecorder;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\SLA\CalendarRegistry;
use Karnoweb\TaskManager\TaskGraph\ScopeGraphLock;
use Karnoweb\TaskManager\TaskService;
use Karnoweb\TaskManager\Workflow\ProjectService;
use Karnoweb\TaskManager\Workflow\StateIndex;
use Karnoweb\TaskManager\Workflow\WorkflowService;

final class RecurrenceService
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly TaskService $tasks,
        private readonly DateService $dates, private readonly ProjectService $projects, private readonly WorkflowService $workflows, private readonly CalendarRegistry $calendars)
    {
    }

    private function authorize(ExecutionContext $context): void
    {
        $context->require('recurrence.manage');
        $this->authorization->authorize($context, 'recurrence.manage');
    }

    public function get(ExecutionContext $context, string $key, bool $write = false): array
    {
        if (! Str::isUuid($key)) {
            throw new DomainError('not_found');
        }
        $row = $this->db->table('tm_recurrences')->where('scope_key', $context->scope)->where('series_key', $key)->where('owner_ref', $context->actor)->first();
        if (! $row || ! $this->tasks->authorizedQuery($context, $write ? 'edit' : 'view')->where('id', $row->task_id)->whereNull('deleted_at')->exists()) {
            throw new DomainError('not_found');
        }
        if ($write) {
            $this->authorize($context);
        }

        return [...(array) $row, 'rule' => json_decode($row->rule, true, flags: JSON_THROW_ON_ERROR), 'template' => json_decode($row->template, true, flags: JSON_THROW_ON_ERROR)];
    }

    public function create(ExecutionContext $context, string $taskKey, int $taskVersion, string $system, string $anchor, string $timezone, array|string $input, string $operation): array
    {
        $this->authorize($context);
        $rule = RecurrenceRule::parse($input);
        if (! in_array($system, ['gregorian', 'jalali'], true)) {
            throw new DomainError('calendar_system_invalid');
        }
        LocalTime::resolve($anchor, $timezone, $rule['dst_policy']);
        if ($system === 'jalali') {
            PersianCalendar::parts(substr($anchor, 0, 10));
        } if ($rule['calendar_ref'] !== null) {
            $this->calendars->get($rule['calendar_ref']);
        }

        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'recurrence_create', 'task' => $taskKey, 'version' => $taskVersion, 'system' => $system, 'anchor' => $anchor, 'timezone' => $timezone, 'rule' => $rule], function () use ($context, $taskKey, $taskVersion, $system, $anchor, $timezone, $rule, $operation) {
            ScopeGraphLock::acquire($this->db, $context);
            $task = $this->tasks->authorizedQuery($context, 'edit')->where('public_key', $taskKey)->whereNull('deleted_at')->whereNull('archived_at')->lockForUpdate()->first();
            if (! $task) {
                throw new DomainError('not_found');
            } if ($task->version !== $taskVersion) {
                throw new DomainError('version_conflict');
            }
            if ($this->db->table('tm_recurrences')->where('task_id', $task->id)->exists()) {
                throw new DomainError('recurrence_exists');
            }
            $revision = $this->db->table('tm_workflow_instances')->where('task_id', $task->id)->where('slot_key', 'main')->orderByDesc('id')->value('workflow_revision_id');
            $template = ['title' => $task->title, 'description' => $task->description, 'priority' => $task->priority, 'project_id' => $task->project_id, 'type' => $task->type_key, 'workflow_revision_id' => $revision,
                'starts_at' => $task->starts_at, 'ends_at' => $task->ends_at, 'due_at' => $task->due_at, 'all_day' => (bool) $task->all_day, 'local_start_date' => $task->local_start_date, 'local_end_date' => $task->local_end_date];
            $key = (string) Str::uuid();
            $this->db->table('tm_recurrences')->insert(['series_key' => $key, 'scope_key' => $context->scope, 'owner_ref' => $context->actor, 'task_id' => $task->id, 'calendar_system' => $system, 'timezone' => $timezone, 'local_anchor' => $anchor, 'rule' => json_encode($rule, JSON_THROW_ON_ERROR), 'template' => json_encode($template, JSON_THROW_ON_ERROR)]);
            (new EventRecorder($this->db, $this->clock))->append($context, 'task', $taskKey, 'recurrence_created', $operation, ['series_key' => $key], $task->id);

            return $this->get($context, $key);
        }, fn ($result) => $this->get($context, $result['series_key']));
    }

    public function preview(ExecutionContext $context, string $key, \DateTimeImmutable $from, \DateTimeImmutable $until, int $limit = 100): array
    {
        $series = $this->get($context, $key);
        if ($series['state'] !== 'active') {
            return [];
        }
        $items = RecurrenceRule::expand($series['calendar_system'], $series['local_anchor'], $series['timezone'], $series['rule'], $from, $until, $this->calendars, $limit);
        $exceptions = $this->db->table('tm_recurrence_exceptions')->where('recurrence_id', $series['id'])->whereIn('occurrence_key', array_column($items, 'occurrence_key'))->get()->keyBy('occurrence_key');
        $result = [];
        foreach ($items as $item) {
            $exception = $exceptions[$item['occurrence_key']] ?? null;
            if ($exception?->cancelled) {
                continue;
            } if ($exception?->override_at !== null) {
                $item['scheduled_at'] = $exception->override_at;
                $item['local_at'] = (new \DateTimeImmutable($exception->override_at, new \DateTimeZone('UTC')))->setTimezone(LocalTime::zone($series['timezone']))->format('Y-m-d H:i:s');
            } $result[] = $item;
        }
        $extra = $this->db->table('tm_recurrence_exceptions')->where('recurrence_id', $series['id'])->where('cancelled', false)->where('override_at', '>=', LocalTime::stamp($from))->where('override_at', '<', LocalTime::stamp($until))
            ->whereIn('occurrence_key', $this->db->table('tm_recurrence_occurrences')->where('recurrence_id', $series['id'])->where('state', 'active')->select('occurrence_key'))->limit(1001)->get();
        if ($extra->count() > 1000) {
            throw new DomainError('recurrence_exception_page_required');
        }
        $byKey = [];
        foreach ($result as $item) {
            if ($item['scheduled_at'] >= LocalTime::stamp($from) && $item['scheduled_at'] < LocalTime::stamp($until)) {
                $byKey[$item['occurrence_key']] = $item;
            }
        }
        foreach ($extra as $exception) {
            $byKey[$exception->occurrence_key] = ['occurrence_key' => $exception->occurrence_key, 'scheduled_at' => $exception->override_at, 'local_at' => (new \DateTimeImmutable($exception->override_at, new \DateTimeZone('UTC')))->setTimezone(LocalTime::zone($series['timezone']))->format('Y-m-d H:i:s')];
        }
        $result = array_values($byKey);
        usort($result, fn ($a, $b) => [$a['scheduled_at'], $a['occurrence_key']] <=> [$b['scheduled_at'], $b['occurrence_key']]);

        return array_slice($result, 0, $limit);
    }

    public function generate(ExecutionContext $context, string $key, int $version, \DateTimeImmutable $from, \DateTimeImmutable $until, string $operation, int $limit = 100): array
    {
        $this->authorize($context);

        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'recurrence_generate', 'key' => $key, 'version' => $version, 'from' => LocalTime::stamp($from), 'until' => LocalTime::stamp($until), 'limit' => $limit], function () use ($context, $key, $version, $from, $until, $operation, $limit) {
            ScopeGraphLock::acquire($this->db, $context);
            $series = $this->get($context, $key, true);
            if ($series['version'] !== $version) {
                throw new DomainError('version_conflict');
            }
            if ($series['state'] !== 'active') {
                return ['series_key' => $key, 'created' => []];
            }
            if ($series['rule']['after_completion']) {
                $last = $this->db->table('tm_recurrence_occurrences')->where('recurrence_id', $series['id'])->where('state', 'active')->orderByDesc('scheduled_at')->first();
                if (! StateIndex::currentTaskIds($this->db, $context->scope, ['done'])->where('task_id', $last?->task_id ?? $series['task_id'])->exists()) {
                    return ['series_key' => $key, 'created' => []];
                }
                if ($last !== null) {
                    $from = max($from, (new \DateTimeImmutable($last->scheduled_at, new \DateTimeZone('UTC')))->modify('+1 second'));
                }
                $limit = 1;
            }
            $created = [];
            foreach ($this->preview($context, $key, $from, $until, $limit) as $item) {
                $prior = $this->db->table('tm_recurrence_occurrences')->where('recurrence_id', $series['id'])->where('occurrence_key', $item['occurrence_key'])->lockForUpdate()->first();
                if ($prior !== null && $prior->state === 'active') {
                    continue;
                }
                $child = 'occurrence:'.hash('sha256', $operation.':'.$item['occurrence_key']);
                if ($prior !== null) {
                    $public = $this->db->table('tm_tasks')->where('id', $prior->task_id)->value('public_key');
                    $task = $this->tasks->get($context, $public);
                    if ($task['deleted_at'] !== null) {
                        $task = $this->tasks->mutate($context, $public, $task['version'], $child.':restore', 'undelete');
                    }
                } else {
                    $task = $this->tasks->create($context, $series['template']['title'], $child.':create');
                    $task = $this->tasks->mutate($context, $task['public_key'], $task['version'], $child.':defaults', 'update', ['description' => $series['template']['description'], 'priority' => $series['template']['priority']]);
                    if ($series['template']['project_id'] !== null) {
                        $projectKey = $this->db->table('tm_projects')->where('id', $series['template']['project_id'])->value('project_key');
                        $revision = $this->workflows->revision($context, $series['template']['workflow_revision_id']);
                        $preview = $this->projects->previewTaskMove($context, $task['public_key'], $projectKey, $series['template']['type'], [$task['state'] => $revision['definition']['initial']]);
                        if ($preview['revision_id'] !== $revision['id']) {
                            throw new DomainError('recurrence_workflow_changed');
                        } $task = $this->projects->moveTask($context, $preview, $child.':project');
                    }
                }
                $task = $this->scheduleOccurrence($context, $series, $task, $item, $child.':dates');
                if ($prior) {
                    $this->db->table('tm_recurrence_occurrences')->where('id', $prior->id)->update(['rule_revision' => $version, 'scheduled_at' => $item['scheduled_at'], 'state' => 'active']);
                } else {
                    $this->db->table('tm_recurrence_occurrences')->insert(['recurrence_id' => $series['id'], 'occurrence_key' => $item['occurrence_key'], 'rule_revision' => $version, 'task_id' => $task['id'], 'scheduled_at' => $item['scheduled_at'], 'created_at' => LocalTime::stamp($this->clock->now())]);
                }
                $created[] = $task['public_key'];
            }

            return ['series_key' => $key, 'created' => $created];
        }, function ($result) use ($context) {
            $this->get($context, $result['series_key'], true);
            foreach ($result['created'] as $task) {
                $this->tasks->get($context, $task);
            }
        });
    }

    private function scheduleOccurrence(ExecutionContext $context, array $series, array $task, array $item, string $operation): array
    {
        $template = $series['template'];
        $at = new \DateTimeImmutable($item['scheduled_at'], new \DateTimeZone('UTC'));
        $zone = LocalTime::zone($series['timezone']);
        $start = null;
        $end = null;
        if ($template['starts_at'] !== null) {
            if ($template['all_day']) {
                $start = $at->setTimezone($zone)->format('Y-m-d');
                $days = (new \DateTimeImmutable($template['local_start_date']))->diff(new \DateTimeImmutable($template['local_end_date']))->days;
                $end = (new \DateTimeImmutable($start))->modify('+'.$days.' days')->format('Y-m-d');
            } else {
                $seconds = (new \DateTimeImmutable($template['ends_at'], new \DateTimeZone('UTC')))->getTimestamp() - (new \DateTimeImmutable($template['starts_at'], new \DateTimeZone('UTC')))->getTimestamp();
                $start = $at->setTimezone($zone)->format('Y-m-d H:i:s');
                $end = $at->modify('+'.$seconds.' seconds')->setTimezone($zone)->format('Y-m-d H:i:s');
            }
        }
        $offset = $template['due_at'] !== null && $template['starts_at'] !== null ? (new \DateTimeImmutable($template['due_at'], new \DateTimeZone('UTC')))->getTimestamp() - (new \DateTimeImmutable($template['starts_at'], new \DateTimeZone('UTC')))->getTimestamp() : 0;
        $due = $at->modify(($offset >= 0 ? '+' : '').$offset.' seconds')->setTimezone($zone)->format('Y-m-d H:i:s');

        return $this->dates->change($context, $task['public_key'], $task['version'], $start, $end, $due, $series['timezone'], $template['all_day'], $series['rule']['dst_policy'], $operation);
    }

    public function changeRule(ExecutionContext $context, string $key, int $version, array|string $input, string $operation, bool $stop = false): array
    {
        $this->authorize($context);
        $rule = RecurrenceRule::parse($input);
        if ($rule['calendar_ref'] !== null) {
            $this->calendars->get($rule['calendar_ref']);
        }

        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'recurrence_change', 'key' => $key, 'version' => $version, 'rule' => $rule, 'stop' => $stop], function () use ($context, $key, $version, $rule, $operation, $stop) {
            ScopeGraphLock::acquire($this->db, $context);
            $series = $this->get($context, $key, true);
            if ($series['version'] !== $version) {
                throw new DomainError('version_conflict');
            }
            $future = $this->db->table('tm_recurrence_occurrences')->where('recurrence_id', $series['id'])->where('state', 'active')->where('scheduled_at', '>=', LocalTime::stamp($this->clock->now()))
                ->whereNotIn('task_id', StateIndex::currentTaskIds($this->db, $context->scope, ['done', 'cancelled']))->orderBy('id')->limit(1001)->get();
            if ($future->count() > 1000) {
                throw new DomainError('recurrence_update_batch_required');
            }
            foreach ($future as $item) {
                $taskKey = $this->db->table('tm_tasks')->where('id', $item->task_id)->value('public_key');
                $task = $this->tasks->get($context, $taskKey);
                $this->tasks->mutate($context, $taskKey, $task['version'], 'series-invalidate:'.hash('sha256', $operation.':'.$item->id), 'delete');
                $this->db->table('tm_recurrence_occurrences')->where('id', $item->id)->update(['state' => 'invalidated']);
            }
            $this->db->table('tm_recurrences')->where('id', $series['id'])->update(['rule' => json_encode($rule, JSON_THROW_ON_ERROR), 'version' => $version + 1, 'state' => $stop ? 'stopped' : 'active']);
            $taskKey = $this->db->table('tm_tasks')->where('id', $series['task_id'])->value('public_key');
            (new EventRecorder($this->db, $this->clock))->append($context, 'task', $taskKey, 'recurrence_rule_changed', $operation, ['series_key' => $key, 'version' => $version + 1, 'stopped' => $stop], $series['task_id']);

            return $this->get($context, $key);
        }, fn ($result) => $this->get($context, $result['series_key'], true));
    }

    public function exception(ExecutionContext $context, string $key, int $version, string $occurrenceKey, int $taskVersion, ?\DateTimeImmutable $override, bool $cancel, string $operation): array
    {
        $this->authorize($context);

        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'recurrence_exception', 'key' => $key, 'version' => $version, 'occurrence' => $occurrenceKey, 'task_version' => $taskVersion, 'override' => LocalTime::stamp($override), 'cancel' => $cancel], function () use ($context, $key, $version, $occurrenceKey, $taskVersion, $override, $cancel, $operation) {
            ScopeGraphLock::acquire($this->db, $context);
            $series = $this->get($context, $key, true);
            if ($series['version'] !== $version) {
                throw new DomainError('version_conflict');
            }
            $row = $this->db->table('tm_recurrence_occurrences')->where('recurrence_id', $series['id'])->where('occurrence_key', $occurrenceKey)->where('state', 'active')->first();
            if (! $row) {
                throw new DomainError('not_found');
            }
            if (StateIndex::currentTaskIds($this->db, $context->scope, ['done', 'cancelled'])->where('task_id', $row->task_id)->exists()) {
                throw new DomainError('occurrence_completed');
            }
            $taskKey = $this->db->table('tm_tasks')->where('id', $row->task_id)->value('public_key');
            $task = $this->tasks->get($context, $taskKey);
            if ($task['version'] !== $taskVersion) {
                throw new DomainError('version_conflict');
            }
            if ($cancel) {
                $this->tasks->mutate($context, $taskKey, $taskVersion, 'exception:'.hash('sha256', $operation), 'delete');
            } elseif ($override !== null) {
                $this->scheduleOccurrence($context, $series, $task, ['scheduled_at' => LocalTime::stamp($override)], 'exception:'.hash('sha256', $operation));
            } else {
                throw new DomainError('exception_invalid');
            }
            $prior = $this->db->table('tm_recurrence_exceptions')->where('recurrence_id', $series['id'])->where('occurrence_key', $occurrenceKey)->first();
            $data = ['cancelled' => $cancel, 'override_at' => LocalTime::stamp($override), 'version' => ($prior->version ?? 0) + 1];
            $this->db->table('tm_recurrence_exceptions')->updateOrInsert(['recurrence_id' => $series['id'], 'occurrence_key' => $occurrenceKey], $data);
            $this->db->table('tm_recurrences')->where('id', $series['id'])->increment('version');

            return ['series_key' => $key, 'version' => $version + 1, 'occurrence_key' => $occurrenceKey, 'cancelled' => $cancel];
        }, fn ($result) => $this->get($context, $result['series_key'], true));
    }
}
