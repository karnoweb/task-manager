<?php

namespace Karnoweb\TaskManager\Group;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use Karnoweb\TaskManager\CommandExecutor;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\AuthorizationProvider;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\EventRecorder;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Workflow\Definition;
use Karnoweb\TaskManager\Workflow\InstanceService;

final class GroupService
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization,
        private readonly InstanceService $instances, private readonly GroupSourceRegistry $sources) {}
    private function authorize(ExecutionContext $context, string $capability): void { $context->require($capability); $this->authorization->authorize($context, $capability); }
    public function get(ExecutionContext $context, string $key): array
    {
        $this->authorize($context, 'group.view');
        if (!Str::isUuid($key)) { throw new DomainError('not_found'); }
        $row = $this->db->table('tm_process_groups')->where('scope_key', $context->scope)->where('group_key', $key)->first();
        if (!$row) { throw new DomainError('not_found'); }
        $this->sources->provider($row->source_type)->authorize($context, $row->source_id, 'view');
        $policy = $this->db->table('tm_group_lifecycle_policies')->where('id', $row->policy_id)->where('group_id', $row->id)->first();
        if (!$policy) { throw new DomainError('group_policy_missing'); }
        return [...(array) $row, 'policy_revision' => $policy->revision, 'policy' => json_decode($policy->definition, true, flags: JSON_THROW_ON_ERROR)];
    }
    private function validatePolicy(array $policy): array
    {
        if (array_diff(array_keys($policy), ['protected_milestones', 'failure_transitions', 'reason_code']) || !is_array($policy['protected_milestones'] ?? null)
            || !array_is_list($policy['protected_milestones']) || !$policy['protected_milestones'] || count($policy['protected_milestones']) > 20
            || count(array_unique($policy['protected_milestones'])) !== count($policy['protected_milestones'])
            || !is_array($policy['failure_transitions'] ?? null) || !$policy['failure_transitions'] || array_is_list($policy['failure_transitions'])) { throw new DomainError('group_policy_invalid'); }
        Definition::key($policy['reason_code'] ?? null);
        foreach ($policy['protected_milestones'] as $key) { Definition::key($key); }
        foreach ($policy['failure_transitions'] as $state => $edge) { Definition::key($state); Definition::key($edge); }
        return $policy;
    }
    public function register(ExecutionContext $context, string $sourceType, string $sourceId, array $policy, string $operation): array
    {
        $this->authorize($context, 'group.manage'); $policy = $this->validatePolicy($policy); Definition::key($sourceType);
        if (trim($sourceId) === '' || strlen($sourceId) > 120) { throw new DomainError('group_source_invalid'); }
        $this->sources->provider($sourceType)->authorize($context, $sourceId, 'register');
        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'group_register', 'source' => [$sourceType, $sourceId], 'policy' => $policy], function () use ($context, $sourceType, $sourceId, $policy, $operation) {
            $this->sources->provider($sourceType)->authorize($context, $sourceId, 'register'); $key = (string) Str::uuid();
            $id = $this->db->table('tm_process_groups')->insertGetId(['group_key' => $key, 'scope_key' => $context->scope, 'source_type' => $sourceType, 'source_id' => $sourceId, 'owner_ref' => $context->actor]);
            $policyId = $this->db->table('tm_group_lifecycle_policies')->insertGetId(['group_id' => $id, 'revision' => 1, 'definition' => json_encode($policy, JSON_THROW_ON_ERROR)]);
            $this->db->table('tm_process_groups')->where('id', $id)->update(['policy_id' => $policyId]);
            $this->event($context, $key, 'group_registered', $operation, ['source_type' => $sourceType, 'policy_revision' => 1]); return $this->get($context, $key);
        }, fn ($result) => $this->get($context, $result['group_key']));
    }
    public function publishPolicy(ExecutionContext $context, string $key, int $version, array $policy, string $operation): array
    {
        $this->authorize($context, 'group.manage'); $policy = $this->validatePolicy($policy);
        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'group_policy', 'key' => $key, 'version' => $version, 'policy' => $policy], function () use ($context, $key, $version, $policy, $operation) {
            $group = $this->lockedGroup($context, $key, $version, 'policy');
            if ($group['state'] === 'closing') { throw new DomainError('campaign_group_closed'); }
            $revision = $group['policy_revision'] + 1;
            $id = $this->db->table('tm_group_lifecycle_policies')->insertGetId(['group_id' => $group['id'], 'revision' => $revision, 'definition' => json_encode($policy, JSON_THROW_ON_ERROR)]);
            $this->db->table('tm_process_groups')->where('id', $group['id'])->update(['policy_id' => $id, 'version' => $version + 1]);
            $this->event($context, $key, 'group_policy_published', $operation, ['revision' => $revision]); return $this->get($context, $key);
        }, fn ($result) => $this->get($context, $result['group_key']));
    }
    public function enroll(ExecutionContext $context, string $key, int $version, array $instances, string $operation): array
    {
        $this->authorize($context, 'group.manage');
        if (!array_is_list($instances) || !$instances || count($instances) > 250) { throw new DomainError('batch_size_invalid'); }
        foreach ($instances as $instance) {
            if (!is_array($instance) || array_diff(array_keys($instance), ['instance_key', 'version']) || !is_string($instance['instance_key'] ?? null) || !is_int($instance['version'] ?? null)) { throw new DomainError('group_member_invalid'); }
        }
        usort($instances, fn ($a, $b) => strcmp($a['instance_key'], $b['instance_key']));
        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'group_enroll', 'key' => $key, 'version' => $version, 'members' => $instances], function () use ($context, $key, $version, $instances, $operation) {
            $group = $this->lockedGroup($context, $key, $version, 'enroll');
            if ($group['state'] !== 'open') { throw new DomainError('campaign_group_closed'); }
            foreach ($instances as $reference) {
                [$instance] = $this->instances->lockForCommand($context, $reference['instance_key'], $reference['version']);
                if ($this->db->table('tm_active_group_members')->where('group_id', $group['id'])->where('instance_id', $instance['id'])->exists()) { throw new DomainError('group_member_already_enrolled'); }
                $id = $this->db->table('tm_process_group_members')->insertGetId(['group_id' => $group['id'], 'instance_id' => $instance['id'], 'joined_at' => $this->now(), 'enrollment_revision' => $version + 1]);
                $this->db->table('tm_active_group_members')->insert(['group_id' => $group['id'], 'instance_id' => $instance['id'], 'member_id' => $id]);
            }
            $this->db->table('tm_process_groups')->where('id', $group['id'])->increment('version');
            $this->event($context, $key, 'group_members_enrolled', $operation, ['count' => count($instances), 'enrollment_revision' => $version + 1]); return $this->get($context, $key);
        }, fn ($result) => $this->get($context, $result['group_key']));
    }
    private function lockedGroup(ExecutionContext $context, string $key, int $version, string $action): array
    {
        $before = $this->get($context, $key);
        $row = $this->db->table('tm_process_groups')->where('id', $before['id'])->lockForUpdate()->first();
        if ($row->version !== $version) { throw new DomainError('version_conflict'); }
        $group = $this->get($context, $key); $this->sources->provider($group['source_type'])->authorize($context, $group['source_id'], $action); return $group;
    }
    private function closure(ExecutionContext $context, array $group): array
    {
        $evidence = $this->sources->provider($group['source_type'])->closure($context, $group['source_id']);
        if (!is_int($evidence['revision'] ?? null) || $evidence['revision'] < 1 || !(($evidence['cutoff_at'] ?? null) instanceof \DateTimeImmutable)
            || $evidence['cutoff_at'] > $this->clock->now()) { throw new DomainError('group_source_invalid'); }
        return ['source_revision' => $evidence['revision'], 'cutoff_at' => $evidence['cutoff_at']->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u')];
    }
    private function memberQuery(ExecutionContext $context, array $group, string $cutoff): Builder
    {
        $visible = $this->instances->authorizedQuery($context)->whereColumn('tm_workflow_instances.id', 'm.instance_id')->select('tm_workflow_instances.id')->limit(1);
        $protected = $this->db->table('tm_process_milestones')->where('scope_key', $context->scope)->whereColumn('instance_id', 'm.instance_id')
            ->whereIn('milestone_key', $group['policy']['protected_milestones'])->where('committed_at', '<=', $cutoff)->selectRaw('MIN(committed_at)');
        $pending = $this->db->table('tm_milestone_intents')->where('scope_key', $context->scope)->whereColumn('instance_id', 'm.instance_id')
            ->whereIn('milestone_key', $group['policy']['protected_milestones'])->where('state', 'pending')->selectRaw('COUNT(*)');
        return $this->db->table('tm_process_group_members as m')->join('tm_workflow_instances as i', 'i.id', '=', 'm.instance_id')
            ->where('m.group_id', $group['id'])->whereNull('m.left_at')->where('i.scope_key', $context->scope)
            ->select(['m.id', 'm.instance_id', 'i.version', 'i.lifecycle', 'i.current_state_key'])
            ->addSelect(['visible_id' => $visible, 'protected_at' => $protected, 'pending_intents' => $pending]);
    }
    private function decision(array $row, array $policy): string
    {
        if ($row['visible_id'] === null) { return 'denied'; }
        if ($row['lifecycle'] === 'closed') { return 'already_terminal'; }
        if ($row['protected_at'] !== null) { return 'continued'; }
        if ((int) $row['pending_intents'] > 0 || $row['lifecycle'] !== 'active' || !isset($policy['failure_transitions'][$row['current_state_key']])) { return 'manual_review'; }
        return 'failed';
    }
    public function previewClosure(ExecutionContext $context, string $key): array
    {
        $this->authorize($context, 'group.preview'); $group = $this->get($context, $key);
        $this->sources->provider($group['source_type'])->authorize($context, $group['source_id'], 'preview'); $closure = $this->closure($context, $group);
        return $this->buildPreview($context, $group, $closure);
    }
    private function buildPreview(ExecutionContext $context, array $group, array $closure, ?int $runId = null): array
    {
        $counts = array_fill_keys(['failed', 'continued', 'already_terminal', 'manual_review', 'denied'], 0);
        $hash = hash_init('sha256'); $after = 0; $total = 0;
        do {
            $rows = $this->memberQuery($context, $group, $closure['cutoff_at'])->where('m.id', '>', $after)->orderBy('m.id')->limit(250)->get();
            $snapshot = [];
            foreach ($rows as $row) {
                $data = (array) $row; ++$counts[$this->decision($data, $group['policy'])]; ++$total; $after = $row->id;
                hash_update($hash, json_encode($data, JSON_THROW_ON_ERROR)."\n");
                if ($runId !== null) { $snapshot[] = ['run_id' => $runId, 'member_id' => $row->id, 'instance_id' => $row->instance_id, 'expected_instance_version' => $row->version]; }
            }
            if ($snapshot) { $this->db->table('tm_group_operation_items')->insert($snapshot); }
        } while (count($rows) === 250);
        return ['group_key' => $group['group_key'], 'group_version' => $group['version'], 'policy_id' => $group['policy_id'], 'policy_revision' => $group['policy_revision'],
            ...$closure, 'scope_key' => $context->scope, 'actor_ref' => $context->actor, 'reason_code' => $group['policy']['reason_code'], 'member_hash' => hash_final($hash), 'member_max_id' => $after, 'total' => $total, 'counts' => $counts];
    }
    public function previewDelta(ExecutionContext $context, array $previous): array
    {
        if (($previous['scope_key'] ?? null) !== $context->scope || ($previous['actor_ref'] ?? null) !== $context->actor || !is_string($previous['group_key'] ?? null)) { throw new DomainError('preview_invalid'); }
        $fresh = $this->previewClosure($context, $previous['group_key']); $delta = [];
        foreach ($fresh['counts'] as $key => $value) { $delta[$key] = $value - (int) ($previous['counts'][$key] ?? 0); }
        return ['preview' => $fresh, 'changed' => $this->ordered($fresh) !== $this->ordered($previous), 'counts_delta' => $delta];
    }
    public function executeClosure(ExecutionContext $context, array $preview, string $runKey): array
    {
        $this->authorize($context, 'group.execute'); $this->authorize($context, 'group.preview');
        if (trim($runKey) === '' || strlen($runKey) > 120 || !is_string($preview['group_key'] ?? null) || !is_int($preview['group_version'] ?? null)) { throw new DomainError('group_run_invalid'); }
        $operation = 'group-run:'.hash('sha256', $runKey);
        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'group_execute', 'preview' => $preview], function () use ($context, $preview, $operation, $runKey) {
            try { $group = $this->lockedGroup($context, $preview['group_key'], $preview['group_version'], 'execute'); }
            catch (DomainError $error) { if ($error->getMessage() === 'version_conflict') { throw new DomainError('preview_stale'); } throw $error; }
            if ($group['state'] === 'closing') { throw new DomainError('campaign_group_closed'); }
            $closure = $this->closure($context, $group);
            $key = (string) Str::uuid();
            $id = $this->db->table('tm_group_operations')->insertGetId(['run_key' => $key, 'scope_key' => $context->scope, 'group_id' => $group['id'], 'policy_id' => $group['policy_id'],
                'source_revision' => $closure['source_revision'], 'cutoff_at' => $closure['cutoff_at'], 'initiator_ref' => $context->actor, 'operation_id' => $operation, 'created_at' => $this->now()]);
            // Hash and persist the exact same bounded rows; a later source change must conflict at the item.
            $fresh = $this->buildPreview($context, $group, $closure, $id);
            if ($this->ordered($fresh) !== $this->ordered($preview)) { throw new DomainError('preview_stale'); }
            $this->db->table('tm_process_groups')->where('id', $group['id'])->update(['state' => 'closing', 'version' => $group['version'] + 1]);
            $this->event($context, $group['group_key'], 'group_closure_started', $operation, ['run_key' => $key, 'policy_revision' => $group['policy_revision'], 'cutoff_at' => $preview['cutoff_at'], 'requested_count' => $preview['total']]);
            return $this->runInfo($context, $key);
        }, fn ($result) => $this->runInfo($context, $result['run_key']));
    }
    public function runInfo(ExecutionContext $context, string $key): array
    {
        $this->authorize($context, 'group.view');
        if (!Str::isUuid($key)) { throw new DomainError('not_found'); }
        $row = $this->db->table('tm_group_operations')->where('scope_key', $context->scope)->where('run_key', $key)->first();
        if (!$row) { throw new DomainError('not_found'); }
        $groupKey = $this->db->table('tm_process_groups')->where('id', $row->group_id)->where('scope_key', $context->scope)->value('group_key');
        $group = $this->get($context, $groupKey ?? '');
        $row->cutoff_at = (new \DateTimeImmutable($row->cutoff_at, new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        return [...(array) $row, 'group_key' => $group['group_key']];
    }
    public function process(ExecutionContext $context, string $runKey, int $limit = 25): array
    {
        $this->authorize($context, 'group.execute');
        if ($limit < 1 || $limit > 250) { throw new DomainError('batch_size_invalid'); }
        $run = $this->runInfo($context, $runKey); $group = $this->get($context, $run['group_key']);
        $this->sources->provider($group['source_type'])->authorize($context, $group['source_id'], 'execute');
        if ($run['state'] === 'complete') { return ['processed' => 0, 'state' => 'complete']; }
        $closure = $this->closure($context, $group);
        if ($closure['source_revision'] !== $run['source_revision'] || $closure['cutoff_at'] !== $run['cutoff_at']) { throw new DomainError('group_source_changed'); }
        $token = (string) Str::uuid(); $now = $this->now();
        $ids = $this->db->transaction(function () use ($run, $token, $now, $limit) {
            $query = $this->db->table('tm_group_operation_items')->where('run_id', $run['id'])->where(fn ($q) => $q->where('state', 'pending')->orWhere(fn ($expired) => $expired->where('state', 'processing')->where('lease_expires_at', '<=', $now)))
                ->orderBy('member_id')->limit($limit);
            $query->lock(in_array($this->db->getDriverName(), ['pgsql', 'mysql', 'mariadb'], true) ? 'FOR UPDATE SKIP LOCKED' : true);
            $items = $query->get();
            $expires = $this->clock->now()->modify('+120 seconds')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
            foreach ($items as $item) { $this->db->table('tm_group_operation_items')->where('id', $item->id)->update(['state' => 'processing', 'lease_token' => $token, 'lease_expires_at' => $expires, 'attempts' => $item->attempts + 1]); }
            return $items->pluck('id')->all();
        });
        $processed = 0;
        foreach ($ids as $id) {
            try { $this->processItem($context, $run, $id, $token); ++$processed; }
            catch (DomainError $error) { if ($error->getMessage() !== 'group_lease_lost') { throw $error; } }
        }
        $this->finish($context, $run);
        return ['processed' => $processed, 'state' => $this->runInfo($context, $runKey)['state']];
    }
    private function processItem(ExecutionContext $context, array $run, int $id, string $token): void
    {
        $this->db->transaction(function () use ($context, $run, $id, $token) {
            $before = $this->db->table('tm_group_operation_items')->where('run_id', $run['id'])->where('id', $id)->first();
            if (!$before) { throw new DomainError('not_found'); }
            $key = $this->db->table('tm_workflow_instances')->where('id', $before->instance_id)->where('scope_key', $context->scope)->value('instance_key');
            $instance = null; $failure = null;
            try { [$instance] = $this->instances->lockForCommand($context, $key ?? '', $before->expected_instance_version); }
            catch (DomainError $error) { $failure = $error->getMessage(); }
            $item = $this->db->table('tm_group_operation_items')->where('id', $id)->where('run_id', $run['id'])->lockForUpdate()->first();
            if ($item->state !== 'processing' || $item->lease_token !== $token || $item->lease_expires_at <= $this->now()) { throw new DomainError('group_lease_lost'); }
            $group = $this->get($context, $run['group_key']);
            $closure = $this->closure($context, $group);
            if ($closure['source_revision'] !== $run['source_revision'] || $closure['cutoff_at'] !== $run['cutoff_at']) { throw new DomainError('group_source_changed'); }
            $policyRow = $this->db->table('tm_group_lifecycle_policies')->where('id', $run['policy_id'])->where('group_id', $group['id'])->first();
            if (!$policyRow) { throw new DomainError('group_policy_missing'); }
            $policy = json_decode($policyRow->definition, true, flags: JSON_THROW_ON_ERROR);
            $operation = 'group-item:'.$run['run_key'].':'.$item->member_id; $reason = null;
            if ($failure !== null) { $result = in_array($failure, ['not_found', 'capability_denied'], true) ? 'denied' : 'manual_review'; }
            else {
                $protected = $this->db->table('tm_process_milestones')->where('scope_key', $context->scope)->where('instance_id', $instance['id'])
                    ->whereIn('milestone_key', $policy['protected_milestones'])->where('committed_at', '<=', $run['cutoff_at'])->exists();
                $pending = $this->db->table('tm_milestone_intents')->where('scope_key', $context->scope)->where('instance_id', $instance['id'])->where('state', 'pending')->whereIn('milestone_key', $policy['protected_milestones'])->exists();
                $result = $this->decision(['visible_id' => $instance['id'], 'lifecycle' => $instance['lifecycle'], 'current_state_key' => $instance['current_state_key'], 'protected_at' => $protected ? $run['cutoff_at'] : null, 'pending_intents' => $pending ? 1 : 0], $policy);
                if ($result === 'failed') {
                    try {
                        $this->db->transaction(fn () => $this->instances->transition($context, $instance['instance_key'], $instance['version'], $operation,
                            $policy['failure_transitions'][$instance['current_state_key']], ['reason_code' => $policy['reason_code']]));
                        $reason = $policy['reason_code'];
                    } catch (DomainError $error) { $failure = $error->getMessage(); $result = in_array($failure, ['not_found', 'capability_denied'], true) ? 'denied' : 'manual_review'; }
                } elseif ($result === 'manual_review') { $failure = $pending ? 'commit_evidence_pending' : 'closure_action_unavailable'; }
            }
            $failure = $failure === null ? null : (preg_match('/^[a-z][a-z0-9_.-]{0,99}$/D', $failure) ? $failure : 'subject_guard_denied');
            $this->db->table('tm_group_operation_items')->where('id', $id)->update(['state' => 'done', 'result' => $result, 'reason_code' => $reason, 'error_code' => $failure,
                'operation_id' => $operation, 'processed_at' => $this->now(), 'lease_token' => null, 'lease_expires_at' => null]);
            (new EventRecorder($this->db, $this->clock))->append($context, $instance === null ? 'group' : 'instance', $instance['instance_key'] ?? $group['group_key'],
                'group_item_processed', $operation, ['run_key' => $run['run_key'], 'member_id' => $item->member_id, 'result' => $result, 'reason_code' => $reason, 'error_code' => $failure], $instance['task_id'] ?? null);
        });
    }
    private function finish(ExecutionContext $context, array $run): void
    {
        if ($this->db->table('tm_group_operation_items')->where('run_id', $run['id'])->where('state', '!=', 'done')->exists()) { return; }
        $this->db->transaction(function () use ($context, $run) {
            $row = $this->db->table('tm_group_operations')->where('id', $run['id'])->lockForUpdate()->first();
            if ($row->state === 'complete' || $this->db->table('tm_group_operation_items')->where('run_id', $run['id'])->where('state', '!=', 'done')->exists()) { return; }
            $this->db->table('tm_group_operations')->where('id', $run['id'])->update(['state' => 'complete', 'version' => $row->version + 1]);
            $this->db->table('tm_process_groups')->where('id', $run['group_id'])->where('state', 'closing')->update(['state' => 'closed', 'version' => $this->db->raw('version + 1')]);
            $this->event($context, $run['group_key'], 'group_closure_completed', 'group-complete:'.$run['run_key'], ['run_key' => $run['run_key']]);
        });
    }
    public function report(ExecutionContext $context, string $key, int $limit = 100, int $afterId = 0): array
    {
        $this->authorize($context, 'group.view');
        if ($limit < 1 || $limit > 250 || $afterId < 0) { throw new DomainError('pagination_invalid'); }
        $run = $this->runInfo($context, $key);
        $visible = $this->instances->authorizedQuery($context)->select('id');
        $base = $this->db->table('tm_group_operation_items')->where('run_id', $run['id'])->whereIn('instance_id', $visible);
        $counts = (clone $base)->select('result')->selectRaw('COUNT(*) AS total')->groupBy('result')->get()->mapWithKeys(fn ($row) => [$row->result ?? 'pending' => (int) $row->total])->all();
        ksort($counts);
        $items = (clone $base)->where('id', '>', $afterId)->orderBy('id')->limit($limit)->get()->map(function ($row) {
            $result = (array) $row; unset($result['lease_token'], $result['lease_expires_at']); return $result;
        })->all();
        return ['run_key' => $key, 'state' => $run['state'], 'counts' => $counts, 'items' => $items, 'next_after_id' => $items ? end($items)['id'] : null];
    }
    private function event(ExecutionContext $context, string $key, string $action, string $operation, array $payload): void
    {
        (new EventRecorder($this->db, $this->clock))->append($context, 'group', $key, $action, $operation, $payload);
    }
    private function ordered(array $value): array
    {
        if (!array_is_list($value)) { ksort($value); }
        foreach ($value as $key => $part) { if (is_array($part)) { $value[$key] = $this->ordered($part); } }
        return $value;
    }
    private function now(): string { return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'); }
}
