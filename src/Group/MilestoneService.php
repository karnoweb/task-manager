<?php

namespace Karnoweb\TaskManager\Group;

use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Karnoweb\TaskManager\CommandExecutor;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\AuthorizationProvider;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\Contracts\CommitEvidenceProvider;
use Karnoweb\TaskManager\EventRecorder;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Workflow\InstanceService;

final class MilestoneService
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization,
        private readonly InstanceService $instances, private readonly CommitEvidenceProvider $evidence) {}
    private function authorize(ExecutionContext $context): void { $context->require('milestone.verify'); $this->authorization->authorize($context, 'milestone.verify'); }
    public function get(ExecutionContext $context, string $intentKey): array
    {
        $this->authorize($context);
        if (!Str::isUuid($intentKey)) { throw new DomainError('not_found'); }
        $row = $this->db->table('tm_milestone_intents')->where('scope_key', $context->scope)->where('intent_key', $intentKey)->first();
        if (!$row) { throw new DomainError('not_found'); }
        $key = $this->db->table('tm_workflow_instances')->where('id', $row->instance_id)->where('scope_key', $context->scope)->value('instance_key');
        $instance = $this->instances->get($context, $key ?? '');
        $committed = $this->db->table('tm_process_milestones')->where('intent_id', $row->id)->value('committed_at');
        $result = (array) $row; unset($result['transaction_ref']); return [...$result, 'instance_key' => $instance['instance_key'], 'current_instance_version' => $instance['version'],
            'committed_at' => $committed === null ? null : (new \DateTimeImmutable($committed, new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u')];
    }
    public function verify(ExecutionContext $context, string $intentKey, int $instanceVersion, string $operation): array
    {
        $this->authorize($context);
        // A provider must inspect a completed transaction, never this caller's uncommitted writes.
        if ($this->db->transactionLevel() !== 0) { throw new DomainError('commit_evidence_requires_committed_boundary'); }
        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'milestone_verify', 'intent' => $intentKey, 'version' => $instanceVersion], function () use ($context, $intentKey, $instanceVersion, $operation) {
            $before = $this->get($context, $intentKey);
            [$instance] = $this->instances->lockForCommand($context, $before['instance_key'], $instanceVersion);
            $intent = $this->db->table('tm_milestone_intents')->where('id', $before['id'])->lockForUpdate()->first();
            if ($intent->state === 'verified') { return $this->get($context, $intentKey); }
            $committed = $this->evidence->committedAt($context, (array) $intent);
            if ($committed === null) { throw new DomainError('commit_evidence_unavailable'); }
            $time = $committed->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
            $this->db->table('tm_process_milestones')->insert(['scope_key' => $context->scope, 'instance_id' => $intent->instance_id, 'intent_id' => $intent->id,
                'milestone_key' => $intent->milestone_key, 'committed_at' => $time, 'verified_at' => $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u')]);
            $this->db->table('tm_milestone_intents')->where('id', $intent->id)->update(['state' => 'verified']);
            (new EventRecorder($this->db, $this->clock))->append($context, 'instance', $instance['instance_key'], 'milestone_verified', $operation,
                ['intent_key' => $intentKey, 'milestone_key' => $intent->milestone_key, 'committed_at' => $time], $instance['task_id']);
            return $this->get($context, $intentKey);
        }, fn ($result) => $this->get($context, $result['intent_key']));
    }
    public function run(ExecutionContext $context, int $limit = 100): array
    {
        $this->authorize($context);
        if ($limit < 1 || $limit > 250) { throw new DomainError('batch_size_invalid'); }
        $rows = $this->db->table('tm_milestone_intents')->where('scope_key', $context->scope)->where('state', 'pending')
            ->whereIn('instance_id', $this->instances->authorizedQuery($context)->select('id'))->orderBy('id')->limit($limit)->get();
        $verified = 0; $pending = 0;
        foreach ($rows as $row) {
            $before = $this->get($context, $row->intent_key);
            try { $this->verify($context, $row->intent_key, $before['current_instance_version'], 'milestone-verify:'.$row->intent_key); ++$verified; }
            catch (DomainError $error) { if (!in_array($error->getMessage(), ['commit_evidence_unavailable', 'version_conflict', 'not_found'], true)) { throw $error; } ++$pending; }
        }
        return ['verified' => $verified, 'pending' => $pending];
    }
}
