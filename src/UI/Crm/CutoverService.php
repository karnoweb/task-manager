<?php

namespace Karnoweb\TaskManager\UI\Crm;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Karnoweb\TaskManager\CommandExecutor;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\AuthorizationProvider;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\EventRecorder;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Scheduling\DateService;
use Karnoweb\TaskManager\Scheduling\RecurrenceRule;
use Karnoweb\TaskManager\TaskService;
use Karnoweb\TaskManager\Workflow\InstanceService;
use Karnoweb\TaskManager\Workflow\PipelineService;
use Karnoweb\TaskManager\Workflow\ProjectService;
use Karnoweb\TaskManager\Workflow\StateIndex;
use Karnoweb\TaskManager\Workflow\WorkflowService;

/** Explicit same-database CRM migration. Never runs automatically in a provider boot. */
final class CutoverService
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock,
        private readonly AuthorizationProvider $authorization, private readonly HostAccess $access,
        private readonly TaskService $tasks, private readonly InstanceService $instances,
        private readonly WorkflowService $workflows, private readonly ProjectService $projects,
        private readonly PipelineService $pipelines, private readonly DateService $dates)
    {
    }

    private function authorize(ExecutionContext $c): void
    {
        $c->require('migration.manage');
        $this->authorization->authorize($c, 'migration.manage');
    }

    public function get(ExecutionContext $c, string $source, bool $lock = false): array
    {
        $this->authorize($c);
        $q = $this->db->table('tm_host_cutovers')->where('scope_key', $c->scope)->where('source_key', $source)->where('owner_ref', $c->actor);
        if ($lock) {
            $q->lockForUpdate();
        } $r = $q->first();
        if (! $r) {
            throw new DomainError('not_found');
        }

        return [...(array) $r, 'mapping' => json_decode($r->mapping, true, flags: JSON_THROW_ON_ERROR)];
    }

    public function configure(ExecutionContext $c, string $source, array $mapping, string $operation): array
    {
        $this->authorize($c);
        if (! in_array($source, ['crm.tasks', 'crm.deals'], true)) {
            throw new DomainError('migration_source_invalid');
        }
        $revision = $this->workflows->revision($c, $mapping['revision_id'] ?? 0);
        if ($source === 'crm.tasks') {
            $project = $this->projects->get($c, $mapping['project_key'] ?? '', 'manage');
            if ($project['workflow_revision_id'] !== $revision['id']) {
                throw new DomainError('migration_mapping_invalid');
            }
            foreach (['open', 'done', 'cancelled'] as $state) {
                if (! isset($mapping['states'][$state],$revision['definition']['states'][$mapping['states'][$state]])) {
                    throw new DomainError('migration_mapping_invalid');
                }
            }
            foreach (['open' => 'none', 'done' => 'success', 'cancelled' => 'cancelled'] as $old => $terminal) {
                if (($revision['definition']['states'][$mapping['states'][$old]]['terminal'] ?? 'none') !== $terminal) {
                    throw new DomainError('migration_mapping_invalid');
                }
            }
        } else {
            $p = $this->pipelines->get($c, $mapping['pipeline_key'] ?? '');
            if ($p['workflow_revision_id'] !== $revision['id'] || ! is_array($mapping['stages'] ?? null)) {
                throw new DomainError('migration_mapping_invalid');
            }
            foreach ($mapping['stages'] as $legacy => $target) {
                $stage = $this->db->table('tm_pipeline_stages')->where('pipeline_id', $p['id'])->where('stage_key', $target)->first();
                $old = $this->db->table('crm_pipeline_stages')->where('id', $legacy)->first();
                $terminal = $stage?->state_key === null ? null : ($revision['definition']['states'][$stage->state_key]['terminal'] ?? 'none');
                $expected = match ($old?->type) {
                    'open' => 'none','won' => 'success','lost' => 'failure',default => null
                };
                if (! $stage || ! $old || $terminal !== $expected) {
                    throw new DomainError('migration_mapping_invalid');
                }
            }
        }

        return (new CommandExecutor($this->db))->execute($c, $operation, ['action' => 'crm_migration_configure', 'source' => $source, 'mapping' => $mapping], function () use ($c, $source, $mapping) {
            if ($this->db->table('tm_host_cutovers')->where('scope_key', $c->scope)->where('source_key', $source)->exists()) {
                throw new DomainError('migration_exists');
            }
            $this->db->table('tm_host_cutovers')->insert(['scope_key' => $c->scope, 'source_key' => $source, 'owner_ref' => $c->actor, 'mapping' => json_encode($mapping, JSON_THROW_ON_ERROR)]);

            return $this->get($c, $source);
        }, fn () => $this->get($c, $source));
    }

    private function legacy(ExecutionContext $c, string $source): Builder
    {
        $q = $this->db->table($source === 'crm.tasks' ? 'crm_followups' : 'crm_deals');
        if ($source === 'crm.deals') {
            $q->whereNull('crm_deals.deleted_at');
        }
        $this->access->constrain($q, $c, $source, 'migrate');

        return $q;
    }

    public function inventory(ExecutionContext $c, string $source): array
    {
        $this->get($c, $source);
        $q = $this->legacy($c, $source);

        return ['source' => $source, 'rows' => $q->count(), 'schema' => $this->db->getSchemaBuilder()->getColumnListing($source === 'crm.tasks' ? 'crm_followups' : 'crm_deals')];
    }

    private function digest(array $snapshot): string
    {
        ksort($snapshot);

        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }

    private function snapshot(string $source, object $row): array
    {
        $data = (array) $row;
        if ($source === 'crm.deals') {
            $data['_history'] = $this->db->table('crm_deal_stage_histories')->where('deal_id', $row->id)->orderBy('occurred_at')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        } elseif (($row->recurring_task_id ?? null) !== null) {
            $data['_series'] = (array) $this->db->table('crm_recurring_tasks')->where('id', $row->recurring_task_id)->first();
        }

        return $data;
    }

    private function target(array $import): array
    {
        if ($import['task_id'] !== null) {
            $t = (array) $this->db->table('tm_tasks')->where('id', $import['task_id'])->first();
            $i = (array) $this->db->table('tm_workflow_instances')->where('task_id', $import['task_id'])->where('slot_key', 'main')->orderByDesc('id')->first();

            return ['task' => array_intersect_key($t, array_flip(['public_key', 'title', 'description', 'priority', 'due_at', 'version', 'deleted_at', 'archived_at', 'project_id', 'primary_assignee'])), 'instance' => array_intersect_key($i, array_flip(['instance_key', 'current_state_key', 'version', 'completed_at', 'workflow_revision_id', 'primary_assignee']))];
        }
        $i = (array) $this->db->table('tm_workflow_instances')->where('id', $import['instance_id'])->first();

        return array_intersect_key($i, array_flip(['instance_key', 'current_state_key', 'version', 'lifecycle', 'active_stage_key', 'workflow_revision_id', 'pipeline_id', 'completed_at']));
    }

    public function importBatch(ExecutionContext $c, string $source, int $version, int $afterId, string $operation, int $limit = 100): array
    {
        $this->authorize($c);
        if ($afterId < 0 || $limit < 1 || $limit > 100) {
            throw new DomainError('pagination_invalid');
        }

        return (new CommandExecutor($this->db))->execute($c, $operation, ['action' => 'crm_import', 'source' => $source, 'version' => $version, 'after' => $afterId, 'limit' => $limit], function () use ($c, $source, $version, $afterId, $limit, $operation) {
            $batch = $this->get($c, $source, true);
            if ($batch['version'] !== $version || $batch['mode'] !== 'shadow') {
                throw new DomainError('migration_state_conflict');
            }
            $rows = $this->legacy($c, $source)->where('id', '>', $afterId)->orderBy('id')->limit($limit)->lockForUpdate()->get();
            $keys = [];
            foreach ($rows as $row) {
                $snapshot = $this->snapshot($source, $row);
                $hash = $this->digest($snapshot);
                $prior = $this->db->table('tm_host_imports')->where('cutover_id', $batch['id'])->where('legacy_type', $source)->where('legacy_id', (string) $row->id)->first();
                if ($prior) {
                    if ($prior->source_hash !== $hash || $prior->target_hash !== $this->digest($this->target((array) $prior))) {
                        throw new DomainError('migration_source_changed');
                    } $keys[] = $prior->id;

                    continue;
                }
                if ($source === 'crm.tasks') {
                    $import = $this->task($c, $batch, $snapshot, $operation.':'.$row->id);
                } else {
                    $import = $this->deal($c, $batch, $snapshot, $operation.':'.$row->id);
                }
                $id = $this->db->table('tm_host_imports')->insertGetId(['cutover_id' => $batch['id'], 'legacy_type' => $source, 'legacy_id' => (string) $row->id, 'source_hash' => $hash, 'source_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), ...$import, 'target_hash' => $this->digest($this->target($import)), 'imported_at' => $this->clock->now()->format('Y-m-d H:i:s')]);
                $keys[] = $id;
            }
            $this->db->table('tm_host_cutovers')->where('id', $batch['id'])->update(['reconciled_hash' => null]);

            return ['source' => $source, 'import_ids' => $keys, 'after_id' => $rows->last()?->id ?? $afterId];
        }, function ($r) use ($c, $source) {
            $this->get($c, $source);
            foreach ($r['import_ids'] as $id) {
                $i = $this->db->table('tm_host_imports')->where('id', $id)->first();
                if (! $i) {
                    throw new DomainError('not_found');
                }$legacy = $this->legacy($c, $source)->where('id', $i->legacy_id)->exists();
                if (! $legacy) {
                    throw new DomainError('not_found');
                }
            }
        });
    }

    private function task(ExecutionContext $c, array $batch, array $row, string $operation): array
    {
        $mapping = $batch['mapping'];
        $revision = $this->workflows->revision($c, $mapping['revision_id']);
        $state = $mapping['states'][$row['status']] ?? null;
        if (! is_string($state)) {
            throw new DomainError('migration_mapping_invalid');
        }
        $task = $this->tasks->create($c, $row['title'] ?: 'CRM task '.$row['id'], $operation.':create');
        $task = $this->tasks->mutate($c, $task['public_key'], $task['version'], $operation.':fields', 'update', ['priority' => $row['priority'] ?? 'normal', 'description' => $row['outcome'] ?? null]);
        $type = $row['type'] === null ? 'task' : ($mapping['types'][$row['type']] ?? null);
        if (! is_string($type)) {
            throw new DomainError('migration_type_mapping_required');
        }
        $preview = $this->projects->previewTaskMove($c, $task['public_key'], $mapping['project_key'], $type, ['pending' => $revision['definition']['initial']]);
        if ($preview['revision_id'] !== $revision['id']) {
            throw new DomainError('migration_mapping_invalid');
        }$task = $this->projects->moveTask($c, $preview, $operation.':project');
        $task = $this->dates->change($c, $task['public_key'], $task['version'], null, null, $row['due_at'], 'UTC', false, 'reject', $operation.':dates');
        $instance = $this->db->table('tm_workflow_instances')->where('task_id', $task['id'])->where('slot_key', 'main')->orderByDesc('id')->first();
        $terminal = ($revision['definition']['states'][$state]['terminal'] ?? 'none') !== 'none';
        if ($terminal && ($row['completed_at'] === null || $row['completed_at'] < $row['created_at'])) {
            throw new DomainError('migration_history_invalid');
        }
        $this->db->table('tm_workflow_instances')->where('id', $instance->id)->update(['current_state_key' => $state, 'lifecycle' => $terminal ? 'closed' : 'active', 'completed_at' => $terminal ? $row['completed_at'] : null, 'started_at' => $row['created_at']]);
        if ($terminal) {
            $this->db->table('tm_active_process_slots')->where('instance_id', $instance->id)->delete();
        }
        if ($row['assigned_to'] !== null) {
            $this->db->table('tm_workflow_instances')->where('id', $instance->id)->update(['primary_assignee' => (string) $row['assigned_to']]);
        }
        if (($row['recurring_task_id'] ?? null) !== null) {
            $this->series($c, $batch, $row, $task);
        }
        $alias = match ($row['subject_type']) {
            'crm_deal' => 'crm.deal','crm_lead' => 'crm.lead',default => throw new DomainError('migration_mapping_invalid')
        };
        $this->db->table('tm_host_task_links')->insert(['scope_key' => $c->scope, 'task_id' => $task['id'], 'subject_type' => $alias, 'subject_id' => (string) $row['subject_id']]);

        return ['task_id' => $task['id'], 'instance_id' => null, 'target_version' => $task['version']];
    }

    private function series(ExecutionContext $c, array $batch, array $row, array $task): void
    {
        $s = $this->db->table('crm_recurring_tasks')->where('id', $row['recurring_task_id'])->lockForUpdate()->first();
        if (! $s || $s->subject_type !== $row['subject_type'] || (string) $s->subject_id !== (string) $row['subject_id'] || ! is_int($row['occurrence_index']) || $row['occurrence_index'] < 0 || $row['occurrence_index'] > 10000) {
            throw new DomainError('migration_history_invalid');
        }
        $map = $this->db->table('tm_host_series')->where('cutover_id', $batch['id'])->where('legacy_series_id', $s->id)->first();
        if (! $map) {
            if ($row['occurrence_index'] !== 0) {
                throw new DomainError('migration_series_order_invalid');
            }
            $rule = RecurrenceRule::parse(['frequency' => $s->frequency, 'after_completion' => true, 'missing_day' => 'last_day', 'dst_policy' => $batch['mapping']['dst_policy'] ?? 'earliest', ...($s->ends_at === null ? [] : ['until' => (new \DateTimeImmutable($s->ends_at, new \DateTimeZone('UTC')))->format(DATE_ATOM)])]);
            $current = (array) $this->db->table('tm_tasks')->where('id', $task['id'])->first();
            $template = ['title' => $current['title'], 'description' => $current['description'], 'priority' => $current['priority'], 'project_id' => $current['project_id'], 'type' => $current['type_key'], 'workflow_revision_id' => $batch['mapping']['revision_id'], 'starts_at' => null, 'ends_at' => null, 'due_at' => $s->starts_at, 'all_day' => false, 'local_start_date' => null, 'local_end_date' => null];
            $recurrence = $this->db->table('tm_recurrences')->insertGetId(['series_key' => (string) Str::uuid(), 'scope_key' => $c->scope, 'owner_ref' => $c->actor, 'task_id' => $task['id'], 'calendar_system' => 'gregorian', 'timezone' => $s->timezone, 'local_anchor' => $s->anchor_local, 'state' => $s->is_active ? 'active' : 'stopped', 'rule' => json_encode($rule, JSON_THROW_ON_ERROR), 'template' => json_encode($template, JSON_THROW_ON_ERROR)]);
            $this->db->table('tm_host_series')->insert(['cutover_id' => $batch['id'], 'legacy_series_id' => $s->id, 'recurrence_id' => $recurrence]);
        } else {
            $recurrence = $map->recurrence_id;
        }
        $anchor = Carbon::parse($s->anchor_local, $s->timezone);
        $local = match ($s->frequency) {
            'daily' => $anchor->copy()->addDays($row['occurrence_index']),'weekly' => $anchor->copy()->addWeeks($row['occurrence_index']),'monthly' => $anchor->copy()->addMonthsNoOverflow($row['occurrence_index']),default => throw new DomainError('migration_mapping_invalid')
        };
        $occurrence = 'gregorian:'.$local->format('Y-m-d\TH:i:s');
        $expected = $local->copy()->utc()->format('Y-m-d H:i:s');
        $this->db->table('tm_recurrence_occurrences')->insert(['recurrence_id' => $recurrence, 'occurrence_key' => $occurrence, 'task_id' => $task['id'], 'rule_revision' => 1, 'scheduled_at' => $row['due_at'], 'state' => $row['status'] === 'cancelled' ? 'invalidated' : 'active', 'created_at' => $row['created_at']]);
        if ($row['due_at'] !== $expected || $row['status'] === 'cancelled') {
            $this->db->table('tm_recurrence_exceptions')->insert(['recurrence_id' => $recurrence, 'occurrence_key' => $occurrence, 'cancelled' => $row['status'] === 'cancelled', 'override_at' => $row['status'] === 'cancelled' ? null : $row['due_at']]);
        }
    }

    private function deal(ExecutionContext $c, array $batch, array $row, string $operation): array
    {
        $map = $batch['mapping'];
        $target = $map['stages'][(string) $row['pipeline_stage_id']] ?? null;
        if (! is_string($target)) {
            throw new DomainError('migration_mapping_invalid');
        }
        $p = $this->pipelines->get($c, $map['pipeline_key']);
        $revision = $this->workflows->revision($c, $map['revision_id']);
        $instance = $this->instances->start($c, 'crm.deal', (string) $row['id'], $revision['id'], $operation.':start', pipelineKey: $p['pipeline_key']);
        $stage = $this->db->table('tm_pipeline_stages')->where('pipeline_id', $p['id'])->where('stage_key', $target)->first();
        $state = $stage->state_key;
        $history = $this->db->table('crm_deal_stage_histories')->where('deal_id', $row['id'])->orderBy('occurred_at')->orderBy('id')->get();
        $previous = null;
        $at = $row['created_at'];
        foreach ($history as $h) {
            $from = $map['stages'][(string) $h->from_stage_id] ?? null;
            $to = $map['stages'][(string) $h->to_stage_id] ?? null;
            if (! $from || ! $to || ($previous !== null && $from !== $previous) || $h->occurred_at < $at) {
                throw new DomainError('migration_history_invalid');
            }
            $a = $this->db->table('tm_pipeline_stages')->where('pipeline_id', $p['id'])->where('stage_key', $from)->value('state_key');
            $b = $this->db->table('tm_pipeline_stages')->where('pipeline_id', $p['id'])->where('stage_key', $to)->value('state_key');
            $this->db->table('tm_instance_transitions')->insert(['scope_key' => $c->scope, 'instance_id' => $instance['id'], ...StateIndex::transition($this->db, $revision['id'], $a, $revision['id'], $b), 'from_state' => $a, 'to_state' => $b, 'actor_ref' => (string) ($h->actor_id ?? $c->actor), 'operation_id' => $operation.':history:'.$h->id, 'occurred_at' => $h->occurred_at]);
            $previous = $to;
            $at = $h->occurred_at;
        }
        if ($previous !== null && $previous !== $target) {
            throw new DomainError('migration_history_invalid');
        }
        $terminal = ($revision['definition']['states'][$state]['terminal'] ?? 'none') !== 'none';
        $this->db->table('tm_workflow_instances')->where('id', $instance['id'])->update(['current_state_key' => $state, 'active_stage_key' => $target, 'lifecycle' => $terminal ? 'closed' : 'active', 'completed_at' => $terminal ? ($row['stage_entered_at'] ?? $row['updated_at']) : null, 'started_at' => $row['created_at']]);
        // Rebuild actual stage intervals. Historical SLA policies cannot be inferred from today's catalog.
        $initialVisit = $this->db->table('tm_stage_visits')->where('instance_id', $instance['id'])->first();
        $number = 0;
        $entered = $row['created_at'];
        foreach ($history as $h) {
            $oldStage = $map['stages'][(string) $h->from_stage_id];
            $this->importVisit($c, $instance, $p['id'], $oldStage, ++$number, $entered, $h->occurred_at, $number === 1 ? $initialVisit?->id : null);
            $entered = $h->occurred_at;
        }
        $this->importVisit($c, $instance, $p['id'], $target, ++$number, $row['stage_entered_at'] ?? $entered, $terminal ? ($row['stage_entered_at'] ?? $row['updated_at']) : null, $number === 1 ? $initialVisit?->id : null);
        if ($terminal) {
            $this->db->table('tm_active_process_slots')->where('instance_id', $instance['id'])->delete();
        }

        return ['task_id' => null, 'instance_id' => $instance['id'], 'target_version' => $instance['version']];
    }

    private function importVisit(ExecutionContext $c, array $instance, int $pipeline, string $stage, int $number, string $entered, ?string $exited, ?int $existing): void
    {
        if ($exited !== null && $exited < $entered) {
            throw new DomainError('migration_history_invalid');
        }
        $data = ['scope_key' => $c->scope, 'instance_id' => $instance['id'], 'pipeline_id' => $pipeline, 'stage_key' => $stage, 'visit_no' => $number, 'entered_at' => $entered, 'exited_at' => $exited, 'policy_id' => null, 'deadline_at' => null, 'warning_at' => null, 'stale_at' => null, 'last_activity_at' => null, 'anchor_at' => null, 'budget_seconds' => null, 'remaining_seconds' => null, 'elapsed_seconds' => $exited === null ? 0 : max(0, strtotime($exited.' UTC') - strtotime($entered.' UTC'))];
        if ($existing !== null) {
            $this->db->table('tm_stage_visits')->where('id', $existing)->update($data);
        } else {
            $this->db->table('tm_stage_visits')->insert($data);
        }
    }

    public function reconcile(ExecutionContext $c, string $source): array
    {
        return $this->db->transaction(function () use ($c, $source) {
            $b = $this->get($c, $source, true);
            $problems = [];
            $fingerprints = hash_init('sha256');
            $seen = 0;
            $this->legacy($c, $source)->orderBy('id')->chunkById(100, function ($rows) use ($b, &$problems, $fingerprints, &$seen) {
                foreach ($rows as $r) {
                    $seen++;
                    $i = $this->db->table('tm_host_imports')->where('cutover_id', $b['id'])->where('legacy_type', $b['source_key'])->where('legacy_id', (string) $r->id)->first();
                    if (! $i || $i->source_hash !== $this->digest($this->snapshot($b['source_key'], $r)) || $i->target_hash !== $this->digest($this->target((array) $i))) {
                        $problems[] = (string) $r->id;
                        if (count($problems) > 100) {
                            throw new DomainError('reconciliation_repair_required');
                        }
                    } else {
                        hash_update($fingerprints, $i->source_hash.':'.$i->target_hash.'|');
                    }
                }
            });
            $count = $this->db->table('tm_host_imports')->where('cutover_id', $b['id'])->count();
            if ($count !== $seen) {
                $problems[] = 'inventory_count';
            }$hash = $problems ? null : hash_final($fingerprints);
            $this->db->table('tm_host_cutovers')->where('id', $b['id'])->update(['reconciled_hash' => $hash]);

            return ['source' => $source, 'consistent' => ! $problems, 'rows' => $seen, 'problems' => $problems, 'hash' => $hash];
        });
    }

    public function switch(ExecutionContext $c, string $source, int $version, string $mode, string $operation): array
    {
        $this->authorize($c);
        if (! in_array($mode, ['active', 'rolled_back'], true)) {
            throw new DomainError('migration_mode_invalid');
        }

        return (new CommandExecutor($this->db))->execute($c, $operation, ['action' => 'crm_cutover', 'source' => $source, 'version' => $version, 'mode' => $mode], function () use ($c, $source, $version, $mode, $operation) {
            $b = $this->get($c, $source, true);
            if ($b['version'] !== $version || ($mode === 'active' && $b['mode'] !== 'shadow') || ($mode === 'rolled_back' && $b['mode'] !== 'active')) {
                throw new DomainError('migration_state_conflict');
            }
            if ($mode === 'rolled_back' && $this->db->table('tm_outbox')->where('scope_key', $c->scope)->where('id', '>', $b['cutover_event_id'] ?? 0)->exists()) {
                throw new DomainError('rollback_reverse_sync_required');
            }
            $check = $this->reconcile($c, $source);
            if (! $check['consistent']) {
                throw new DomainError($mode === 'rolled_back' ? 'rollback_reverse_sync_required' : 'reconciliation_required');
            }
            $this->db->table('tm_host_cutovers')->where('id', $b['id'])->update(['mode' => $mode, 'version' => $version + 1, $mode === 'active' ? 'cutover_at' : 'rollback_at' => $this->clock->now()->format('Y-m-d H:i:s')]);
            (new EventRecorder($this->db, $this->clock))->append($c, 'migration', $source, 'crm_writer_changed', $operation, ['mode' => $mode, 'rows' => $check['rows']]);
            if ($mode === 'active') {
                $this->db->table('tm_host_cutovers')->where('id', $b['id'])->update(['cutover_event_id' => $this->db->table('tm_outbox')->where('scope_key', $c->scope)->max('id')]);
            }

            return $this->get($c, $source);
        }, fn () => $this->get($c, $source));
    }

    public function shadow(ExecutionContext $c, string $source, string $legacyId): array
    {
        $b = $this->get($c, $source);
        $legacy = $this->legacy($c, $source)->where('id',$legacyId)->first();
        if (! $legacy) {
            throw new DomainError('not_found');
        }
        $i = $this->db->table('tm_host_imports')->where('cutover_id',$b['id'])->where('legacy_type',$source)->where('legacy_id',$legacyId)->first();
        if ($i) {
            if ($i->task_id !== null) {
                $key = $this->db->table('tm_tasks')->where('id',$i->task_id)->value('public_key');
                $this->tasks->get($c,$key);
            } else {
                $key = $this->db->table('tm_workflow_instances')->where('id',$i->instance_id)->value('instance_key');
                $this->instances->get($c,$key);
            }
        }

        return ['source' => $source, 'legacy_id' => $legacyId, 'reader' => $b['mode'] === 'active' ? 'engine' : 'legacy', 'legacy_state' => $source === 'crm.tasks' ? $legacy->status : (string) $legacy->pipeline_stage_id, 'engine' => $i ? $this->target((array) $i) : null];
    }
}
