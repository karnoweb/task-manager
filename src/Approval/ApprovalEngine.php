<?php

namespace Karnoweb\TaskManager\Approval;

use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Context\TenantBoundary;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\Contracts\ResponsibilityResolver;
use Karnoweb\TaskManager\Contracts\StageLifecycle;
use Karnoweb\TaskManager\EventRecorder;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Schema\Tables;

final class ApprovalEngine implements StageLifecycle
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly ResponsibilityResolver $resolver)
    {
        Tables::assertConnection($db);
    }

    public function validate(array $policy, array $workflow, string $state): array
    {
        $policy += ['mode' => 'parallel', 'forbid_self' => true, 'reject_reasons' => ['rejected'], 'approved_reason' => null, 'rejected_transition' => null];
        if (array_diff(array_keys($policy), ['mode', 'forbid_self', 'reject_reasons', 'approved_reason', 'rejected_transition', 'approved_transition', 'approvers', 'quorum'])) {
            throw new DomainError('approval_policy_invalid');
        }
        if (! in_array($policy['mode'], ['sequential', 'parallel', 'quorum'], true) || ! is_array($policy['approvers'] ?? null) || ! array_is_list($policy['approvers'])
            || ! $policy['approvers'] || count($policy['approvers']) > 100 || ! is_bool($policy['forbid_self']) || ! is_array($policy['reject_reasons'])) {
            throw new DomainError('approval_policy_invalid');
        }
        foreach ($policy['approvers'] as $actor) {
            if (! is_string($actor) || trim($actor) === '' || strlen($actor) > 120) {
                throw new DomainError('approver_invalid');
            }
        }
        if (count(array_unique($policy['approvers'])) !== count($policy['approvers'])) {
            throw new DomainError('duplicate_approver');
        }
        $policy['quorum'] = $policy['mode'] === 'quorum' ? ($policy['quorum'] ?? null) : count($policy['approvers']);
        if (! is_int($policy['quorum']) || $policy['quorum'] < 1 || $policy['quorum'] > count($policy['approvers'])) {
            throw new DomainError('approval_quorum_invalid');
        }
        if (! is_string($policy['approved_transition'] ?? null)) {
            throw new DomainError('approval_transition_required');
        }
        foreach (['approved_transition', 'rejected_transition'] as $field) {
            $edge = $policy[$field] ?? null;
            if ($edge === null && $field === 'rejected_transition') {
                continue;
            }
            if (! is_string($edge) || ! isset($workflow['transitions'][$edge]) || $workflow['transitions'][$edge]['from'] !== $state) {
                throw new DomainError('approval_transition_invalid');
            }
        }
        foreach ($policy['reject_reasons'] as $reason) {
            if (! is_string($reason) || ! preg_match('/^[a-z][a-z0-9_]{0,99}$/D', $reason)) {
                throw new DomainError('approval_reason_invalid');
            }
        }
        if ($policy['approved_reason'] !== null && (! is_string($policy['approved_reason']) || ! preg_match('/^[a-z][a-z0-9_.-]{0,99}$/D', $policy['approved_reason']))) {
            throw new DomainError('approval_reason_invalid');
        }

        return $policy;
    }

    public function create(ExecutionContext $context, array $instance, int $policyId, string $operation, ?int $visitId = null): array
    {
        $context->validateTenant();
        $row = $this->db->table(Tables::name('approval_policies'))->where('scope_key', $context->scope)->where('id', $policyId)->first();
        if (! $row) {
            throw new DomainError('approval_policy_missing');
        } $policy = json_decode($row->definition, true, flags: JSON_THROW_ON_ERROR);
        $eligible = array_filter($policy['approvers'], fn ($actor) => (! $policy['forbid_self'] || $actor !== $context->actor) && $this->resolver->eligible($context, $actor, 'approval'));
        if (count($eligible) < $policy['quorum']) {
            throw new DomainError('approval_unresolvable');
        }
        foreach ($eligible as $actor) {
            TenantBoundary::validateActor($context, $actor);
        }
        if ($this->db->table(Tables::name('active_approval_steps'))->where('instance_id', $instance['id'])->exists()) {
            throw new DomainError('approval_already_active');
        }
        $key = (string) Str::uuid();
        $id = $this->db->table(Tables::name('approval_steps'))->insertGetId(['step_key' => $key, 'scope_key' => $context->scope, 'instance_id' => $instance['id'], 'policy_id' => $policyId,
            'visit_id' => $visitId, 'requested_by' => $context->actor, 'created_at' => $this->now()]);
        $this->db->table(Tables::name('active_approval_steps'))->insert(['instance_id' => $instance['id'], 'step_id' => $id]);
        (new EventRecorder($this->db, $this->clock))->append($context, 'instance', $instance['instance_key'], 'approval_started', $operation, ['step_key' => $key, 'mode' => $policy['mode'], 'quorum' => $policy['quorum']], $instance['task_id']);

        return ['id' => $id, 'step_key' => $key, 'instance_key' => $instance['instance_key']];
    }

    public function policy(object $step): array
    {
        $row = $this->db->table(Tables::name('approval_policies'))->where('scope_key', $step->scope_key)->where('id', $step->policy_id)->first();
        if (! $row) {
            throw new DomainError('approval_policy_missing');
        }

        return json_decode($row->definition, true, flags: JSON_THROW_ON_ERROR);
    }

    public function assertVoter(ExecutionContext $context, object $step, array $policy): void
    {
        $context->validateTenant();
        if (! in_array($context->actor, $policy['approvers'], true) || ($policy['forbid_self'] && $context->actor === $step->requested_by)
            || ! $this->resolver->eligible($context, $context->actor, 'approval')) {
            throw new DomainError('approval_vote_denied');
        }
    }

    public function beforeTransition(ExecutionContext $context, array $instance, array $edge, array $payload): void
    {
        $context->validateTenant();
        $active = $this->db->table(Tables::name('active_approval_steps'))->where('instance_id', $instance['id'])->first();
        if (! $active) {
            return;
        }
        if (($edge['from'] ?? null) === ($edge['to'] ?? null) && ! ($edge['force_exit'] ?? false)) {
            return;
        }
        $step = $this->db->table(Tables::name('approval_steps'))->where('id', $active->step_id)->where('scope_key', $context->scope)->first();
        if (! $step || $step->state === 'pending') {
            throw new DomainError('approval_pending');
        }
        if (! in_array($step->state, ['approved', 'rejected'], true)) {
            throw new DomainError('approval_manual_resolution_required');
        }
        if ($edge['force_exit'] ?? false) {
            throw new DomainError('approval_outcome_transition_required');
        }
        $policy = $this->policy($step);
        $expected = $step->state === 'approved' ? $policy['approved_transition'] : $policy['rejected_transition'];
        if ($expected === null) {
            throw new DomainError('approval_manual_resolution_required');
        }
        if (($edge['key'] ?? '') !== $expected) {
            throw new DomainError('approval_outcome_transition_required');
        }
    }

    public function entered(ExecutionContext $context, int $instanceId, int $visitId, object $stage, string $operationId): void
    {
        $context->validateTenant();
        if ($stage->approval_policy_id === null) {
            return;
        }
        $instance = (array) $this->db->table(Tables::name('workflow_instances'))->where('scope_key', $context->scope)->where('id', $instanceId)->first();
        $this->create($context, $instance, $stage->approval_policy_id, $operationId, $visitId);
    }

    public function leaving(ExecutionContext $context, int $instanceId): void
    {
        $context->validateTenant();
        $active = $this->db->table(Tables::name('active_approval_steps'))->where('instance_id', $instanceId)->first();
        if ($active) {
            $step = $this->db->table(Tables::name('approval_steps'))->where('id', $active->step_id)->first();
            if ($step->state === 'pending') {
                throw new DomainError('approval_pending');
            }
            $this->db->table(Tables::name('approval_steps'))->where('id', $step->id)->update(['execution_state' => 'executed']);
            $this->db->table(Tables::name('active_approval_steps'))->where('step_id', $step->id)->delete();
        }
    }

    public function activated(ExecutionContext $context, int $instanceId, string $operationId): void
    {
        $context->validateTenant();
    }

    public function activity(ExecutionContext $context, int $instanceId): void
    {
        $context->validateTenant();
    }

    private function now(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
