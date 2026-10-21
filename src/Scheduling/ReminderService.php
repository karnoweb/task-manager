<?php

namespace Karnoweb\TaskManager\Scheduling;

use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Karnoweb\TaskManager\CommandExecutor;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\AuthorizationProvider;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\Contracts\NotificationPreferences;
use Karnoweb\TaskManager\EventRecorder;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\TaskGraph\ScopeGraphLock;
use Karnoweb\TaskManager\TaskService;
use Karnoweb\TaskManager\Workflow\StateIndex;

final class ReminderService
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly TaskService $tasks, private readonly NotificationPreferences $preferences)
    {
    }

    private function authorize(ExecutionContext $context): void
    {
        $context->require('reminder.manage');
        $this->authorization->authorize($context, 'reminder.manage');
    }

    public function get(ExecutionContext $context, string $key): array
    {
        if (! Str::isUuid($key)) {
            throw new DomainError('not_found');
        } $row = $this->db->table('tm_reminders')->where('scope_key', $context->scope)->where('actor_ref', $context->actor)->where('reminder_key', $key)->whereIn('task_id', $this->tasks->authorizedQuery($context)->whereNull('deleted_at')->select('id'))->first();
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
