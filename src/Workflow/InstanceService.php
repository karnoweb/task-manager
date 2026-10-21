<?php

namespace Karnoweb\TaskManager\Workflow;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use Karnoweb\TaskManager\CommandExecutor;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\AuthorizationProvider;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\Contracts\StageLifecycle;
use Karnoweb\TaskManager\EventRecorder;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\SubjectRegistry;
use Karnoweb\TaskManager\TaskGraph\ScopeGraphLock;

final class InstanceService
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization,
        private readonly SubjectRegistry $subjects, private readonly WorkflowService $workflows, private readonly PipelineService $pipelines, private readonly array $guards = [], private readonly ?StageLifecycle $lifecycle = null)
    {
    }

    private function authorize(ExecutionContext $context, string $capability): void
    {
        $context->require($capability);
        $this->authorization->authorize($context, $capability);
    }

    private function subject(ExecutionContext $context, string $type, string $id, string $capability, bool $lock = false): object
    {
        if ($id === '' || strlen($id) > 120) {
            throw new DomainError('subject_reference_invalid');
        }
        if ($type === 'task' && ! Str::isUuid($id)) {
            throw new DomainError('not_found');
        }
        $handler = $this->subjects->handler($type);
        $query = $handler->query($context, $capability)->where($handler->scopeColumn(), $context->scope)->where($handler->idColumn(), $id);
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first();
        if (! $row) {
            throw new DomainError('not_found');
        }

        return $row;
    }

    private function visible(ExecutionContext $context): Builder
    {
        $this->authorize($context, 'workflow.view');
        $query = $this->db->table('tm_workflow_instances')->where('scope_key', $context->scope)->where(function (Builder $q) use ($context) {
            $q->whereRaw('1 = 0');
            foreach ($this->subjects->all() as $alias => $handler) {
                try {
                    $subjects = $handler->query($context, 'view')->where($handler->scopeColumn(), $context->scope);
                } catch (DomainError $error) {
                    if ($error->getMessage() === 'capability_denied') {
                        continue;
                    } throw $error;
                }
                $column = $this->db->getQueryGrammar()->wrap($handler->idColumn());
                $cast = $this->db->getDriverName() === 'mysql' ? 'CHAR' : 'VARCHAR(120)';
                $subjects->select([])->selectRaw('CAST('.$column.' AS '.$cast.')');
                $q->orWhere(fn (Builder $typed) => $typed->where('subject_type', $alias)->whereIn('subject_id', $subjects));
            }
        });
        $this->authorization->constrain($query, $context, 'instance', 'view');

        return $query;
    }

    public function get(ExecutionContext $context, string $key): array
    {
        if (! Str::isUuid($key)) {
            throw new DomainError('not_found');
        }
        $row = $this->visible($context)->where('instance_key', $key)->first();
        if (! $row) {
            throw new DomainError('not_found');
        }

        return (array) $row;
    }

    public function authorizedQuery(ExecutionContext $context): Builder
    {
        return $this->visible($context);
    }

    public function query(ExecutionContext $context, int $limit = 30, ?string $afterKey = null): array
    {
        if ($limit < 1 || $limit > 100 || ($afterKey !== null && ! Str::isUuid($afterKey))) {
            throw new DomainError('pagination_invalid');
        }
        $query = $this->visible($context);
        if ($afterKey !== null) {
            $query->where('instance_key', '>', $afterKey);
        }

        return $query->orderBy('instance_key')->limit($limit)->get()->map(fn ($r) => (array) $r)->all();
    }

    public function count(ExecutionContext $context): int
    {
        return $this->visible($context)->count();
    }

    public function facets(ExecutionContext $context): array
    {
        return $this->visible($context)->select('current_state_key')->selectRaw('COUNT(*) AS total')->groupBy('current_state_key')->orderBy('current_state_key')->get()->map(fn ($r) => ['state' => $r->current_state_key, 'total' => (int) $r->total])->all();
    }

    public function visits(ExecutionContext $context, string $key): array
    {
        $instance = $this->get($context, $key);

        return $this->db->table('tm_stage_visits')->where('scope_key', $context->scope)->where('instance_id', $instance['id'])->orderBy('visit_no')->get()->map(fn ($r) => (array) $r)->all();
    }

    public function start(ExecutionContext $context, string $type, string $subjectId, int $revisionId, string $operation, string $slot = 'main', ?string $pipelineKey = null, ?string $stageKey = null, ?string $caseKey = null): array
    {
        $this->authorize($context, 'workflow.transition');
        Definition::key($slot);
        $this->subject($context, $type, $subjectId, 'edit');
        $revision = $this->workflows->revision($context, $revisionId);
        $pipeline = $pipelineKey === null ? null : $this->pipelines->get($context, $pipelineKey);
        if ($pipeline !== null && $pipeline['workflow_revision_id'] !== $revisionId) {
            throw new DomainError('pipeline_revision_mismatch');
        }
        $initial = $revision['definition']['initial'];
        if (($revision['definition']['states'][$initial]['terminal'] ?? 'none') !== 'none') {
            throw new DomainError('initial_state_terminal');
        }

        return $this->command($context, $operation, ['action' => 'start_instance', 'type' => $type, 'subject' => $subjectId, 'revision' => $revisionId, 'slot' => $slot, 'pipeline' => $pipelineKey, 'stage' => $stageKey, 'case' => $caseKey], function () use ($context, $type, $subjectId, $revisionId, $revision, $pipeline, $stageKey, $initial, $slot, $caseKey, $operation) {
            if ($type === 'task') {
                ScopeGraphLock::acquire($this->db, $context);
            }
            $subject = $this->subject($context, $type, $subjectId, 'edit', true);
            $this->subjects->handler($type)->guard($context, $subjectId, [], 'start', ['pipeline_key' => $pipeline['pipeline_key'] ?? null, 'revision_id' => $revisionId, 'slot' => $slot, 'state' => $initial, 'stage_key' => $stageKey]);
            $caseId = null;
            if ($caseKey !== null) {
                $case = $this->db->table('tm_process_cases')->where('scope_key', $context->scope)->where('case_key', $caseKey)->where('owner_ref', $context->actor)->first();
                if (! $case) {
                    throw new DomainError('not_found');
                } $caseId = $case->id;
            }
            $stage = $pipeline === null ? null : $this->stage($pipeline['id'], $initial, $stageKey);
            if ($stage !== null) {
                $this->lockStages([$stage->id]);
                $this->capacity($context, $stage);
            }
            $key = (string) Str::uuid();
            $id = $this->db->table('tm_workflow_instances')->insertGetId(['instance_key' => $key, 'scope_key' => $context->scope,
                'task_id' => $type === 'task' ? $subject->id : null, 'subject_type' => $type, 'subject_id' => $subjectId, 'slot_key' => $slot,
                'workflow_key' => $revision['key'], 'workflow_revision_id' => $revisionId, 'current_state_key' => $initial,
                'owner_ref' => $context->actor, 'primary_assignee' => $context->actor, 'pipeline_id' => $pipeline['id'] ?? null, 'active_stage_key' => $stage?->stage_key,
                'case_id' => $caseId, 'started_at' => $this->now()]);
            $this->reserve($context, ['id' => $id, 'subject_type' => $type, 'subject_id' => $subjectId, 'slot_key' => $slot]);
            if ($type === 'task' && $slot === 'main') {
                $native = (array) $this->db->table('tm_workflow_instances')->where('id', $id)->first();
                $category = $this->db->table('tm_workflow_states')->where('workflow_revision_id', $revisionId)->where('state_key', $initial)->value('category');
                if ($category === 'in_progress') {
                    $this->lifecycle?->beforeTransition($context, $native, ['key' => 'start_instance', 'from' => $initial, 'to' => $initial, 'starting_instance' => true], []);
                    $this->db->table('tm_instance_transitions')->insert(['scope_key' => $context->scope, 'instance_id' => $id,
                        'from_state' => '', 'to_state' => $initial, 'from_revision_id' => null, 'to_revision_id' => $revisionId,
                        'from_category' => null, 'to_category' => $category, 'actor_ref' => $context->actor, 'operation_id' => $operation, 'occurred_at' => $this->now()]);
                }
            }
            if ($type === 'task') {
                $this->db->table('tm_tasks')->where('id', $subject->id)->increment('version');
            }
            if ($caseId !== null) {
                $this->db->table('tm_case_subjects')->insertOrIgnore(['case_id' => $caseId, 'subject_type' => $type, 'subject_id' => $subjectId]);
            }
            if ($stage !== null) {
                $this->visit($context, $id, $stage, $operation);
            }
            $this->event($context, $key, 'instance_started', $operation, ['state' => $initial, 'slot' => $slot], $type === 'task' ? $subject->id : null);

            return $this->get($context, $key);
        });
    }

    public function transition(ExecutionContext $context, string $key, int $version, string $operation, string $transition, array $payload = [], ?string $stageKey = null, bool $confirmed = true): array
    {
        $this->authorize($context, 'workflow.transition');
        if (! $confirmed) {
            return $this->get($context, $key);
        }

        return $this->command($context, $operation, ['action' => 'transition', 'key' => $key, 'version' => $version, 'transition' => $transition, 'payload' => $payload, 'stage' => $stageKey], function () use ($context, $key, $version, $operation, $transition, $payload, $stageKey) {
            [$instance, $subject] = $this->locked($context, $key, $version);
            $definition = $this->workflows->revision($context, $instance['workflow_revision_id'])['definition'];
            $edge = $definition['transitions'][$transition] ?? null;
            if (! $edge || $edge['from'] !== $instance['current_state_key']) {
                throw new DomainError('transition_invalid');
            }
            if ($instance['lifecycle'] === 'waiting' && ! ($edge['allow_while_waiting'] ?? false)) {
                throw new DomainError('instance_on_hold');
            }
            foreach ($edge['required_fields'] ?? [] as $field) {
                if (! array_key_exists($field, $payload) || $payload[$field] === null || $payload[$field] === '') {
                    throw new DomainError('required_field_missing');
                }
            }
            $needsReason = ($edge['reason_required'] ?? false) || in_array($definition['states'][$edge['to']]['terminal'] ?? 'none', ['failure', 'cancelled'], true);
            if ($needsReason && (! is_string($payload['reason_code'] ?? null) || trim($payload['reason_code']) === '')) {
                throw new DomainError('reason_required');
            }
            if (isset($payload['reason_code'], $edge['reason_codes']) && ! in_array($payload['reason_code'], $edge['reason_codes'], true)) {
                throw new DomainError('reason_invalid');
            }
            if (isset($edge['roles'])) {
                $role = $instance['subject_type'] === 'task' && $subject->project_id !== null
                    ? $this->db->table('tm_project_members')->where('project_id', $subject->project_id)->where('actor_ref', $context->actor)->value('role') : ($instance['owner_ref'] === $context->actor ? 'owner' : null);
                if (! in_array($role, $edge['roles'], true)) {
                    throw new DomainError('transition_role_denied');
                }
            }
            foreach ($edge['conditions'] ?? [] as $condition) {
                $actual = $subject->{$condition['field']} ?? null;
                $expected = $condition['value'];
                $valid = match ($condition['operator']) {
                    'eq' => $actual === $expected, 'neq' => $actual !== $expected,
                    'gte' => is_numeric($actual) && is_numeric($expected) && $actual >= $expected, 'in' => is_array($expected) && in_array($actual, $expected, true), default => false
                };
                if (! $valid) {
                    throw new DomainError('condition_failed');
                }
            }
            foreach ($edge['guards'] ?? [] as $guard) {
                if (! isset($this->guards[$guard]) || ! is_callable($this->guards[$guard])) {
                    throw new DomainError('guard_unregistered');
                }
                if (($this->guards[$guard])($context, $instance, $payload) !== true) {
                    throw new DomainError('guard_veto');
                }
            }
            $this->subjects->handler($instance['subject_type'])->guard($context, $instance['subject_id'], $instance, $transition, $payload);
            $this->lifecycle?->beforeTransition($context, $instance, ['key' => $transition, ...$edge], $payload);
            $state = $edge['to'];
            $terminal = ($definition['states'][$state]['terminal'] ?? 'none') !== 'none';
            $stage = $instance['pipeline_id'] === null ? null : $this->stage($instance['pipeline_id'], $state, $stageKey);
            $oldStage = $instance['pipeline_id'] === null ? null : $this->db->table('tm_pipeline_stages')->where('pipeline_id', $instance['pipeline_id'])->where('stage_key', $instance['active_stage_key'])->first();
            $stageChanged = $stage !== null && $stage->stage_key !== $instance['active_stage_key'];
            if ($instance['lifecycle'] === 'waiting' && ! $terminal && $stageChanged) {
                throw new DomainError('instance_on_hold');
            }
            $this->lockStages(array_filter([$oldStage?->id, $stage?->id]));
            if ($stage !== null && ! $terminal) {
                $this->capacity($context, $stage, $instance['id']);
            }
            if ($terminal) {
                $this->db->table('tm_active_process_slots')->where('instance_id', $instance['id'])->delete();
            } else {
                $this->reserve($context, $instance);
            }
            if ($stageChanged || $terminal) {
                $this->closeVisit($context, $instance['id']);
            }
            if ($stage !== null && ($stageChanged || (! $terminal && $instance['lifecycle'] === 'closed'))) {
                $this->visit($context, $instance['id'], $stage, $operation);
                if ($terminal) {
                    $this->closeVisit($context, $instance['id']);
                }
            }
            if ($stage === null && ! $terminal && $instance['lifecycle'] === 'closed') {
                $this->lifecycle?->activated($context, $instance['id'], $operation);
            }
            $this->db->table('tm_workflow_instances')->where('id', $instance['id'])->update(['current_state_key' => $state, 'active_stage_key' => $stage?->stage_key,
                'version' => $version + 1, 'lifecycle' => $terminal ? 'closed' : ($instance['lifecycle'] === 'waiting' ? 'waiting' : 'active'), 'completed_at' => $terminal ? $this->now() : null]);
            if ($instance['task_id'] !== null) {
                $this->db->table('tm_tasks')->where('id', $instance['task_id'])->increment('version');
            }
            $this->history($context, $instance, $state, $operation);
            if (! empty($edge['milestones'])) {
                $transaction = $this->db->getDriverName() === 'pgsql' ? 'pgsql:'.$this->db->selectOne('SELECT pg_current_xact_id()::text AS xid')->xid : null;
                foreach ($edge['milestones'] as $milestone) {
                    $this->db->table('tm_milestone_intents')->insert(['intent_key' => (string) Str::uuid(), 'scope_key' => $context->scope, 'instance_id' => $instance['id'],
                        'milestone_key' => $milestone, 'instance_version' => $version + 1, 'operation_id' => $operation, 'transaction_ref' => $transaction,
                        'created_at' => $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u')]);
                }
            }
            if (! $stageChanged && ! $terminal) {
                $this->lifecycle?->activity($context, $instance['id']);
            }
            $this->event($context, $key, 'instance_transitioned', $operation, ['from' => $instance['current_state_key'], 'to' => $state, 'transition' => $transition, 'reason_code' => $payload['reason_code'] ?? null], $instance['task_id']);

            return $this->get($context, $key);
        });
    }

    public function previewMove(ExecutionContext $context, string $key, string $pipelineKey, array $map, ?string $stageKey = null): array
    {
        $this->authorize($context, 'pipeline.manage');
        $instance = $this->get($context, $key);
        $pipeline = $this->pipelines->get($context, $pipelineKey);
        $definition = $this->workflows->revision($context, $pipeline['workflow_revision_id'])['definition'];
        $state = $map[$instance['current_state_key']] ?? null;
        if (! is_string($state) || ! isset($definition['states'][$state])) {
            throw new DomainError('state_mapping_required');
        }
        $stage = $this->stage($pipeline['id'], $state, $stageKey);

        return ['instance_key' => $key, 'instance_version' => $instance['version'], 'source_revision' => $instance['workflow_revision_id'],
            'pipeline_key' => $pipelineKey, 'pipeline_version' => $pipeline['version'], 'target_state' => $state, 'target_stage' => $stage->stage_key];
    }

    public function moveToPipeline(ExecutionContext $context, array $preview, string $operation): array
    {
        $this->authorize($context, 'pipeline.manage');
        $this->authorize($context, 'workflow.transition');

        return $this->command($context, $operation, ['action' => 'move_pipeline', 'preview' => $preview], function () use ($context, $preview, $operation) {
            [$instance] = $this->locked($context, $preview['instance_key'], $preview['instance_version']);
            $pipeline = $this->pipelines->get($context, $preview['pipeline_key']);
            if ($pipeline['version'] !== $preview['pipeline_version'] || $instance['workflow_revision_id'] !== $preview['source_revision']) {
                throw new DomainError('preview_stale');
            }
            $definition = $this->workflows->revision($context, $pipeline['workflow_revision_id'])['definition'];
            $state = $preview['target_state'];
            if (! isset($definition['states'][$state])) {
                throw new DomainError('state_mapping_required');
            }
            $stage = $this->stage($pipeline['id'], $state, $preview['target_stage']);
            $old = $instance['pipeline_id'] === null ? null : $this->db->table('tm_pipeline_stages')->where('pipeline_id', $instance['pipeline_id'])->where('stage_key', $instance['active_stage_key'])->first();
            $this->lockStages(array_filter([$stage->id, $old?->id]));
            $terminal = ($definition['states'][$state]['terminal'] ?? 'none') !== 'none';
            if (! $terminal) {
                $this->capacity($context, $stage, $instance['id']);
                $this->reserve($context, $instance);
            } else {
                $this->db->table('tm_active_process_slots')->where('instance_id', $instance['id'])->delete();
            }
            $this->subjects->handler($instance['subject_type'])->guard($context, $instance['subject_id'], $instance, 'move_pipeline', ['pipeline_key' => $pipeline['pipeline_key'], 'state' => $state]);
            $this->lifecycle?->beforeTransition($context, $instance, ['key' => 'move_pipeline', 'from' => $instance['current_state_key'], 'to' => $state, 'target_revision_id' => $pipeline['workflow_revision_id'], 'force_exit' => true], []);
            $this->closeVisit($context, $instance['id']);
            $this->visit($context, $instance['id'], $stage, $operation);
            if ($terminal) {
                $this->closeVisit($context, $instance['id']);
            }
            $this->db->table('tm_workflow_instances')->where('id', $instance['id'])->update(['pipeline_id' => $pipeline['id'], 'workflow_revision_id' => $pipeline['workflow_revision_id'],
                'workflow_key' => $this->db->table('tm_workflow_definitions')->where('id', $pipeline['workflow_revision_id'])->value('key'),
                'current_state_key' => $state, 'active_stage_key' => $stage->stage_key, 'version' => $instance['version'] + 1, 'lifecycle' => $terminal ? 'closed' : 'active', 'completed_at' => $terminal ? $this->now() : null]);
            if ($instance['task_id'] !== null) {
                $this->db->table('tm_tasks')->where('id', $instance['task_id'])->increment('version');
            }
            $this->history($context, $instance, $state, $operation, $pipeline['workflow_revision_id']);
            $this->event($context, $instance['instance_key'], 'instance_pipeline_moved', $operation, ['before' => ['pipeline' => $instance['pipeline_id'], 'state' => $instance['current_state_key']], 'after' => ['pipeline' => $pipeline['id'], 'state' => $state]], $instance['task_id']);

            return $this->get($context, $instance['instance_key']);
        });
    }

    public function changeBoardVisibility(ExecutionContext $context, string $key): array
    {
        // Board membership/filter persistence belongs to the host UI; this is a read-only domain lookup.
        return $this->get($context, $key);
    }

    public function reconcile(ExecutionContext $context, string $key): array
    {
        $instance = $this->get($context, $key);
        try {
            $this->subjects->handler($instance['subject_type'])->guard($context, $instance['subject_id'], $instance, 'reconcile', []);

            return ['instance_key' => $key, 'status' => 'consistent'];
        } catch (DomainError $error) {
            return ['instance_key' => $key, 'status' => 'conflict', 'reason' => 'host_guard_conflict'];
        }
    }

    private function locked(ExecutionContext $context, string $key, int $version, string $subjectCapability = 'edit'): array
    {
        $before = $this->get($context, $key);
        if ($before['subject_type'] === 'task') {
            ScopeGraphLock::acquire($this->db, $context);
        }
        $subject = $this->subject($context, $before['subject_type'], $before['subject_id'], $subjectCapability, true);
        $instance = $this->db->table('tm_workflow_instances')->where('scope_key', $context->scope)->where('id', $before['id'])->lockForUpdate()->first();
        if ($instance->version !== $version) {
            throw new DomainError('version_conflict');
        }
        if ($instance->subject_type !== $before['subject_type'] || $instance->subject_id !== $before['subject_id']) {
            throw new DomainError('subject_identity_conflict');
        }

        return [(array) $instance, $subject];
    }

    public function lockForCommand(ExecutionContext $context, string $key, int $version, string $subjectCapability = 'edit'): array
    {
        if ($this->db->transactionLevel() < 1) {
            throw new DomainError('lock_requires_transaction');
        }
        if (! in_array($subjectCapability, ['view', 'edit'], true)) {
            throw new DomainError('capability_invalid');
        }

        return $this->locked($context, $key, $version, $subjectCapability);
    }

    private function stage(int $pipeline, string $state, ?string $key): object
    {
        $query = $this->db->table('tm_pipeline_stages')->where('pipeline_id', $pipeline)->where('state_key', $state);
        if ($key !== null) {
            $query->where('stage_key', $key);
        }
        $stages = $query->get();
        if ($stages->count() !== 1) {
            throw new DomainError($stages->count() > 1 ? 'stage_ambiguous' : 'stage_mapping_invalid');
        }

        return $stages->first();
    }

    private function lockStages(array $ids): void
    {
        if ($ids) {
            $this->db->table('tm_pipeline_stages')->whereIn('id', array_unique($ids))->orderBy('id')->lockForUpdate()->get();
        }
    }

    private function capacity(ExecutionContext $context, object $stage, ?int $exclude = null): void
    {
        if ($stage->capacity === null) {
            return;
        }
        $query = $this->db->table('tm_workflow_instances')->where('scope_key', $context->scope)->where('pipeline_id', $stage->pipeline_id)->where('active_stage_key', $stage->stage_key)->where('lifecycle', 'active');
        if ($exclude !== null) {
            $query->where('id', '!=', $exclude);
        }
        if ($query->count() >= $stage->capacity) {
            throw new DomainError('capacity_exceeded');
        }
    }

    private function reserve(ExecutionContext $context, array $instance): void
    {
        $key = ['scope_key' => $context->scope, 'subject_type' => $instance['subject_type'], 'subject_id' => $instance['subject_id'], 'slot_key' => $instance['slot_key']];
        $this->db->table('tm_active_process_slots')->insertOrIgnore($key + ['instance_id' => $instance['id']]);
        $slot = $this->db->table('tm_active_process_slots')->where($key)->first();
        if (! $slot || $slot->instance_id !== $instance['id']) {
            throw new DomainError('active_slot_conflict');
        }
    }

    private function visit(ExecutionContext $context, int $instanceId, object $stage, string $operation): void
    {
        $stage = $this->db->table('tm_pipeline_stages')->where('id', $stage->id)->first();
        $number = ($this->db->table('tm_stage_visits')->where('instance_id', $instanceId)->max('visit_no') ?? 0) + 1;
        $visitId = $this->db->table('tm_stage_visits')->insertGetId(['scope_key' => $context->scope, 'instance_id' => $instanceId, 'pipeline_id' => $stage->pipeline_id, 'stage_key' => $stage->stage_key, 'visit_no' => $number, 'entered_at' => $this->now()]);
        $this->lifecycle?->entered($context, $instanceId, $visitId, $stage, $operation);
    }

    private function closeVisit(ExecutionContext $context, int $instanceId): void
    {
        $this->lifecycle?->leaving($context, $instanceId);
        $this->db->table('tm_stage_visits')->where('instance_id', $instanceId)->whereNull('exited_at')->update(['exited_at' => $this->now()]);
    }

    private function history(ExecutionContext $context, array $instance, string $state, string $operation, ?int $targetRevision = null): void
    {
        $this->db->table('tm_instance_transitions')->insert(['scope_key' => $context->scope, 'instance_id' => $instance['id'],
            ...StateIndex::transition($this->db, $instance['workflow_revision_id'], $instance['current_state_key'], $targetRevision ?? $instance['workflow_revision_id'], $state),
            'from_state' => $instance['current_state_key'], 'to_state' => $state, 'actor_ref' => $context->actor, 'operation_id' => $operation, 'occurred_at' => $this->now()]);
    }

    private function command(ExecutionContext $context, string $operation, array $payload, callable $execute): array
    {
        try {
            return (new CommandExecutor($this->db))->execute($context, $operation, $payload, $execute, fn ($r) => $this->get($context, $r['instance_key']));
        } catch (DomainError $error) {
            $reason = preg_match('/^[a-z][a-z0-9_]{0,79}$/D', $error->getMessage()) ? $error->getMessage() : 'host_guard_denied';
            $this->db->table('tm_security_decisions')->insert(['scope_key' => $context->scope, 'actor_ref' => $context->actor, 'action' => $payload['action'], 'reason' => $reason,
                'target_hash' => hash('sha256', $payload['key'] ?? $payload['subject'] ?? ''), 'occurred_at' => $this->now()]);
            throw $error;
        }
    }

    private function event(ExecutionContext $c, string $key, string $action, string $operation, array $safePayload, ?int $taskId): void
    {
        (new EventRecorder($this->db, $this->clock))->append($c, 'instance', $key, $action, $operation, $safePayload, $taskId);
    }

    private function now(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
