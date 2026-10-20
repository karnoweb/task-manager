<?php

namespace Karnoweb\TaskManager\Responsibility;

use Illuminate\Database\Connection;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\Contracts\ResponsibilityResolver;
use Karnoweb\TaskManager\Contracts\StageLifecycle;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\EventRecorder;

final class AssignmentEngine implements StageLifecycle
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly ResponsibilityResolver $resolver) {}
    public function validate(array $policy): array
    {
        $policy += ['mode' => 'fixed', 'max_assignees' => 1, 'secondary' => [], 'allow_unassigned' => false];
        if (!in_array($policy['mode'], ['fixed', 'manual', 'follow_owner', 'team', 'round_robin'], true) || !is_int($policy['max_assignees']) || $policy['max_assignees'] < 1 || $policy['max_assignees'] > 100
            || !is_array($policy['secondary']) || !array_is_list($policy['secondary']) || !is_bool($policy['allow_unassigned'])) { throw new DomainError('assignment_policy_invalid'); }
        foreach ($policy['secondary'] as $actor) { $this->actor($actor); }
        if (array_key_exists('primary', $policy) && $policy['primary'] !== null) { $this->actor($policy['primary']); }
        if (in_array($policy['mode'], ['team', 'round_robin'], true) && (!is_string($policy['reference'] ?? null) || trim($policy['reference']) === '' || strlen($policy['reference']) > 120)) { throw new DomainError('assignment_reference_required'); }
        return $policy;
    }
    private function actor(mixed $actor): void
    {
        if (!is_string($actor) || trim($actor) === '' || strlen($actor) > 120) { throw new DomainError('assignee_invalid'); }
    }
    public function policy(ExecutionContext $context, array $instance): array
    {
        $id = $instance['assignment_policy_id'];
        if ($id === null) { $id = $this->db->table('tm_stage_visits')->where('instance_id', $instance['id'])->whereNull('exited_at')->value('assignment_policy_id'); }
        if ($id === null) { return ['id' => null, 'definition' => $this->validate([])]; }
        $row = $this->db->table('tm_assignment_policies')->where('scope_key', $context->scope)->where('id', $id)->first();
        if (!$row) { throw new DomainError('assignment_policy_missing'); }
        return ['id' => $row->id, 'definition' => json_decode($row->definition, true, flags: JSON_THROW_ON_ERROR)];
    }
    public function resolve(ExecutionContext $context, array $instance, array $policy): ?string
    {
        if ($policy['mode'] === 'follow_owner') { return $instance['owner_ref']; }
        if (!in_array($policy['mode'], ['team', 'round_robin'], true)) { return array_key_exists('primary', $policy) ? $policy['primary'] : ($instance['primary_assignee'] ?? $instance['owner_ref']); }
        $candidates = $this->resolver->candidates($context, $policy['reference'], 'assignment'); foreach ($candidates as $actor) { $this->actor($actor); }
        $candidates = array_values(array_unique($candidates)); sort($candidates, SORT_STRING);
        $candidates = array_values(array_filter($candidates, fn ($a) => is_string($a) && $this->resolver->eligible($context, $a, 'assignment')));
        if (!$candidates) { throw new DomainError('assignment_unresolvable'); }
        if ($policy['mode'] === 'team') { return $candidates[0]; }
        $key = ['scope_key' => $context->scope, 'reference_key' => hash('sha256', $policy['reference'])];
        $this->db->table('tm_assignment_queues')->insertOrIgnore($key + ['position' => 0]);
        $queue = $this->db->table('tm_assignment_queues')->where($key)->lockForUpdate()->first();
        $chosen = $candidates[$queue->position % count($candidates)]; $this->db->table('tm_assignment_queues')->where($key)->increment('position');
        return $chosen;
    }
    public function assign(ExecutionContext $context, array $instance, array $policy, ?int $policyId, ?string $primary, array $secondary, string $reason, ?int $visitId = null, bool $validateEligibility = true): void
    {
        if ($this->db->transactionLevel() < 1) { throw new DomainError('assignment_requires_transaction'); }
        if ($primary === null && !$policy['allow_unassigned']) { throw new DomainError('primary_assignee_required'); }
        if (!array_is_list($secondary)) { throw new DomainError('assignee_invalid'); }
        $actors = $primary === null ? $secondary : [$primary, ...$secondary];
        foreach ($actors as $actor) { $this->actor($actor); }
        if (count($actors) > $policy['max_assignees']) { throw new DomainError('assignee_capacity_exceeded'); }
        if (count(array_unique($actors)) !== count($actors)) { throw new DomainError('duplicate_assignee'); }
        foreach ($actors as $actor) {
            $this->actor($actor); if ($validateEligibility && !$this->resolver->eligible($context, $actor, 'assignment')) { throw new DomainError('assignment_unresolvable'); }
        }
        $now = $this->now();
        $this->close($context, $instance['id']);
        foreach ($actors as $actor) {
            $kind = $actor === $primary ? 'primary' : 'secondary';
            $assignment = $this->db->table('tm_instance_assignments')->insertGetId(['scope_key' => $context->scope, 'instance_id' => $instance['id'], 'actor_ref' => $actor,
                'kind' => $kind, 'active_from' => $now, 'assigned_by' => $context->actor, 'reason_code' => $reason, 'policy_id' => $policyId, 'visit_id' => $visitId]);
            $this->db->table('tm_assignment_slots')->insert(['instance_id' => $instance['id'], 'slot_key' => $kind === 'primary' ? 'primary' : 'secondary:'.hash('sha256', $actor), 'actor_ref' => $actor, 'assignment_id' => $assignment]);
        }
        $this->db->table('tm_workflow_instances')->where('id', $instance['id'])->update(['primary_assignee' => $primary,
            'assignment_snapshot' => json_encode(['primary' => $primary, 'secondary' => $secondary, 'policy_id' => $policyId, 'max_assignees' => $policy['max_assignees'], 'allow_unassigned' => $policy['allow_unassigned']], JSON_THROW_ON_ERROR)]);
    }
    public function activated(ExecutionContext $context, int $instanceId, string $operationId): void
    {
        $instance = (array) $this->db->table('tm_workflow_instances')->where('scope_key', $context->scope)->where('id', $instanceId)->first();
        if ($instance['assignment_snapshot'] === null) { return; }
        $snapshot = json_decode($instance['assignment_snapshot'], true, flags: JSON_THROW_ON_ERROR);
        $policy = $this->validate(['max_assignees' => $snapshot['max_assignees'], 'allow_unassigned' => $snapshot['allow_unassigned']]);
        $this->assign($context, $instance, $policy, $snapshot['policy_id'], $snapshot['primary'], $snapshot['secondary'], 'reopened');
        (new EventRecorder($this->db, $this->clock))->append($context, 'instance', $instance['instance_key'], 'assignment_restored', $operationId,
            ['primary' => $snapshot['primary'], 'secondary' => $snapshot['secondary']], $instance['task_id']);
    }
    public function entered(ExecutionContext $context, int $instanceId, int $visitId, object $stage, string $operationId): void
    {
        $instance = (array) $this->db->table('tm_workflow_instances')->where('scope_key', $context->scope)->where('id', $instanceId)->first();
        $policyId = $instance['assignment_policy_id'] ?? $stage->assignment_policy_id;
        if ($policyId === null) {
            $snapshot = $instance['assignment_snapshot'] === null ? null : json_decode($instance['assignment_snapshot'], true, flags: JSON_THROW_ON_ERROR);
            $definition = $this->validate(['max_assignees' => $snapshot['max_assignees'] ?? 1, 'allow_unassigned' => $snapshot['allow_unassigned'] ?? false]);
            $primary = $snapshot === null ? ($instance['primary_assignee'] ?? $instance['owner_ref']) : $snapshot['primary'];
            $this->assign($context, $instance, $definition, null, $primary, $snapshot['secondary'] ?? [], 'stage_preserved', $visitId, false); return;
        }
        $row = $this->db->table('tm_assignment_policies')->where('scope_key', $context->scope)->where('id', $policyId)->first();
        if (!$row) { throw new DomainError('assignment_policy_missing'); } $policy = json_decode($row->definition, true, flags: JSON_THROW_ON_ERROR);
        $this->db->table('tm_stage_visits')->where('id', $visitId)->update(['assignment_policy_id' => $policyId]);
        $snapshot = $instance['assignment_snapshot'] === null ? null : json_decode($instance['assignment_snapshot'], true, flags: JSON_THROW_ON_ERROR);
        $preserve = $instance['assignment_policy_id'] === $policyId && in_array($policy['mode'], ['fixed', 'manual'], true) && ($snapshot['policy_id'] ?? null) === $policyId;
        $primary = $preserve ? $snapshot['primary'] : $this->resolve($context, $instance, $policy); $secondary = $preserve ? $snapshot['secondary'] : $policy['secondary'];
        $this->assign($context, $instance, $policy, $policyId, $primary, $secondary, 'stage_entered', $visitId);
        (new EventRecorder($this->db, $this->clock))->append($context, 'instance', $instance['instance_key'], 'stage_assignment_resolved', $operationId,
            ['visit_id' => $visitId, 'policy_id' => $policyId, 'before' => $instance['primary_assignee'], 'after' => $primary, 'secondary' => $secondary], $instance['task_id']);
    }
    public function close(ExecutionContext $context, int $instanceId): void
    {
        $this->db->table('tm_instance_assignments')->where('scope_key', $context->scope)->where('instance_id', $instanceId)->whereNull('active_to')->update(['active_to' => $this->now()]);
        $this->db->table('tm_assignment_slots')->where('instance_id', $instanceId)->delete();
    }
    public function leaving(ExecutionContext $context, int $instanceId): void { $this->close($context, $instanceId); }
    public function activity(ExecutionContext $context, int $instanceId): void {}
    public function beforeTransition(ExecutionContext $context, array $instance, array $edge, array $payload): void {}
    private function now(): string { return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'); }
}
