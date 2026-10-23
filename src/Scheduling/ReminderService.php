<?php

namespace Karnoweb\TaskManager\Scheduling;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use Karnoweb\TaskManager\CommandExecutor;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\AuthorizationProvider;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\Contracts\NotificationPreferences;
use Karnoweb\TaskManager\Contracts\ReminderRecipients;
use Karnoweb\TaskManager\EventRecorder;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\SubjectRegistry;
use Karnoweb\TaskManager\TaskGraph\ScopeGraphLock;
use Karnoweb\TaskManager\TaskService;
use Karnoweb\TaskManager\Workflow\StateIndex;

final class ReminderService
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly TaskService $tasks, private readonly NotificationPreferences $preferences, private readonly ?SubjectRegistry $subjects = null, private readonly ?ReminderRecipients $recipients = null)
    {
    }

    private function authorize(ExecutionContext $context): void
    {
        $context->require('reminder.manage');
        $this->authorization->authorize($context, 'reminder.manage');
    }

    public function parseTime(string $wall, string $timezone, string $calendar = 'gregorian', string $dstPolicy = 'reject'): \DateTimeImmutable
    {
        $wall = strtr($wall, array_combine(preg_split('//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY), str_split('01234567890123456789')));
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2}) (\d{2}:\d{2}:\d{2})$/D', $wall, $parts)) {
            throw new DomainError('date_invalid');
        }
        if (! in_array($calendar, ['gregorian', 'jalali'], true)) {
            throw new DomainError('calendar_system_invalid');
        }
        if ($calendar === 'jalali') {
            $wall = PersianCalendar::date((int) $parts[1], (int) $parts[2], (int) $parts[3]).' '.$parts[4];
        }

        return LocalTime::resolve($wall, $timezone, $dstPolicy);
    }

    public function suggestedRecipient(ExecutionContext $context, ?string $type = null, ?string $id = null): string
    {
        $this->authorize($context);
        $this->linkedSubject($context, $type, $id);
        $policy = $this->recipients ?? new SelfReminderRecipients;
        $recipient = $policy->suggest($context, $type, $id);
        $policy->authorize($context, $recipient, $type, $id);

        return $recipient;
    }

    private function linkedSubject(ExecutionContext $context, ?string $type, ?string $id): void
    {
        if ($type === null && $id === null) {
            return;
        }
        if ($type === null || $id === null || $id === '' || strlen($id) > 120 || $this->subjects === null) {
            throw new DomainError('subject_reference_invalid');
        }
        $handler = $this->subjects->handler($type);
        if (! $handler->query($context, 'view')->where($handler->scopeColumn(), $context->scope)->where($handler->idColumn(), $id)->exists()) {
            throw new DomainError('not_found');
        }
    }

    private function body(string $body, string $timezone): void
    {
        if (trim($body) === '' || mb_strlen($body) > 10000) {
            throw new DomainError('reminder_invalid');
        }
        LocalTime::zone($timezone);
    }

    public function createGeneral(ExecutionContext $context, string $body, \DateTimeImmutable $when, string $timezone, string $operation, ?string $subjectType = null, ?string $subjectId = null, ?string $recipient = null, bool $allowPast = false): array
    {
        $this->authorize($context);
        $this->body($body, $timezone);
        if ($recipient !== null && (trim($recipient) === '' || strlen($recipient) > 120)) {
            throw new DomainError('reminder_recipient_denied');
        }
        $payload = ['action' => 'general_reminder_create', 'body' => $body, 'when' => LocalTime::stamp($when), 'timezone' => $timezone, 'type' => $subjectType, 'subject' => $subjectId, 'recipient' => $recipient, 'allow_past' => $allowPast];

        return (new CommandExecutor($this->db))->execute($context, $operation, $payload, function () use ($context, $body, $when, $timezone, $operation, $subjectType, $subjectId, $recipient, $allowPast) {
            ScopeGraphLock::acquire($this->db, $context);
            $recipient ??= $this->suggestedRecipient($context, $subjectType, $subjectId);
            $this->linkedSubject($context, $subjectType, $subjectId);
            ($this->recipients ?? new SelfReminderRecipients)->authorize($context, $recipient, $subjectType, $subjectId);
            if ($allowPast) {
                $context->require('reminder.schedule_past');
                $this->authorization->authorize($context, 'reminder.schedule_past');
            } elseif ($when <= $this->clock->now()) {
                throw new DomainError('reminder_in_past');
            }
            $key = (string) Str::uuid();
            $now = LocalTime::stamp($this->clock->now());
            $this->db->table('tm_reminders')->insert(['reminder_key' => $key, 'scope_key' => $context->scope, 'task_id' => null, 'actor_ref' => $recipient, 'created_by' => $context->actor,
                'body' => $body, 'timezone' => $timezone, 'subject_type' => $subjectType, 'subject_id' => $subjectId, 'anchor' => 'absolute', 'offset_seconds' => 0, 'source_revision' => 0,
                'absolute_at' => LocalTime::stamp($when), 'scheduled_at' => LocalTime::stamp($when), 'created_at' => $now, 'updated_at' => $now]);
            (new EventRecorder($this->db, $this->clock))->append($context, 'reminder', $key, 'reminder_created', $operation, ['recipient_ref' => $recipient]);

            return $this->get($context, $key);
        }, fn ($row) => $this->get($context, $row['reminder_key']));
    }

    public function updateGeneral(ExecutionContext $context, string $key, int $version, array $changes, string $operation): array
    {
        $this->authorize($context);
        if (array_diff(array_keys($changes), ['body', 'when', 'timezone', 'recipient', 'subject_type', 'subject_id'])) {
            throw new DomainError('reminder_invalid');
        }
        if (array_key_exists('when', $changes) && ! $changes['when'] instanceof \DateTimeImmutable) {
            throw new DomainError('date_invalid');
        }
        $payload = $changes;
        if (isset($payload['when'])) {
            $payload['when'] = LocalTime::stamp($payload['when']);
        }

        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'general_reminder_update', 'key' => $key, 'version' => $version, 'changes' => $payload], function () use ($context, $key, $version, $changes, $operation) {
            ScopeGraphLock::acquire($this->db, $context);
            $row = $this->generalForUpdate($context, $key, $version);
            $body = $changes['body'] ?? $row['body'];
            $timezone = $changes['timezone'] ?? $row['timezone'];
            if (! is_string($body) || ! is_string($timezone)) {
                throw new DomainError('reminder_invalid');
            }
            $this->body($body, $timezone);
            $type = array_key_exists('subject_type', $changes) ? $changes['subject_type'] : $row['subject_type'];
            $id = array_key_exists('subject_id', $changes) ? $changes['subject_id'] : $row['subject_id'];
            if (($type !== null && ! is_string($type)) || ($id !== null && ! is_string($id))) {
                throw new DomainError('subject_reference_invalid');
            }
            $this->linkedSubject($context, $type, $id);
            $recipient = $changes['recipient'] ?? $row['actor_ref'];
            if (! is_string($recipient) || trim($recipient) === '' || strlen($recipient) > 120) {
                throw new DomainError('reminder_recipient_denied');
            }
            ($this->recipients ?? new SelfReminderRecipients)->authorize($context, $recipient, $type, $id);
            if (isset($changes['when']) && $changes['when'] <= $this->clock->now()) {
                throw new DomainError('reminder_in_past');
            }
            $rescheduled = isset($changes['when']) && LocalTime::stamp($changes['when']) !== LocalTime::stamp(new \DateTimeImmutable($row['absolute_at'], new \DateTimeZone('UTC')));
            $newDelivery = $rescheduled || $recipient !== $row['actor_ref'] || $type !== $row['subject_type'] || $id !== $row['subject_id'];
            $values = ['body' => $body, 'timezone' => $timezone, 'actor_ref' => $recipient, 'subject_type' => $type, 'subject_id' => $id, 'version' => $version + 1, 'generation' => $row['generation'] + (int) $newDelivery, 'updated_at' => LocalTime::stamp($this->clock->now())];
            if (isset($changes['when'])) {
                $values += ['scheduled_at' => LocalTime::stamp($changes['when']), 'absolute_at' => LocalTime::stamp($changes['when'])];
            }
            if ($newDelivery && $row['state'] === 'sent') {
                $values['state'] = 'pending';
            }
            $this->db->table('tm_reminders')->where('id', $row['id'])->update($values);
            (new EventRecorder($this->db, $this->clock))->append($context, 'reminder', $key, 'reminder_updated', $operation, ['version' => $version + 1, 'recipient_ref' => $recipient]);

            return $this->get($context, $key);
        }, fn ($row) => $this->get($context, $row['reminder_key']));
    }

    private function generalForUpdate(ExecutionContext $context, string $key, int $version): array
    {
        $row = $this->get($context, $key);
        $locked = $this->db->table('tm_reminders')->where('id', $row['id'])->lockForUpdate()->first();
        if ($locked->version !== $version) {
            throw new DomainError('version_conflict');
        }
        if ($row['task_id'] !== null) {
            throw new DomainError('reminder_anchored');
        }
        $this->authorization->constrain($q = $this->db->table('tm_reminders')->where('id', $row['id']), $context, 'reminder', 'edit');
        if (! $q->exists()) {
            throw new DomainError('not_found');
        }

        return (array) $locked;
    }

    public function changeState(ExecutionContext $context, string $key, int $version, string $action, string $operation): array
    {
        $this->authorize($context);
        if (! in_array($action, ['complete', 'reopen', 'delete'], true)) {
            throw new DomainError('reminder_action_invalid');
        }

        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'general_reminder_state', 'key' => $key, 'version' => $version, 'state' => $action], function () use ($context, $key, $version, $action, $operation) {
            ScopeGraphLock::acquire($this->db, $context);
            $row = $this->generalForUpdate($context, $key, $version);
            $now = LocalTime::stamp($this->clock->now());
            $this->db->table('tm_reminders')->where('id', $row['id'])->update(['state' => match ($action) {
                'complete' => 'completed', 'reopen' => 'pending', 'delete' => 'cancelled'
            },
                'version' => $version + 1, 'generation' => $row['generation'] + 1, 'completed_at' => $action === 'complete' ? $now : null, 'deleted_at' => $action === 'delete' ? $now : null, 'updated_at' => $now]);
            (new EventRecorder($this->db, $this->clock))->append($context, 'reminder', $key, 'reminder_'.$action, $operation, ['version' => $version + 1]);

            return $this->read($context, $key, $action === 'delete');
        }, fn ($row) => $this->read($context, $row['reminder_key'], $action === 'delete'));
    }

    public function query(ExecutionContext $context, int $limit = 30, int $afterId = 0): array
    {
        if ($limit < 1 || $limit > 100 || $afterId < 0) {
            throw new DomainError('pagination_invalid');
        }

        return $this->visible($context)->where('id', '>', $afterId)->orderBy('id')->limit($limit)->get()->map(fn ($row) => (array) $row)->all();
    }

    public function count(ExecutionContext $context): int
    {
        return $this->visible($context)->whereIn('state', ['pending', 'sent'])->count();
    }

    public function activity(ExecutionContext $context, string $key): array
    {
        $this->read($context, $key, true);

        return $this->db->table('tm_audit_events')->where('scope_key', $context->scope)->where('aggregate_type', 'reminder')->where('aggregate_key', $key)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }

    private function dispatchGeneral(ExecutionContext $context, object $row): string
    {
        try {
            $this->get($context, $row->reminder_key);
        } catch (DomainError $error) {
            if (! in_array($error->getMessage(), ['not_found', 'capability_denied'], true)) {
                throw $error;
            }
            $this->db->table('tm_reminders')->where('id', $row->id)->update(['state' => 'cancelled']);

            return 'cancelled';
        }
        $allowed = $this->preferences->nextAllowed($context, $row->channel, $this->clock->now());
        if ($allowed === null) {
            $this->db->table('tm_reminders')->where('id', $row->id)->update(['state' => 'cancelled']);

            return 'cancelled';
        }
        if ($allowed > $this->clock->now()) {
            $this->db->table('tm_reminders')->where('id', $row->id)->update(['scheduled_at' => LocalTime::stamp($allowed)]);

            return 'deferred';
        }
        $operation = 'reminder:'.$row->id.':'.$row->generation;
        (new EventRecorder($this->db, $this->clock))->append($context, 'reminder', $row->reminder_key, 'reminder_due', $operation, ['reminder_key' => $row->reminder_key, 'generation' => $row->generation, 'recipient_ref' => $row->actor_ref, 'channel' => $row->channel]);
        $event = $this->db->table('tm_outbox')->where('scope_key', $context->scope)->where('operation_id', $operation)->where('action', 'reminder_due')->value('event_id');
        $this->db->table('tm_reminder_deliveries')->insert(['reminder_id' => $row->id, 'generation' => $row->generation, 'event_id' => $event, 'created_at' => LocalTime::stamp($this->clock->now())]);
        $this->db->table('tm_reminders')->where('id', $row->id)->update(['state' => 'sent']);

        return 'queued';
    }

    public function get(ExecutionContext $context, string $key): array
    {
        return $this->read($context, $key);
    }

    private function visible(ExecutionContext $context, bool $includeDeleted = false): Builder
    {
        $this->authorize($context);
        $query = $this->db->table('tm_reminders')->where('scope_key', $context->scope);
        if (! in_array('reminder.view_others', $context->capabilities, true)) {
            $query->where(fn ($q) => $q->where('actor_ref', $context->actor)->orWhere('created_by', $context->actor));
        } else {
            $this->authorization->authorize($context, 'reminder.view_others');
        }
        $this->authorization->constrain($query, $context, 'reminder', 'view');
        if (! $includeDeleted) {
            $query->whereNull('deleted_at');
        }
        $taskQuery = null;
        try {
            $taskQuery = $this->tasks->authorizedQuery($context)->whereNull('deleted_at')->select('id');
        } catch (DomainError $error) {
            if ($error->getMessage() !== 'capability_denied') {
                throw $error;
            }
        }
        $query->where(function ($q) use ($context, $taskQuery) {
            $taskQuery === null ? $q->whereRaw('1 = 0') : $q->whereIn('task_id', $taskQuery);
            $q->orWhere(function ($general) use ($context) {
                $general->whereNull('task_id')->where(function ($sources) use ($context) {
                    $sources->whereNull('subject_type');
                    foreach ($this->subjects?->all() ?? [] as $alias => $handler) {
                        try {
                            $subjects = $handler->query($context, 'view')->where($handler->scopeColumn(), $context->scope);
                        } catch (DomainError $error) {
                            if ($error->getMessage() === 'capability_denied') {
                                continue;
                            }
                            throw $error;
                        }
                        $column = $this->db->getQueryGrammar()->wrap($handler->idColumn());
                        $cast = in_array($this->db->getDriverName(), ['mysql', 'mariadb'], true) ? 'CHAR' : 'VARCHAR(120)';
                        $subjects->select([])->selectRaw('CAST('.$column.' AS '.$cast.')');
                        $sources->orWhere(fn ($typed) => $typed->where('subject_type', $alias)->whereIn('subject_id', $subjects));
                    }
                });
            });
        });

        return $query;
    }

    private function read(ExecutionContext $context, string $key, bool $includeDeleted = false): array
    {
        if (! Str::isUuid($key)) {
            throw new DomainError('not_found');
        } $row = $this->visible($context, $includeDeleted)->where('reminder_key', $key)->first();
        if (! $row) {
            throw new DomainError('not_found');
        }

        return (array) $row;
    }

    public function create(ExecutionContext $context, string $taskKey, string $anchor, int $offsetSeconds, ?\DateTimeImmutable $absolute, string $channel, string $operation): array
    {
        $this->authorize($context);
        if (! in_array($anchor, ['due', 'start', 'absolute'], true) || abs($offsetSeconds) > 31622400 || ! in_array($channel, ['in_app', 'email', 'sms', 'push'], true) || ($anchor === 'absolute') !== ($absolute !== null)) {
            throw new DomainError('reminder_invalid');
        }

        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'reminder_create', 'task' => $taskKey, 'anchor' => $anchor, 'offset' => $offsetSeconds, 'absolute' => LocalTime::stamp($absolute), 'channel' => $channel], function () use ($context, $taskKey, $anchor, $offsetSeconds, $absolute, $channel, $operation) {
            ScopeGraphLock::acquire($this->db, $context);
            $task = $this->tasks->authorizedQuery($context)->where('public_key', $taskKey)->whereNull('deleted_at')->whereNull('archived_at')->lockForUpdate()->first();
            if (! $task) {
                throw new DomainError('not_found');
            }
            if (StateIndex::currentTaskIds($this->db, $context->scope, ['done', 'cancelled'])->where('task_id', $task->id)->exists()) {
                throw new DomainError('reminder_task_terminal');
            }
            if ($this->db->table('tm_reminders')->where('task_id', $task->id)->where('actor_ref', $context->actor)->where('state', 'pending')->count() >= 20) {
                throw new DomainError('reminder_limit');
            }
            $value = match ($anchor) {
                'due' => $task->due_at, 'start' => $task->starts_at, default => LocalTime::stamp($absolute)
            };
            if ($value === null) {
                throw new DomainError('reminder_anchor_missing');
            }
            $scheduled = (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->modify(($offsetSeconds >= 0 ? '+' : '').$offsetSeconds.' seconds');
            $key = (string) Str::uuid();
            $this->db->table('tm_reminders')->insert(['reminder_key' => $key, 'scope_key' => $context->scope, 'task_id' => $task->id, 'actor_ref' => $context->actor, 'anchor' => $anchor, 'offset_seconds' => $offsetSeconds, 'absolute_at' => LocalTime::stamp($absolute), 'scheduled_at' => LocalTime::stamp($scheduled), 'source_revision' => $task->date_revision, 'channel' => $channel]);
            (new EventRecorder($this->db, $this->clock))->append($context, 'task', $taskKey, 'reminder_created', $operation, ['reminder_key' => $key], $task->id);

            return $this->get($context, $key);
        }, fn ($result) => $this->get($context, $result['reminder_key']));
    }

    public function snooze(ExecutionContext $context, string $key, int $version, \DateTimeImmutable $until, string $operation): array
    {
        $this->authorize($context);
        if ($until <= $this->clock->now() || $until > $this->clock->now()->modify('+366 days')) {
            throw new DomainError('snooze_invalid');
        }

        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'reminder_snooze', 'key' => $key, 'version' => $version, 'until' => LocalTime::stamp($until)], function () use ($context, $key, $version, $until, $operation) {
            ScopeGraphLock::acquire($this->db, $context);
            $reminder = $this->get($context, $key);
            if ($reminder['version'] !== $version) {
                throw new DomainError('version_conflict');
            } if ($reminder['state'] === 'cancelled') {
                throw new DomainError('reminder_cancelled');
            }
            $this->db->table('tm_reminders')->where('id', $reminder['id'])->update(['scheduled_at' => LocalTime::stamp($until), 'state' => 'pending', 'version' => $version + 1, 'generation' => $reminder['generation'] + 1]);
            $taskKey = $this->db->table('tm_tasks')->where('id', $reminder['task_id'])->value('public_key');
            (new EventRecorder($this->db, $this->clock))->append($context, 'task', $taskKey, 'reminder_snoozed', $operation, ['reminder_key' => $key], $reminder['task_id']);

            return $this->get($context, $key);
        }, fn ($result) => $this->get($context, $result['reminder_key']));
    }

    public function dispatch(ExecutionContext $context, int $limit = 100): array
    {
        $this->authorize($context);
        if (! $context->serviceActor || $this->db->transactionLevel() !== 0 || $limit < 1 || $limit > 1000) {
            throw new DomainError('scheduler_context_invalid');
        }
        $ids = $this->db->table('tm_reminders')->where('scope_key', $context->scope)->where('actor_ref', $context->actor)->where('state', 'pending')->where('scheduled_at', '<=', LocalTime::stamp($this->clock->now()))->orderBy('scheduled_at')->orderBy('id')->limit($limit)->pluck('id');
        $result = ['queued' => 0, 'deferred' => 0, 'cancelled' => 0];
        foreach ($ids as $id) {
            $outcome = $this->db->transaction(function () use ($context, $id) {
                ScopeGraphLock::acquire($this->db, $context);
                $row = $this->db->table('tm_reminders')->where('id', $id)->where('state', 'pending')->lockForUpdate()->first();
                if (! $row || $row->scheduled_at > LocalTime::stamp($this->clock->now())) {
                    return null;
                }
                if ($row->task_id === null) {
                    return $this->dispatchGeneral($context, $row);
                }
                $task = $this->tasks->authorizedQuery($context)->where('id', $row->task_id)->whereNull('deleted_at')->whereNull('archived_at')->lockForUpdate()->first();
                if (! $task || StateIndex::currentTaskIds($this->db, $context->scope, ['done', 'cancelled'])->where('task_id', $row->task_id)->exists() || $row->source_revision !== $task->date_revision) {
                    $this->db->table('tm_reminders')->where('id', $id)->update(['state' => 'cancelled']);

                    return 'cancelled';
                }
                $allowed = $this->preferences->nextAllowed($context, $row->channel, $this->clock->now());
                if ($allowed === null) {
                    $this->db->table('tm_reminders')->where('id', $id)->update(['state' => 'cancelled']);

                    return 'cancelled';
                }
                if ($allowed > $this->clock->now()) {
                    $this->db->table('tm_reminders')->where('id', $id)->update(['scheduled_at' => LocalTime::stamp($allowed)]);

                    return 'deferred';
                }
                $operation = 'reminder:'.$id.':'.$row->generation;
                (new EventRecorder($this->db, $this->clock))->append($context, 'task', $task->public_key, 'reminder_due', $operation, ['reminder_key' => $row->reminder_key, 'generation' => $row->generation, 'recipient_ref' => $context->actor, 'channel' => $row->channel], $task->id);
                $eventId = $this->db->table('tm_outbox')->where('scope_key', $context->scope)->where('operation_id', $operation)->where('action', 'reminder_due')->value('event_id');
                $this->db->table('tm_reminder_deliveries')->insert(['reminder_id' => $id, 'generation' => $row->generation, 'event_id' => $eventId, 'created_at' => LocalTime::stamp($this->clock->now())]);
                $this->db->table('tm_reminders')->where('id', $id)->update(['state' => 'sent']);

                return 'queued';
            }, 3);
            if ($outcome !== null) {
                $result[$outcome]++;
            }
        }

        return $result;
    }
}
