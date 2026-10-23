<?php

namespace Karnoweb\TaskManager\Integration;

use Illuminate\Database\Connection;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Group\GroupService;
use Karnoweb\TaskManager\Handoff\HandoffService;
use Karnoweb\TaskManager\Scheduling\ReminderService;
use Karnoweb\TaskManager\TaskService;
use Karnoweb\TaskManager\Workflow\InstanceService;

final class EventAccess
{
    public function __construct(private readonly Connection $db, private readonly TaskService $tasks, private readonly InstanceService $instances, private readonly GroupService $groups, private readonly HandoffService $handoffs, private readonly ?ReminderService $reminders = null)
    {
    }

    public function source(ExecutionContext $context, array $event): array
    {
        if ($event['scope_key'] !== $context->scope) {
            throw new DomainError('event_scope_invalid');
        }
        $sourcePayload = json_decode($event['payload'], true, flags: JSON_THROW_ON_ERROR);
        if (isset($sourcePayload['handoff_key']) && is_string($sourcePayload['handoff_key'])) {
            $this->handoffs->get($context, $sourcePayload['handoff_key']);
        }
        $source = ['task_key' => null, 'instance_key' => null, 'group_key' => null];
        if ($event['aggregate_type'] === 'task') {
            $key = $event['aggregate_key'] ?? $this->tasks->authorizedQuery($context)->where('id', $event['task_id'])->value('public_key');
            if (! is_string($key)) {
                throw new DomainError('not_found');
            }$task = $this->tasks->get($context, $key);
            $source['task_key'] = $task['public_key'];
        } elseif ($event['aggregate_type'] === 'instance') {
            $instance = $this->instances->get($context, $event['aggregate_key']);
            $source['instance_key'] = $instance['instance_key'];
            if ($instance['task_id'] !== null) {
                $source['task_key'] = $this->db->table('tm_tasks')->where('id', $instance['task_id'])->value('public_key');
            }
        } elseif (in_array($event['aggregate_type'], ['group', 'process_group'], true)) {
            $group = $this->groups->get($context, $event['aggregate_key']);
            $source['group_key'] = $group['group_key'];
        } elseif ($event['aggregate_type'] === 'handoff') {
            $handoff = $this->handoffs->get($context, $event['aggregate_key']);
            $source['instance_key'] = $this->db->table('tm_workflow_instances')->where('id', $handoff['origin_id'])->value('instance_key');
        } elseif ($event['aggregate_type'] === 'reminder') {
            if ($this->reminders === null) {
                throw new DomainError('event_source_not_subscribable');
            }
            $reminder = $this->reminders->get($context, $event['aggregate_key']);
            if ($reminder['actor_ref'] !== $context->actor) {
                throw new DomainError('not_found');
            }
        } else {
            throw new DomainError('event_source_not_subscribable');
        }
        if (($event['schema_version'] ?? 1) !== 1) {
            throw new DomainError('event_schema_unsupported');
        }

        return $source;
    }

    public function envelope(array $event): array
    {
        $payload = json_decode($event['payload'], true, flags: JSON_THROW_ON_ERROR);
        $allowed = [];
        foreach (['state', 'from', 'to', 'status', 'reason_code', 'handoff_key', 'outcome', 'group_key', 'membership_revision', 'version', 'reminder_key', 'generation', 'recipient_ref', 'channel', 'visit_id', 'policy_id', 'step_key'] as $key) {
            if (isset($payload[$key]) && (is_scalar($payload[$key]) || $payload[$key] === null)) {
                $allowed[$key] = $payload[$key];
            }
        }

        return ['scope_key' => $event['scope_key'], 'schema_version' => 1, 'event_id' => $event['event_id'], 'event_type' => $event['action'], 'aggregate_type' => $event['aggregate_type'], 'aggregate_key' => $event['aggregate_key'] ?? ($event['aggregate_type'] === 'task' ? $this->db->table('tm_tasks')->where('scope_key', $event['scope_key'])->where('id', $event['task_id'])->value('public_key') : null), 'occurred_at' => $event['occurred_at'], 'data' => $allowed];
    }
}
