<?php

namespace Karnoweb\TaskManager\Handoff;

use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Karnoweb\TaskManager\CommandExecutor;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\AuthorizationProvider;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\EventRecorder;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Responsibility\AssignmentEngine;
use Karnoweb\TaskManager\SLA\SlaService;
use Karnoweb\TaskManager\Workflow\CaseService;
use Karnoweb\TaskManager\Workflow\Definition;
use Karnoweb\TaskManager\Workflow\InstanceService;
use Karnoweb\TaskManager\Workflow\WorkflowService;

/** Same-subject delegation. Policies and outcome evidence remain immutable. */
final class HandoffService
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock,
        private readonly AuthorizationProvider $authorization, private readonly InstanceService $instances,
        private readonly WorkflowService $workflows, private readonly CaseService $cases,
        private readonly SlaService $sla, private readonly AssignmentEngine $assignments)
    {
    }

    private function authorize(ExecutionContext $context, string $capability): void
    {
        $context->require($capability);
        $this->authorization->authorize($context, $capability);
    }

    public function get(ExecutionContext $context, string $key): array
    {
        $this->authorize($context, 'handoff.view');
        if (! Str::isUuid($key)) {
            throw new DomainError('not_found');
        }
        $row = $this->db->table('tm_handoffs')->where('scope_key', $context->scope)->where('handoff_key', $key)->first();
        if (! $row) {
            throw new DomainError('not_found');
        }
        foreach ([$row->origin_id, $row->destination_id] as $id) {
            $instanceKey = $this->db->table('tm_workflow_instances')->where('scope_key', $context->scope)->where('id', $id)->value('instance_key');
            $this->instances->get($context, $instanceKey ?? '');
        }

        return (array) $row;
    }

    private function policy(array $policy): array
    {
        if (array_diff(array_keys($policy), ['returns', 'assignment', 'sla', 'timeout_seconds', 'recall_transition', 'timeout_transition', 'cancel_reason', 'reason_code', 'target_team'])) {
            throw new DomainError('handoff_policy_invalid');
        }
        Definition::key($policy['reason_code'] ?? null);
        if (! is_string($policy['target_team'] ?? null) || trim($policy['target_team']) === '' || strlen($policy['target_team']) > 120) {
            throw new DomainError('handoff_team_required');
        }
        $policy += ['assignment' => 'restore_origin', 'sla' => 'resume', 'timeout_seconds' => null, 'recall_transition' => null, 'timeout_transition' => null, 'cancel_reason' => null];
        if (! in_array($policy['assignment'], ['restore_origin', 'keep_current', 'resolve_stage'], true)
            || ! in_array($policy['sla'], ['resume', 'reset'], true) || ! is_array($policy['returns'] ?? null)
            || ($policy['timeout_seconds'] !== null && (! is_int($policy['timeout_seconds']) || $policy['timeout_seconds'] < 1))) {
            throw new DomainError('handoff_policy_invalid');
        }
        if (array_diff(array_keys($policy['returns']), ['approved', 'rejected', 'cancelled', 'timed_out', 'recalled', 'manual_resolution'])) {
            throw new DomainError('handoff_policy_invalid');
        }
        foreach ($policy['returns'] as $outcome => $return) {
            if (! is_array($return) || array_diff(array_keys($return), ['transition', 'reason_code']) || ! is_string($return['transition'] ?? null)) {
                throw new DomainError('handoff_return_invalid');
            }
            Definition::key($return['transition']);
            if (isset($return['reason_code'])) {
                Definition::key($return['reason_code']);
            }
        }
        foreach (['recall_transition', 'timeout_transition', 'cancel_reason'] as $field) {
            if ($policy[$field] !== null) {
                Definition::key($policy[$field]);
            }
        }

        return $policy;
    }

    public function delegate(ExecutionContext $context, string $originKey, int $version, string $pipelineKey, int $revisionId, string $slot, array $policy, string $operation): array
    {
        return $this->createDelegate($context, $originKey, $version, $pipelineKey, $revisionId, $slot, $policy, $operation);
    }

    public function parallel(ExecutionContext $context, string $originKey, int $version, array $destinations, array $policy, array $join, string $operation): array
    {
        $this->authorize($context, 'handoff.manage');
        if (! array_is_list($destinations) || count($destinations) < 2 || count($destinations) > 20
            || array_diff(array_keys($join), ['mode', 'quorum', 'outcomes', 'priority', 'remaining'])
            || ! in_array($join['mode'] ?? null, ['all', 'any', 'quorum', 'manual'], true)
            || ($join['remaining'] ?? null) !== 'keep_active_detached') {
            throw new DomainError('handoff_join_invalid');
        }
        $join['quorum'] = match ($join['mode']) {
            'all' => count($destinations), 'any' => 1, 'manual' => count($destinations) + 1, default => $join['quorum'] ?? null
        };
        if (! is_int($join['quorum']) || $join['quorum'] < 1 || ($join['mode'] !== 'manual' && $join['quorum'] > count($destinations))) {
            throw new DomainError('handoff_join_invalid');
        }
        foreach (['outcomes', 'priority'] as $field) {
            if (! is_array($join[$field] ?? null) || ! array_is_list($join[$field]) || ! $join[$field] || count(array_unique($join[$field])) !== count($join[$field])
                || array_diff($join[$field], ['approved', 'rejected', 'cancelled', 'recalled', 'timed_out'])) {
                throw new DomainError('handoff_join_invalid');
            }
        }
        if (array_diff($join['outcomes'], $join['priority'])) {
            throw new DomainError('handoff_join_invalid');
        }
        foreach ($destinations as $destination) {
            if (! is_array($destination) || array_diff(array_keys($destination), ['pipeline_key', 'revision_id', 'slot'])
                || ! is_string($destination['pipeline_key'] ?? null) || ! is_int($destination['revision_id'] ?? null) || ! is_string($destination['slot'] ?? null)) {
                throw new DomainError('handoff_destination_invalid');
            }
        }

        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'parallel', 'origin' => $originKey, 'version' => $version, 'destinations' => $destinations, 'policy' => $policy, 'join' => $join], function () use ($context, $originKey, $version, $destinations, $policy, $join, $operation) {
            [$origin] = $this->instances->lockForCommand($context, $originKey, $version);
            $groupKey = (string) Str::uuid();
            $groupId = $this->db->table('tm_handoff_groups')->insertGetId(['group_key' => $groupKey, 'scope_key' => $context->scope,
                'origin_id' => $origin['id'], 'join_policy' => json_encode($join, JSON_THROW_ON_ERROR), 'expected_count' => count($destinations)]);
            $members = [];
            foreach ($destinations as $index => $destination) {
                $members[] = $this->createDelegate($context, $originKey, $index === 0 ? $version : $version + 1, $destination['pipeline_key'], $destination['revision_id'], $destination['slot'], $policy, $this->child($operation, 'member-'.$index), $groupId);
            }

            return ['group_id' => $groupId, 'group_key' => $groupKey, 'members' => $members];
        }, function ($result) use ($context) {
            foreach ($result['members'] as $member) {
                $this->get($context, $member['handoff_key']);
            }
        });
    }

    private function createDelegate(ExecutionContext $context, string $originKey, int $version, string $pipelineKey, int $revisionId, string $slot, array $policy, string $operation, ?int $groupId = null): array
    {
        $this->authorize($context, 'handoff.manage');
        $policy = $this->policy($policy);

        return $this->command($context, $operation, ['action' => 'delegate', 'origin' => $originKey, 'version' => $version, 'pipeline' => $pipelineKey, 'revision' => $revisionId, 'slot' => $slot, 'policy' => $policy, 'group_id' => $groupId], function () use ($context, $originKey, $version, $pipelineKey, $revisionId, $slot, $policy, $operation, $groupId) {
            [$origin] = $this->instances->lockForCommand($context, $originKey, $version);
            $reservation = $this->db->table('tm_active_handoff_origins')->where('origin_id', $origin['id'])->first();
            $alreadyHeld = $groupId !== null && $reservation !== null && $this->db->table('tm_handoffs')->where('id', $reservation->handoff_id)->where('group_id', $groupId)->exists();
            if (! $alreadyHeld && ($origin['lifecycle'] !== 'active' || $reservation !== null)) {
                throw new DomainError('handoff_origin_unavailable');
            }
            if ($slot === $origin['slot_key']) {
                throw new DomainError('handoff_slot_conflict');
            }
            if ($this->db->table('tm_active_approval_steps')->where('instance_id', $origin['id'])->exists()) {
                throw new DomainError('approval_pending');
            }
            $parent = $this->db->table('tm_handoffs')->where('scope_key', $context->scope)->where('destination_id', $origin['id'])->first();
            $lineage = $parent === null ? [] : json_decode($parent->lineage, true, flags: JSON_THROW_ON_ERROR);
            $sourcePipeline = $origin['pipeline_id'] === null ? 'none' : $this->db->table('tm_pipelines')->where('id', $origin['pipeline_id'])->value('pipeline_key');
            $lineage[] = $sourcePipeline;
            if (count($lineage) > 5 || in_array($pipelineKey, $lineage, true)) {
                throw new DomainError('handoff_cycle_or_depth');
            }
            $case = $origin['case_id'] === null ? $this->cases->create($context, 'Process handoff', $this->child($operation, 'case'))
                : ['id' => $origin['case_id'], 'case_key' => $this->db->table('tm_process_cases')->where('id', $origin['case_id'])->value('case_key')];
            $destination = $this->instances->start($context, $origin['subject_type'], $origin['subject_id'], $revisionId, $this->child($operation, 'destination'), $slot, $pipelineKey, caseKey: $case['case_key']);
            $visit = $this->db->table('tm_stage_visits')->where('instance_id', $origin['id'])->whereNull('exited_at')->first();
            $pausedVisit = null;
            if (! $alreadyHeld && $visit !== null && $visit->policy_id !== null) {
                if ($visit->paused_at !== null) {
                    throw new DomainError('handoff_sla_already_paused');
                }
                $this->sla->pause($context, $visit->id, $visit->sla_version, $this->child($operation, 'pause'), 'handoff');
                $pausedVisit = $visit->id;
            }
            if ($alreadyHeld) {
                $pausedVisit = $this->db->table('tm_handoffs')->where('id', $reservation->handoff_id)->value('paused_visit_id');
            }
            $this->db->table('tm_workflow_instances')->where('id', $origin['id'])->update(['lifecycle' => 'waiting', 'version' => $alreadyHeld ? $version : $version + 1, 'case_id' => $case['id']]);
            $this->db->table('tm_case_subjects')->insertOrIgnore(['case_id' => $case['id'], 'subject_type' => $origin['subject_type'], 'subject_id' => $origin['subject_id']]);
            if ($origin['task_id'] !== null) {
                $this->db->table('tm_tasks')->where('id', $origin['task_id'])->increment('version');
            }
            $snapshot = $origin['assignment_snapshot'] === null ? ['primary' => $origin['primary_assignee'], 'secondary' => [], 'policy_id' => null, 'max_assignees' => 1, 'allow_unassigned' => false] : json_decode($origin['assignment_snapshot'], true, flags: JSON_THROW_ON_ERROR);
            $key = (string) Str::uuid();
            $id = $this->db->table('tm_handoffs')->insertGetId(['handoff_key' => $key, 'scope_key' => $context->scope, 'origin_id' => $origin['id'], 'destination_id' => $destination['id'],
                'origin_version' => $alreadyHeld ? $version : $version + 1, 'group_id' => $groupId, 'policy_snapshot' => json_encode($policy, JSON_THROW_ON_ERROR), 'assignment_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                'lineage' => json_encode($lineage, JSON_THROW_ON_ERROR), 'paused_visit_id' => $pausedVisit, 'created_at' => $this->now(),
                'origin_snapshot' => json_encode(array_intersect_key($origin, array_flip(['current_state_key', 'active_stage_key', 'workflow_revision_id', 'owner_ref', 'version', 'pipeline_id'])), JSON_THROW_ON_ERROR),
                'initiator_ref' => $context->actor, 'reason_code' => $policy['reason_code'], 'target_team' => $policy['target_team'],
                'deadline_at' => $policy['timeout_seconds'] === null ? null : $this->clock->now()->modify('+'.$policy['timeout_seconds'].' seconds')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')]);
            if (! $alreadyHeld) {
                $this->db->table('tm_active_handoff_origins')->insert(['origin_id' => $origin['id'], 'handoff_id' => $id]);
            }
            $this->event($context, $origin, 'handoff_created', $operation, ['handoff_key' => $key, 'destination_key' => $destination['instance_key']]);

            return $this->get($context, $key);
        });
    }

    /** Consume a committed terminal destination; never accept caller-asserted success. */
    public function settle(ExecutionContext $context, string $key, int $version, string $operation): array
    {
        $this->authorize($context, 'handoff.manage');

        return $this->command($context, $operation, ['action' => 'settle', 'key' => $key, 'version' => $version], function () use ($context, $key, $version, $operation) {
            [$handoff, $origin, $destination] = $this->locked($context, $key);
            if (in_array($handoff['status'], ['resolved', 'awaiting_join', 'detached_resolved', 'manually_closed'], true)) {
                $actual = $this->terminal($context, $destination);
                if ($actual !== $handoff['outcome'] && ! (in_array($handoff['outcome'], ['recalled', 'timed_out'], true) && $actual === 'cancelled')) {
                    throw new DomainError('handoff_already_resolved');
                }

                return $this->get($context, $key);
            }
            if ($handoff['version'] !== $version) {
                throw new DomainError('version_conflict');
            }
            $outcome = $this->terminal($context, $destination);

            return $this->returnOrigin($context, $handoff, $origin, $destination, $outcome, $operation);
        });
    }

    public function recall(ExecutionContext $context, string $key, int $version, string $operation, bool $timeout = false): array
    {
        $this->authorize($context, $timeout ? 'handoff.timeout' : 'handoff.recall');

        return $this->command($context, $operation, ['action' => $timeout ? 'timeout' : 'recall', 'key' => $key, 'version' => $version], function () use ($context, $key, $version, $operation, $timeout) {
            [$handoff, $origin, $destination] = $this->locked($context, $key);
            if ($handoff['status'] !== 'active') {
                throw new DomainError('handoff_already_resolved');
            }
            if ($handoff['version'] !== $version) {
                throw new DomainError('version_conflict');
            }
            if ($timeout && ($handoff['deadline_at'] === null || $handoff['deadline_at'] > $this->now())) {
                throw new DomainError('handoff_timeout_not_due');
            }
            $policy = json_decode($handoff['policy_snapshot'], true, flags: JSON_THROW_ON_ERROR);
            $edge = $policy[$timeout ? 'timeout_transition' : 'recall_transition'];
            if ($edge === null) {
                throw new DomainError('handoff_resolution_policy_required');
            }
            $destination = $this->instances->transition($context, $destination['instance_key'], $destination['version'], $this->child($operation, 'cancel'), $edge, ['reason_code' => $policy['cancel_reason']]);
            if ($this->terminal($context, $destination) !== 'cancelled') {
                throw new DomainError('handoff_cancel_transition_invalid');
            }

            return $this->returnOrigin($context, $handoff, $origin, $destination, $timeout ? 'timed_out' : 'recalled', $operation);
        });
    }

    public function getGroup(ExecutionContext $context, string $key): array
    {
        $this->authorize($context, 'handoff.view');
        if (! Str::isUuid($key)) {
            throw new DomainError('not_found');
        }
        $group = $this->db->table('tm_handoff_groups')->where('scope_key', $context->scope)->where('group_key', $key)->first();
        if (! $group) {
            throw new DomainError('not_found');
        }
        $members = $this->db->table('tm_handoffs')->where('scope_key', $context->scope)->where('group_id', $group->id)->orderBy('id')->pluck('handoff_key');
        $visible = [];
        foreach ($members as $member) {
            $visible[] = $this->get($context, $member);
        }

        return [...(array) $group, 'members' => $visible];
    }

    public function manualJoin(ExecutionContext $context, string $groupKey, int $version, string $operation): array
    {
        $this->authorize($context, 'handoff.manage');
        $this->authorize($context, 'handoff.repair');

        return $this->command($context, $operation, ['action' => 'manual_join', 'group' => $groupKey, 'version' => $version], function () use ($context, $groupKey, $version, $operation) {
            $group = $this->getGroup($context, $groupKey);
            $join = json_decode($group['join_policy'], true, flags: JSON_THROW_ON_ERROR);
            if ($join['mode'] !== 'manual') {
                throw new DomainError('handoff_join_invalid');
            }
            $chosen = null;
            foreach ($join['priority'] as $outcome) {
                if (! in_array($outcome, $join['outcomes'], true)) {
                    continue;
                }
                foreach ($group['members'] as $member) {
                    if ($member['outcome'] === $outcome) {
                        $chosen = $member;
                        break 2;
                    }
                }
            }
            if ($chosen === null) {
                throw new DomainError('handoff_join_no_result');
            }
            [$handoff, $origin, $destination] = $this->locked($context, $chosen['handoff_key']);
            $locked = $this->db->table('tm_handoff_groups')->where('id', $group['id'])->lockForUpdate()->first();
            if ($locked->version !== $version) {
                throw new DomainError('version_conflict');
            }
            $this->repairRecord($context, $handoff, $origin['version'], 'manual_join', 'manual_resolution', $operation);

            return $this->returnOrigin($context, $handoff, $origin, $destination, $handoff['outcome'], $operation, true);
        });
    }

    public function retryReturn(ExecutionContext $context, string $key, int $version, int $originVersion, string $reason, string $operation): array
    {
        $this->authorize($context, 'handoff.manage');
        $this->authorize($context, 'handoff.repair');
        if (! in_array($reason, ['source_change_reviewed', 'host_guard_repaired', 'assignee_repaired', 'clock_policy_reviewed'], true)) {
            throw new DomainError('reason_invalid');
        }

        return $this->command($context, $operation, ['action' => 'handoff_retry', 'key' => $key, 'version' => $version, 'origin_version' => $originVersion, 'reason' => $reason], function () use ($context, $key, $version, $originVersion, $reason, $operation) {
            [$handoff, $origin, $destination] = $this->locked($context, $key);
            if ($handoff['version'] !== $version || $origin['version'] !== $originVersion) {
                throw new DomainError('version_conflict');
            }
            if ($handoff['status'] !== 'return_conflict' || $origin['lifecycle'] !== 'waiting') {
                throw new DomainError('handoff_repair_invalid');
            }
            $snapshot = json_decode($handoff['origin_snapshot'], true, flags: JSON_THROW_ON_ERROR);
            foreach (['current_state_key', 'active_stage_key', 'workflow_revision_id', 'owner_ref', 'pipeline_id'] as $field) {
                if ($origin[$field] !== $snapshot[$field]) {
                    throw new DomainError('handoff_return_conflict');
                }
            }
            $outcome = $this->terminal($context, $destination);
            if ($outcome !== $handoff['outcome'] && ! (in_array($handoff['outcome'], ['recalled', 'timed_out'], true) && $outcome === 'cancelled')) {
                throw new DomainError('handoff_already_resolved');
            }
            $this->repairRecord($context, $handoff, $originVersion, 'retry_return', $reason, $operation);
            $this->db->table('tm_handoffs')->where('id', $handoff['id'])->update(['repair_origin_version' => $originVersion]);
            $handoff['repair_origin_version'] = $originVersion;

            return $this->returnOrigin($context, $handoff, $origin, $destination, $handoff['outcome'], $operation);
        });
    }

    public function closeConflict(ExecutionContext $context, string $key, int $version, string $operation): array
    {
        $this->authorize($context, 'handoff.manage');
        $this->authorize($context, 'handoff.repair');

        return $this->command($context, $operation, ['action' => 'handoff_conflict_close', 'key' => $key, 'version' => $version], function () use ($context, $key, $version, $operation) {
            [$handoff, $origin, $destination] = $this->locked($context, $key);
            if ($handoff['version'] !== $version) {
                throw new DomainError('version_conflict');
            }
            if ($handoff['status'] !== 'return_conflict' || $origin['lifecycle'] !== 'closed' || $handoff['outcome'] === null) {
                throw new DomainError('handoff_repair_invalid');
            }
            $this->repairRecord($context, $handoff, $origin['version'], 'close_conflict', 'origin_closed_reviewed', $operation);
            $this->db->table('tm_handoffs')->where('id', $handoff['id'])->update(['status' => 'manually_closed', 'version' => $version + 1, 'resolved_at' => $this->now()]);
            $this->db->table('tm_active_handoff_origins')->where('origin_id', $origin['id'])->delete();
            if ($handoff['group_id'] !== null) {
                $this->db->table('tm_handoff_groups')->where('id', $handoff['group_id'])->update(['status' => 'manually_closed', 'version' => $this->db->raw('version + 1')]);
                $this->db->table('tm_handoffs')->where('group_id', $handoff['group_id'])->whereNull('outcome')->update(['status' => 'detached']);
            }
            $this->event($context, $origin, 'handoff_conflict_closed', $operation, ['handoff_key' => $key, 'reason_code' => 'origin_closed_reviewed', 'outcome' => $handoff['outcome']]);

            return $this->get($context, $key);
        });
    }

    private function repairRecord(ExecutionContext $context, array $handoff, int $originVersion, string $action, string $reason, string $operation): void
    {
        $this->db->table('tm_handoff_repairs')->insert(['handoff_id' => $handoff['id'], 'action' => $action, 'accepted_origin_version' => $originVersion,
            'actor_ref' => $context->actor, 'reason_code' => $reason, 'operation_id' => $operation, 'occurred_at' => $this->now()]);
    }

    private function terminal(ExecutionContext $context, array $destination): string
    {
        if ($destination['lifecycle'] !== 'closed') {
            throw new DomainError('handoff_destination_not_terminal');
        }
        $definition = $this->workflows->revision($context, $destination['workflow_revision_id'])['definition'];

        return match ($definition['states'][$destination['current_state_key']]['terminal'] ?? 'none') {
            'success' => 'approved', 'failure' => 'rejected', 'cancelled' => 'cancelled', default => throw new DomainError('handoff_destination_not_terminal')
        };
    }

    /** Recovery scan after a committed destination event or a worker restart. */
    public function run(ExecutionContext $context, int $limit = 100): array
    {
        $this->authorize($context, 'handoff.run');
        if ($limit < 1 || $limit > 250) {
            throw new DomainError('batch_size_invalid');
        }
        $visible = $this->instances->authorizedQuery($context)->select('id');
        $items = $this->db->table('tm_handoffs')->where('scope_key', $context->scope)->whereIn('status', ['active', 'detached'])
            ->whereIn('origin_id', clone $visible)->whereIn('destination_id', clone $visible)
            ->where(function ($query) {
                $query->whereIn('destination_id', $this->db->table('tm_workflow_instances')->where('lifecycle', 'closed')->select('id'))
                    ->orWhere(fn ($due) => $due->where('status', 'active')->where('deadline_at', '<=', $this->now()));
            })->orderBy('id')->limit($limit)->get();
        $processed = 0;
        $conflicts = 0;
        foreach ($items as $item) {
            try {
                $closed = $this->db->table('tm_workflow_instances')->where('id', $item->destination_id)->value('lifecycle') === 'closed';
                $operation = 'handoff-worker:'.$item->handoff_key.':'.$item->version;
                $result = $closed ? $this->settle($context, $item->handoff_key, $item->version, $operation) : $this->recall($context, $item->handoff_key, $item->version, $operation, true);
                $processed++;
                if ($result['status'] === 'return_conflict') {
                    $conflicts++;
                }
            } catch (DomainError $error) {
                if (! in_array($error->getMessage(), ['version_conflict', 'handoff_already_resolved', 'not_found', 'transition_invalid'], true)) {
                    throw $error;
                }
                $conflicts++;
            }
        }

        return ['processed' => $processed, 'conflicts' => $conflicts];
    }

    private function locked(ExecutionContext $context, string $key): array
    {
        $before = $this->get($context, $key);
        $instances = [];
        $ids = [$before['origin_id'], $before['destination_id']];
        sort($ids, SORT_NUMERIC);
        foreach ($ids as $id) {
            $row = (array) $this->db->table('tm_workflow_instances')->where('scope_key', $context->scope)->where('id', $id)->first();
            [$instances[$id]] = $this->instances->lockForCommand($context, $row['instance_key'], $row['version']);
        }
        $handoff = (array) $this->db->table('tm_handoffs')->where('id', $before['id'])->lockForUpdate()->first();

        return [$handoff, $instances[$before['origin_id']], $instances[$before['destination_id']]];
    }

    private function returnOrigin(ExecutionContext $context, array $handoff, array $origin, array $destination, string $outcome, string $operation, bool $manualJoin = false): array
    {
        $memberOutcome = $outcome;
        if ($handoff['outcome'] !== null && $handoff['outcome'] !== $outcome) {
            throw new DomainError('handoff_already_resolved');
        }
        $evidence = $this->db->table('tm_handoff_outcomes')->where('handoff_id', $handoff['id'])->first();
        if ($evidence !== null && ($evidence->outcome !== $outcome || $evidence->destination_version !== $destination['version'])) {
            throw new DomainError('handoff_already_resolved');
        }
        if ($evidence === null) {
            $this->db->table('tm_handoff_outcomes')->insert(['handoff_id' => $handoff['id'], 'outcome' => $outcome, 'destination_version' => $destination['version'], 'actor_ref' => $context->actor, 'operation_id' => $operation, 'occurred_at' => $this->now()]);
        }
        if ($handoff['group_id'] !== null) {
            $group = $this->db->table('tm_handoff_groups')->where('id', $handoff['group_id'])->lockForUpdate()->first();
            $join = json_decode($group->join_policy, true, flags: JSON_THROW_ON_ERROR);
            $this->db->table('tm_handoffs')->where('id', $handoff['id'])->update(['outcome' => $outcome]);
            $outcomes = $this->db->table('tm_handoff_outcomes')->whereIn('handoff_id', $this->db->table('tm_handoffs')->where('group_id', $group->id)->select('id'))->pluck('outcome')->all();
            $qualified = array_values(array_filter($outcomes, fn ($value) => in_array($value, $join['outcomes'], true)));
            $closedGroup = in_array($group->status, ['resolved', 'manually_closed'], true);
            if ($manualJoin && ($join['mode'] !== 'manual' || ! $qualified || $closedGroup)) {
                throw new DomainError('handoff_join_invalid');
            }
            if ($closedGroup || (! $manualJoin && count($qualified) < $join['quorum'])) {
                $this->db->table('tm_handoffs')->where('id', $handoff['id'])->update(['status' => $closedGroup ? 'detached_resolved' : 'awaiting_join', 'version' => $handoff['version'] + 1, 'resolved_at' => $this->now()]);
                if (! $closedGroup) {
                    $state = $join['mode'] === 'manual' ? 'awaiting_manual' : (count($outcomes) === $group->expected_count ? 'manual_review' : $group->status);
                    $this->db->table('tm_handoff_groups')->where('id', $group->id)->update(['status' => $state, 'version' => $group->version + 1]);
                }
                $this->event($context, $origin, 'handoff_outcome_recorded', $operation, ['handoff_key' => $handoff['handoff_key'], 'outcome' => $outcome, 'group_id' => $group->id]);

                return $this->get($context, $handoff['handoff_key']);
            }
            $outcome = current(array_values(array_filter($join['priority'], fn ($candidate) => in_array($candidate, $qualified, true))));
        }
        $policy = json_decode($handoff['policy_snapshot'], true, flags: JSON_THROW_ON_ERROR);
        $status = 'resolved';
        $failure = null;
        try {
            $this->db->transaction(function () use ($context, $handoff, $origin, $destination, $outcome, $operation, $policy) {
                if ($origin['version'] !== ($handoff['repair_origin_version'] ?? $handoff['origin_version']) || $origin['lifecycle'] !== 'waiting') {
                    throw new DomainError('handoff_return_conflict');
                }
                $return = $policy['returns'][$outcome] ?? null;
                if ($return === null) {
                    throw new DomainError('handoff_return_policy_missing');
                }
                $this->db->table('tm_workflow_instances')->where('id', $origin['id'])->update(['lifecycle' => 'active']);
                if ($handoff['paused_visit_id'] !== null) {
                    $visit = $this->sla->get($context, $handoff['paused_visit_id']);
                    $resumed = $this->sla->resume($context, $visit['id'], $visit['sla_version'], $this->child($operation, 'resume'));
                    if ($policy['sla'] === 'reset') {
                        $this->sla->reset($context, $visit['id'], $resumed['sla_version'], $this->child($operation, 'reset'));
                    }
                }
                $result = $this->instances->transition($context, $origin['instance_key'], $origin['version'], $this->child($operation, 'return'), $return['transition'], isset($return['reason_code']) ? ['reason_code' => $return['reason_code']] : []);
                if ($result['lifecycle'] !== 'closed' && $policy['assignment'] !== 'resolve_stage') {
                    $snapshot = $policy['assignment'] === 'restore_origin' ? json_decode($handoff['assignment_snapshot'], true, flags: JSON_THROW_ON_ERROR)
                        : ($destination['assignment_snapshot'] === null ? ['primary' => $destination['primary_assignee'], 'secondary' => [], 'policy_id' => null, 'max_assignees' => 1, 'allow_unassigned' => false] : json_decode($destination['assignment_snapshot'], true, flags: JSON_THROW_ON_ERROR));
                    $definition = $this->assignments->validate(['max_assignees' => $snapshot['max_assignees'], 'allow_unassigned' => $snapshot['allow_unassigned']]);
                    $this->assignments->assign($context, $result, $definition, $snapshot['policy_id'], $snapshot['primary'], $snapshot['secondary'], 'handoff_return');
                }
                $this->db->table('tm_active_handoff_origins')->where('origin_id', $origin['id'])->delete();
            });
        } catch (DomainError $error) {
            $status = 'return_conflict';
            $failure = preg_match('/^[a-z][a-z0-9_.-]{0,99}$/D', $error->getMessage()) ? $error->getMessage() : 'host_guard_denied';
        }
        $this->db->table('tm_handoffs')->where('id', $handoff['id'])->update(['outcome' => $memberOutcome, 'status' => $status, 'failure_code' => $failure,
            'version' => $handoff['version'] + 1, 'resolved_at' => $status === 'resolved' ? $this->now() : null]);
        if ($handoff['group_id'] !== null) {
            $this->db->table('tm_handoff_groups')->where('id', $handoff['group_id'])->update(['status' => $status, 'outcome' => $outcome, 'version' => $group->version + 1]);
            if ($status === 'resolved') {
                $this->db->table('tm_handoffs')->where('group_id', $handoff['group_id'])->where('id', '!=', $handoff['id'])->whereNull('outcome')->update(['status' => 'detached']);
            }
        }
        $this->event($context, $origin, $status === 'resolved' ? 'handoff_returned' : 'handoff_return_conflict', $operation, ['handoff_key' => $handoff['handoff_key'], 'outcome' => $outcome, 'reason_code' => $failure]);

        return $this->get($context, $handoff['handoff_key']);
    }

    private function command(ExecutionContext $context, string $operation, array $payload, callable $execute): array
    {
        return (new CommandExecutor($this->db))->execute($context, $operation, $payload, $execute, fn ($r) => $this->get($context, $r['handoff_key']));
    }

    private function child(string $operation, string $suffix): string
    {
        return 'handoff-'.$suffix.':'.hash('sha256', $operation);
    }

    private function now(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function event(ExecutionContext $context, array $origin, string $action, string $operation, array $payload): void
    {
        (new EventRecorder($this->db, $this->clock))->append($context, 'instance', $origin['instance_key'], $action, $operation, $payload, $origin['task_id']);
    }
}
