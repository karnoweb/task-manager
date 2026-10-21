<?php

namespace Karnoweb\TaskManager\Approval;

use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Karnoweb\TaskManager\CommandExecutor;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\AuthorizationProvider;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\EventRecorder;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Workflow\Definition;
use Karnoweb\TaskManager\Workflow\InstanceService;
use Karnoweb\TaskManager\Workflow\PipelineService;

final class ApprovalService
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization,
        private readonly ApprovalEngine $engine, private readonly InstanceService $instances, private readonly PipelineService $pipelines)
    {
    }

    private function authorize(ExecutionContext $context, string $capability): void
    {
        $context->require($capability);
        $this->authorization->authorize($context, $capability);
    }

    private function definition(ExecutionContext $context, int $revisionId): array
    {
        $row = $this->db->table('tm_workflow_definitions')->where('scope_key', $context->scope)->where('id', $revisionId)->first();
        if (! $row) {
            throw new DomainError('not_found');
        }

return Definition::decode($row->definition);
    }

    public function get(ExecutionContext $context, string $key): array
    {
        if (! Str::isUuid($key)) {
            throw new DomainError('not_found');
        }
        $step = $this->db->table('tm_approval_steps')->where('scope_key', $context->scope)->where('step_key', $key)
            ->whereIn('instance_id', $this->instances->authorizedQuery($context)->select('id'))->first();
        if (! $step) {
            throw new DomainError('not_found');
        }
        $instance = $this->db->table('tm_workflow_instances')->where('id', $step->instance_id)->first();

        return [...(array) $step, 'instance_key' => $instance->instance_key, 'instance_version' => $instance->version, 'policy' => $this->engine->policy($step),
            'votes' => $this->db->table('tm_approval_votes')->where('step_id', $step->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()];
    }

    public function active(ExecutionContext $context, string $instanceKey): ?array
    {
        $instance = $this->instances->get($context, $instanceKey);
        $id = $this->db->table('tm_active_approval_steps')->where('instance_id', $instance['id'])->value('step_id');
        if ($id === null) {
            return null;
        }

        return $this->get($context, $this->db->table('tm_approval_steps')->where('id', $id)->value('step_key'));
    }

    public function begin(ExecutionContext $context, string $key, int $version, string $operation, array $policy): array
    {
        $this->authorize($context, 'approval.manage');

        return $this->command($context, $operation, ['action' => 'approval_begin', 'instance' => $key, 'version' => $version, 'policy' => $policy], function () use ($context, $key, $version, $operation, $policy) {
            [$instance] = $this->instances->lockForCommand($context, $key, $version);
            if ($instance['lifecycle'] !== 'active') {
                throw new DomainError('instance_inactive');
            }
            $definition = $this->engine->validate($policy, $this->definition($context, $instance['workflow_revision_id']), $instance['current_state_key']);
            $id = $this->db->table('tm_approval_policies')->insertGetId(['scope_key' => $context->scope, 'definition' => json_encode($definition, JSON_THROW_ON_ERROR)]);
            $created = $this->engine->create($context, $instance, $id, $operation);
            $this->db->table('tm_workflow_instances')->where('id', $instance['id'])->increment('version');
            if ($instance['task_id'] !== null) {
                $this->db->table('tm_tasks')->where('id', $instance['task_id'])->increment('version');
            }

            return $this->get($context, $created['step_key']);
        });
    }

    public function configureStage(ExecutionContext $context, string $pipelineKey, string $stageKey, int $version, string $operation, array $policy): array
    {
        $this->authorize($context, 'approval.manage');

        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'stage_approval_policy', 'pipeline' => $pipelineKey, 'stage' => $stageKey, 'version' => $version, 'policy' => $policy], function () use ($context, $pipelineKey, $stageKey, $version, $operation, $policy) {
            $pipeline = $this->pipelines->get($context, $pipelineKey);
            $row = $this->db->table('tm_pipelines')->where('id', $pipeline['id'])->lockForUpdate()->first();
            if ($row->version !== $version) {
                throw new DomainError('version_conflict');
            }
            $stage = $this->db->table('tm_pipeline_stages')->where('pipeline_id', $pipeline['id'])->where('stage_key', $stageKey)->lockForUpdate()->first();
            if (! $stage) {
                throw new DomainError('not_found');
            }
            $workflow = $this->definition($context, $pipeline['workflow_revision_id']);
            if (($workflow['states'][$stage->state_key]['terminal'] ?? 'none') !== 'none') {
                throw new DomainError('approval_stage_terminal');
            }
            $definition = $this->engine->validate($policy, $workflow, $stage->state_key);
            $revision = ($this->db->table('tm_approval_policies')->where('stage_id', $stage->id)->max('revision') ?? 0) + 1;
            $id = $this->db->table('tm_approval_policies')->insertGetId(['scope_key' => $context->scope, 'stage_id' => $stage->id, 'revision' => $revision, 'definition' => json_encode($definition, JSON_THROW_ON_ERROR)]);
            $this->db->table('tm_pipeline_stages')->where('id', $stage->id)->update(['approval_policy_id' => $id]);
            $this->db->table('tm_pipelines')->where('id', $pipeline['id'])->increment('version');
            (new EventRecorder($this->db, $this->clock))->append($context, 'pipeline', $pipelineKey, 'approval_policy_published', $operation, ['policy_id' => $id, 'stage' => $stageKey]);

            return ['id' => $id, 'pipeline_key' => $pipelineKey, 'revision' => $revision];
        }, fn ($r) => $this->pipelines->get($context, $r['pipeline_key']));
    }

    public function vote(ExecutionContext $context, string $stepKey, int $stepVersion, int $instanceVersion, string $operation, string $vote, ?string $reason = null): array
    {
        $this->authorize($context, 'approval.vote');
        if (! in_array($vote, ['approve', 'reject'], true)) {
            throw new DomainError('approval_vote_invalid');
        }

        return $this->command($context, $operation, ['action' => 'approval_vote', 'step' => $stepKey, 'step_version' => $stepVersion, 'instance_version' => $instanceVersion, 'vote' => $vote, 'reason' => $reason], function () use ($context, $stepKey, $stepVersion, $instanceVersion, $operation, $vote, $reason) {
            $visible = $this->get($context, $stepKey);
            [$instance] = $this->instances->lockForCommand($context, $visible['instance_key'], $instanceVersion, 'view');
            $step = $this->db->table('tm_approval_steps')->where('id', $visible['id'])->lockForUpdate()->first();
            $policy = $this->engine->policy($step);
            $this->engine->assertVoter($context, $step, $policy);
            $prior = $this->db->table('tm_approval_votes')->where('step_id', $step->id)->where('actor_ref', $context->actor)->first();
            if ($prior) {
                if ($prior->vote !== $vote || $prior->reason_code !== $reason) {
                    throw new DomainError('approval_vote_already_cast');
                }

                return $this->get($context, $stepKey);
            }
            if ($step->state !== 'pending') {
                throw new DomainError('approval_already_resolved');
            }
            if ($step->version !== $stepVersion) {
                throw new DomainError('version_conflict');
            }
            if ($vote === 'reject' && ! in_array($reason, $policy['reject_reasons'], true)) {
                throw new DomainError('approval_reason_required');
            }
            $votes = $this->db->table('tm_approval_votes')->where('step_id', $step->id)->get();
            if ($policy['mode'] === 'sequential') {
                $remaining = array_values(array_diff($policy['approvers'], $votes->pluck('actor_ref')->all()));
                if (($remaining[0] ?? null) !== $context->actor) {
                    throw new DomainError('approval_sequence_violation');
                }
            }
            $this->db->table('tm_approval_votes')->insert(['step_id' => $step->id, 'actor_ref' => $context->actor, 'vote' => $vote, 'reason_code' => $reason, 'operation_id' => $operation, 'occurred_at' => $this->now()]);
            $approvals = $votes->where('vote', 'approve')->count() + ($vote === 'approve' ? 1 : 0);
            $state = $vote === 'reject' ? 'rejected' : ($approvals >= $policy['quorum'] ? 'approved' : 'pending');
            $changes = ['version' => $stepVersion + 1, 'state' => $state];
            if ($state !== 'pending') {
                $changes += ['execution_state' => 'pending', 'decided_at' => $this->now(), 'decision_instance_version' => $instance['version']];
            }
            $this->db->table('tm_approval_steps')->where('id', $step->id)->update($changes);
            $this->event($context, $instance, 'approval_vote_recorded', $operation, ['step_key' => $stepKey, 'actor_ref' => $context->actor, 'vote' => $vote, 'reason_code' => $reason]);
            if ($state !== 'pending') {
                $updated = $this->db->table('tm_approval_steps')->where('id', $step->id)->first();
                $this->executeWithin($context, $updated, $instance, $policy, $operation, $reason);
                $this->event($context, $instance, 'approval_decided', $operation, ['step_key' => $stepKey, 'state' => $state,
                    'execution_state' => $this->db->table('tm_approval_steps')->where('id', $step->id)->value('execution_state')]);
            }

            return $this->get($context, $stepKey);
        });
    }

    public function execute(ExecutionContext $context, string $stepKey, int $stepVersion, int $instanceVersion, string $operation): array
    {
        $this->authorize($context, 'approval.execute');
        $this->authorize($context, 'workflow.transition');

        return $this->command($context, $operation, ['action' => 'approval_execute', 'step' => $stepKey, 'step_version' => $stepVersion, 'instance_version' => $instanceVersion], function () use ($context, $stepKey, $stepVersion, $instanceVersion, $operation) {
            $visible = $this->get($context, $stepKey);
            [$instance] = $this->instances->lockForCommand($context, $visible['instance_key'], $instanceVersion);
            $step = $this->db->table('tm_approval_steps')->where('id', $visible['id'])->lockForUpdate()->first();
            if ($step->execution_state === 'executed') {
                return $this->get($context, $stepKey);
            }
            if ($step->version !== $stepVersion) {
                throw new DomainError('version_conflict');
            }
            if ($step->state === 'pending') {
                throw new DomainError('approval_pending');
            }
            if (! in_array($step->state, ['approved', 'rejected'], true)) {
                throw new DomainError('approval_not_decided');
            }
            if ($step->decision_instance_version !== $instanceVersion) {
                throw new DomainError('approval_execution_conflict');
            }
            $policy = $this->engine->policy($step);
            $reason = $this->db->table('tm_approval_votes')->where('step_id', $step->id)->where('vote', 'reject')->value('reason_code');
            $this->executeWithin($context, $step, $instance, $policy, $operation, $reason);
            $this->db->table('tm_approval_steps')->where('id', $step->id)->increment('version');
            $this->event($context, $instance, 'approval_execution_attempted', $operation, ['step_key' => $stepKey]);

            return $this->get($context, $stepKey);
        });
    }

    private function executeWithin(ExecutionContext $context, object $step, array $instance, array $policy, string $operation, ?string $rejectedReason): void
    {
        $edge = $step->state === 'approved' ? $policy['approved_transition'] : $policy['rejected_transition'];
        if ($edge === null) {
            $this->db->table('tm_approval_steps')->where('id', $step->id)->update(['execution_state' => 'manual_review']);

            return;
        }
        if (! in_array('workflow.transition', $context->capabilities, true)) {
            $this->event($context, $instance, 'approval_action_requested', $operation, ['step_key' => $step->step_key]);

            return;
        }
        try {
            $reason = $step->state === 'approved' ? $policy['approved_reason'] : $rejectedReason;
            $this->instances->transition($context, $instance['instance_key'], $instance['version'], $this->internalOperation($operation), $edge, $reason === null ? [] : ['reason_code' => $reason]);
            $this->db->table('tm_active_approval_steps')->where('step_id', $step->id)->delete();
            $this->db->table('tm_approval_steps')->where('id', $step->id)->update(['execution_state' => 'executed']);
        } catch (DomainError $error) {
            $this->db->table('tm_approval_steps')->where('id', $step->id)->update(['execution_state' => 'manual_review']);
            $this->event($context, $instance, 'approval_action_requested', $operation, ['step_key' => $step->step_key, 'reason_code' => 'transition_denied']);
        }
    }

    public function cancel(ExecutionContext $context, string $stepKey, int $stepVersion, int $instanceVersion, string $operation, string $reason): array
    {
        $this->authorize($context, 'approval.manage');
        if (! in_array($reason, ['manual_resolution', 'approver_unavailable', 'request_recalled'], true)) {
            throw new DomainError('approval_reason_required');
        }

        return $this->command($context, $operation, ['action' => 'approval_cancel', 'step' => $stepKey, 'step_version' => $stepVersion, 'instance_version' => $instanceVersion, 'reason' => $reason], function () use ($context, $stepKey, $stepVersion, $instanceVersion, $operation, $reason) {
            $visible = $this->get($context, $stepKey);
            [$instance] = $this->instances->lockForCommand($context, $visible['instance_key'], $instanceVersion);
            $step = $this->db->table('tm_approval_steps')->where('id', $visible['id'])->lockForUpdate()->first();
            if ($step->version !== $stepVersion) {
                throw new DomainError('version_conflict');
            }
            if ($step->execution_state === 'executed' || $step->state === 'cancelled') {
                throw new DomainError('approval_already_resolved');
            }
            $this->db->table('tm_approval_steps')->where('id', $step->id)->update(['state' => 'cancelled', 'execution_state' => 'cancelled', 'version' => $stepVersion + 1, 'decided_at' => $this->now()]);
            $this->db->table('tm_active_approval_steps')->where('step_id', $step->id)->delete();
            $this->db->table('tm_workflow_instances')->where('id', $instance['id'])->increment('version');
            if ($instance['task_id'] !== null) {
                $this->db->table('tm_tasks')->where('id', $instance['task_id'])->increment('version');
            }
            $this->event($context, $instance, 'approval_cancelled', $operation, ['step_key' => $stepKey, 'reason_code' => $reason]);

            return $this->get($context, $stepKey);
        });
    }

    private function internalOperation(string $operation): string
    {
        return 'approval-transition:'.hash('sha256', $operation);
    }

    private function command(ExecutionContext $context, string $operation, array $payload, callable $execute): array
    {
        try {
            return (new CommandExecutor($this->db))->execute($context, $operation, $payload, $execute, fn ($r) => $this->get($context, $r['step_key']));
        } catch (DomainError $e) {
            $reason = preg_match('/^[a-z][a-z0-9_]{0,79}$/D', $e->getMessage()) ? $e->getMessage() : 'approval_denied';
            $this->db->table('tm_security_decisions')->insert(['scope_key' => $context->scope, 'actor_ref' => $context->actor, 'action' => $payload['action'], 'reason' => $reason,
                'target_hash' => hash('sha256', $payload['step'] ?? $payload['instance'] ?? ''), 'occurred_at' => $this->now()]);
            throw $e;
        }
    }

    private function event(ExecutionContext $context, array $instance, string $action, string $operation, array $payload): void
    {
        (new EventRecorder($this->db, $this->clock))->append($context, 'instance', $instance['instance_key'], $action, $operation, $payload, $instance['task_id']);
    }

    private function now(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
