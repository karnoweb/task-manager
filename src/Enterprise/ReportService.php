<?php

namespace Karnoweb\TaskManager\Enterprise;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Karnoweb\TaskManager\CommandExecutor;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\AuthorizationProvider;
use Karnoweb\TaskManager\Contracts\AuthorizationRevision;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\EventRecorder;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Group\GroupService;
use Karnoweb\TaskManager\Scheduling\LocalTime;
use Karnoweb\TaskManager\Schema\Tables;
use Karnoweb\TaskManager\TaskGraph\ScopeGraphLock;
use Karnoweb\TaskManager\Workflow\InstanceService;
use Karnoweb\TaskManager\Workflow\ProjectService;

final class ReportService
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization, private readonly AuthorizationRevision $revision, private readonly InstanceService $instances, private readonly ProjectService $projects, private readonly GroupService $groups)
    {
        Tables::assertConnection($db);
    }

    private function authorize(ExecutionContext $context): void
    {
        $context->require('report.view');
        $this->authorization->authorize($context, 'report.view');
    }

    private function definition(ExecutionContext $context, array $input): array
    {
        if (array_diff(array_keys($input), ['kind', 'project_key', 'pipeline_id', 'group_key', 'as_of']) || ! in_array($input['kind'] ?? 'process', ['process', 'group'], true)) {
            throw new DomainError('report_invalid');
        }$d = ['kind' => $input['kind'] ?? 'process', 'project_key' => $input['project_key'] ?? null, 'pipeline_id' => $input['pipeline_id'] ?? null, 'group_key' => $input['group_key'] ?? null, 'as_of' => $input['as_of'] ?? null];
        if ($d['project_key'] !== null) {
            $this->projects->get($context, $d['project_key']);
        }if ($d['pipeline_id'] !== null && (! is_int($d['pipeline_id']) || $d['pipeline_id'] < 1)) {
            throw new DomainError('report_invalid');
        }if ($d['kind'] === 'group') {
            if (! is_string($d['group_key'])) {
                throw new DomainError('report_invalid');
            }$this->groups->get($context, $d['group_key']);
        }
        if ($d['as_of'] !== null) {
            if (! is_string($d['as_of'])) {
                throw new DomainError('report_invalid');
            }try {
                $at = new \DateTimeImmutable($d['as_of'], new \DateTimeZone('UTC'));
            } catch (\Exception) {
                throw new DomainError('date_invalid');
            }if ($at > $this->clock->now()) {
                throw new DomainError('report_as_of_future');
            }$d['as_of'] = LocalTime::stamp($at);
        }

        return $d;
    }

    private function permission(ExecutionContext $context): string
    {
        return VisibilityEpoch::fingerprint($this->db, $context, $this->revision->revision($context));
    }

    private function source(ExecutionContext $context, array $definition): string
    {
        $q = $this->db->table(Tables::name('outbox'))->where('scope_key', $context->scope);
        if ($definition['as_of'] !== null) {
            $q->where('occurred_at', '<=', $definition['as_of']);
        }$stats = $q->selectRaw('COUNT(*) as rows_count, COALESCE(SUM(id),0) as id_sum, COALESCE(MAX(id),0) as last_id')->first();

        return hash('sha256', json_encode([(array) $stats, $definition['as_of'] === null ? LocalTime::stamp($this->clock->now()) : null], JSON_THROW_ON_ERROR));
    }

    private function secondsExpression(string $start, string $end): string
    {
        return match ($this->db->getDriverName()) {
            'sqlite' => "(CAST(strftime('%s', $end) AS INTEGER) - CAST(strftime('%s', $start) AS INTEGER))",'pgsql' => "EXTRACT(EPOCH FROM (($end)::timestamp - ($start)::timestamp))",default => "TIMESTAMPDIFF(SECOND, $start, $end)"
        };
    }

    private function permittedIds(ExecutionContext $context, array $d): Builder
    {
        $at = $d['as_of'] ?? LocalTime::stamp($this->clock->now());
        $query = $this->instances->authorizedQuery($context)->where('started_at', '<=', $at);
        if ($d['project_key'] !== null) {
            $project = $this->projects->get($context, $d['project_key']);
            $query->whereIn('task_id', $this->db->table(Tables::name('tasks'))->where('project_id', $project['id'])->select('id'));
        }if ($d['pipeline_id'] !== null) {
            $query->where('pipeline_id', $d['pipeline_id']);
        }if ($d['kind'] === 'group') {
            $group = $this->groups->get($context, $d['group_key']);
            $query->whereIn('id', $this->db->table(Tables::name('process_group_members'))->where('group_id', $group['id'])->where('joined_at', '<=', $at)->select('instance_id'));
        }

        return $query->select('id');
    }

    private function cachePermitted(ExecutionContext $context, array $d, int $projectionId): bool
    {
        $stored = $this->db->table(Tables::name('report_projection_items'))->where('projection_id', $projectionId);
        $current = $this->permittedIds($context, $d);

        return (clone $stored)->count() === (clone $current)->count() && ! (clone $stored)->whereNotIn('instance_id', $current)->exists();
    }

    private function calculate(ExecutionContext $context, array $d): array
    {
        $at = $d['as_of'] ?? LocalTime::stamp($this->clock->now());
        $instances = $this->instances->authorizedQuery($context)->where('started_at', '<=', $at);
        if ($d['project_key'] !== null) {
            $project = $this->projects->get($context, $d['project_key']);
            $instances->whereIn('task_id', $this->db->table(Tables::name('tasks'))->where('project_id', $project['id'])->select('id'));
        }if ($d['pipeline_id'] !== null) {
            $instances->where('pipeline_id', $d['pipeline_id']);
        }
        if ($d['kind'] === 'group') {
            $group = $this->groups->get($context, $d['group_key']);
            $instances->whereIn('id', $this->db->table(Tables::name('process_group_members'))->where('group_id', $group['id'])->where('joined_at', '<=', $at)->select('instance_id'));
        }
        $ids = (clone $instances)->select('id');
        $latest = $this->db->table(Tables::sql('tm_instance_transitions as historical'))->whereColumn('historical.instance_id', Tables::sql('tm_workflow_instances.id'))->where('historical.occurred_at', '<=', $at)->orderByDesc('historical.id')->limit(1)->selectRaw("COALESCE(historical.to_category, 'unknown')");
        $future = $this->db->table(Tables::sql('tm_instance_transitions as first_future'))->whereColumn('first_future.instance_id', Tables::sql('tm_workflow_instances.id'))->where('first_future.occurred_at', '>', $at)->orderBy('first_future.id')->limit(1)->selectRaw("COALESCE(first_future.from_category, 'unknown')");
        $initial = $this->db->table(Tables::sql('tm_workflow_states as initial_meta'))->whereColumn('initial_meta.workflow_revision_id', Tables::sql('tm_workflow_instances.workflow_revision_id'))->whereColumn('initial_meta.state_key', Tables::sql('tm_workflow_instances.current_state_key'))->select('category')->limit(1);
        $annotated = (clone $instances)->select(Tables::sql('tm_workflow_instances.id'))->selectSub($latest, 'past_category')->selectSub($future, 'future_category')->selectSub($initial, 'initial_category');
        $categories = $this->db->query()->fromSub($annotated, 'permitted_history')->selectRaw("COALESCE(past_category, future_category, initial_category, 'unknown') as category, COUNT(*) as total")->groupByRaw("COALESCE(past_category, future_category, initial_category, 'unknown')")->get()->mapWithKeys(fn ($row) => [$row->category => (int) $row->total])->all();
        $visits = $this->db->table(Tables::name('stage_visits'))->where('scope_key', $context->scope)->whereIn('instance_id', $ids)->where('entered_at', '<=', $at);
        $end = 'CASE WHEN exited_at IS NULL OR exited_at > ? THEN ? ELSE exited_at END';
        $span = $this->secondsExpression('entered_at', $end);
        $dwell = (int) ((clone $visits)->selectRaw("COALESCE(SUM($span),0) as seconds", [$at, $at])->value('seconds') ?? 0);
        $pauses = $this->db->table(Tables::name('sla_pauses'))->whereIn('visit_id', (clone $visits)->select('id'))->where('started_at', '<=', $at);
        $pauseEnd = 'CASE WHEN ended_at IS NULL OR ended_at > ? THEN ? ELSE ended_at END';
        $pauseSpan = $this->secondsExpression('started_at', $pauseEnd);
        $paused = (int) ($pauses->selectRaw("COALESCE(SUM($pauseSpan),0) as seconds", [$at, $at])->value('seconds') ?? 0);
        $workload = $this->db->table(Tables::name('instance_assignments'))->where('scope_key', $context->scope)->whereIn('instance_id', $ids)->where('active_from', '<=', $at)->where(fn ($q) => $q->whereNull('active_to')->orWhere('active_to', '>', $at))->groupBy('actor_ref')->selectRaw('actor_ref, COUNT(DISTINCT instance_id) as instances')->orderBy('actor_ref')->limit(1001)->get();
        if ($workload->count() > 1000) {
            throw new DomainError('workload_page_required');
        }
        $metrics = ['as_of' => $at, 'total_instances' => array_sum($categories), 'categories' => $categories, 'history_state' => isset($categories['unknown']) ? 'insufficient_data' : 'calculated', 'visit_count' => (clone $visits)->count(), 'elapsed_dwell_seconds' => max(0, $dwell), 'paused_seconds' => $paused, 'active_elapsed_dwell_seconds' => max(0, $dwell - $paused), 'breached_visits' => (clone $visits)->where('breached_at', '<=', $at)->count(), 'dead_candidate_visits' => (clone $visits)->where('dead_candidate_at', '<=', $at)->count(), 'workload' => $workload->map(fn ($row) => ['actor_ref' => $row->actor_ref, 'instances' => (int) $row->instances])->all(), 'visibility' => 'currently_permitted_sources'];
        if ($d['kind'] === 'group') {
            $runs = $this->db->table(Tables::name('group_operations'))->where('group_id', $group['id'])->where('created_at', '<=', $at)->select('id');
            $metrics['closure_outcomes'] = $this->db->table(Tables::name('group_operation_items'))->whereIn('run_id', $runs)->whereIn('instance_id', $ids)->where('processed_at', '<=', $at)->groupBy('result')->selectRaw('result,COUNT(*) as total')->get()->mapWithKeys(fn ($row) => [$row->result => (int) $row->total])->all();
        }

        return $metrics;
    }

    public function read(ExecutionContext $context, array $input = [], bool $truthFallback = true): array
    {
        $context->validateTenant();
        $this->authorize($context);
        $d = $this->definition($context, $input);
        $key = hash('sha256', json_encode($d, JSON_THROW_ON_ERROR));
        $row = $this->db->table(Tables::name('report_projections'))->where('scope_key', $context->scope)->where('actor_ref', $context->actor)->where('report_key', $key)->first();
        $stale = ! $row || $row->permission_hash !== $this->permission($context) || $row->source_hash !== $this->source($context, $d) || ! $this->cachePermitted($context, $d, $row->id);
        if ($stale) {
            return ['state' => $truthFallback ? 'from_truth' : 'stale', 'projection_stale' => true, 'metrics' => $truthFallback ? $this->calculate($context, $d) : null];
        }

        return ['state' => 'projected', 'projection_stale' => false, 'metrics' => json_decode($row->metrics, true, flags: JSON_THROW_ON_ERROR), 'version' => $row->version];
    }

    public function rebuild(ExecutionContext $context, array $input, string $operation): array
    {
        $context->validateTenant();
        $this->authorize($context);
        $d = $this->definition($context, $input);
        $key = hash('sha256', json_encode($d, JSON_THROW_ON_ERROR));

        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'report_rebuild', 'definition' => $d], function () use ($context, $d, $operation, $key) {
            ScopeGraphLock::acquire($this->db, $context);
            $before = $this->source($context, $d);
            $prior = $this->db->table(Tables::name('report_projections'))->where('scope_key', $context->scope)->where('actor_ref', $context->actor)->where('report_key', $key)->first();
            $version = ($prior->version ?? 0) + 1;
            $this->db->table(Tables::name('report_projections'))->updateOrInsert(['scope_key' => $context->scope, 'actor_ref' => $context->actor, 'report_key' => $key], ['definition' => json_encode($d, JSON_THROW_ON_ERROR), 'metrics' => '{}', 'source_hash' => $before, 'permission_hash' => $this->permission($context), 'captured_at' => LocalTime::stamp($this->clock->now()), 'version' => $version]);
            $projection = $this->db->table(Tables::name('report_projections'))->where('scope_key', $context->scope)->where('actor_ref', $context->actor)->where('report_key', $key)->first();
            $this->db->table(Tables::name('report_projection_items'))->where('projection_id', $projection->id)->delete();
            $ids = $this->permittedIds($context, $d)->select([])->selectRaw(Tables::sql('? as projection_id, tm_workflow_instances.id'), [$projection->id]);
            $this->db->table(Tables::name('report_projection_items'))->insertUsing(['projection_id', 'instance_id'], $ids);
            $metrics = $this->calculate($context, $d);
            if ($this->source($context, $d) !== $before || ! $this->cachePermitted($context, $d, $projection->id)) {
                throw new DomainError('report_source_changed');
            }(new EventRecorder($this->db, $this->clock))->append($context, 'report_projection', $key, 'report_rebuilt', $operation, []);
            $this->db->table(Tables::name('report_projections'))->where('id', $projection->id)->update(['metrics' => json_encode($metrics, JSON_THROW_ON_ERROR), 'source_hash' => $this->source($context, $d)]);

            return ['state' => 'projected', 'projection_stale' => false, 'metrics' => $metrics, 'version' => $version];
        }, function ($result) use ($context, $d, $key) {
            $this->definition($context, $d);
            $row = $this->db->table(Tables::name('report_projections'))->where('scope_key', $context->scope)->where('actor_ref', $context->actor)->where('report_key', $key)->first();
            if (! $row || $row->version !== $result['version'] || $row->permission_hash !== $this->permission($context) || ! $this->cachePermitted($context, $d, $row->id)) {
                throw new DomainError('report_permission_revoked');
            }
        });
    }
}
