<?php

namespace Karnoweb\TaskManager\SLA;

use Illuminate\Database\Connection;
use Karnoweb\TaskManager\CommandExecutor;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\AuthorizationProvider;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\EventRecorder;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Workflow\InstanceService;

final class SlaService
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization,
        private readonly SlaEngine $engine, private readonly InstanceService $instances)
    {
    }

    private function authorize(ExecutionContext $context, string $capability): void
    {
        $context->require($capability);
        $this->authorization->authorize($context, $capability);
    }

    public function get(ExecutionContext $context, int $visitId): array
    {
        $ids = $this->instances->authorizedQuery($context)->select('id');
        $visit = $this->db->table('tm_stage_visits')->where('scope_key', $context->scope)->where('id', $visitId)->whereIn('instance_id', $ids)->first();
        if (! $visit) {
            throw new DomainError('not_found');
        }
        $key = $this->db->table('tm_workflow_instances')->where('id', $visit->instance_id)->value('instance_key');

        return [...(array) $visit, 'instance_key' => $key];
    }

    private function locked(ExecutionContext $context, int $visitId): array
    {
        $visible = $this->get($context, $visitId);
        $current = $this->instances->get($context, $visible['instance_key']);
        [$instance] = $this->instances->lockForCommand($context, $visible['instance_key'], $current['version']);
        $visit = $this->db->table('tm_stage_visits')->where('scope_key', $context->scope)->where('id', $visitId)->lockForUpdate()->first();
        if ($visit->policy_id === null) {
            throw new DomainError('sla_unconfigured');
        }

        return [$visit, $instance, $this->engine->policy($visit)];
    }

    public function pause(ExecutionContext $context, int $visitId, int $slaVersion, string $operation, string $reason): array
    {
        $this->authorize($context, 'sla.pause');

        return $this->command($context, $operation, ['action' => 'sla_pause', 'visit' => $visitId, 'version' => $slaVersion, 'reason' => $reason], function () use ($context, $visitId, $slaVersion, $operation, $reason) {
            [$visit, $instance, $policy] = $this->locked($context, $visitId);
            if ($visit->sla_version !== $slaVersion) {
                throw new DomainError('version_conflict');
            }
            if ($visit->exited_at !== null || $instance['lifecycle'] !== 'active') {
                throw new DomainError('sla_inactive');
            }
            if ($visit->paused_at !== null) {
                throw new DomainError('sla_already_paused');
            }
            if (! in_array($reason, $policy['pause_reasons'], true)) {
                throw new DomainError('reason_invalid');
            }
            $remaining = max(0, $visit->budget_seconds - $this->engine->worked($visit, $policy, sinceAnchor: true));
            $now = $this->engine->format($this->engine->now());
            if ($remaining === 0 && $visit->breached_at === null) {
                throw new DomainError('sla_due_requires_detection');
            }
            $this->db->table('tm_sla_pauses')->insert(['visit_id' => $visitId, 'started_at' => $now, 'reason_code' => $reason, 'actor_ref' => $context->actor]);
            $this->db->table('tm_stage_visits')->where('id', $visitId)->update(['paused_at' => $now, 'remaining_seconds' => $remaining, 'sla_state' => 'paused', 'sla_version' => $slaVersion + 1]);
            $this->event($context, $instance, 'sla_paused', $operation, ['visit_id' => $visitId, 'reason_code' => $reason, 'remaining_seconds' => $remaining]);

            return $this->get($context, $visitId);
        });
    }

    public function resume(ExecutionContext $context, int $visitId, int $slaVersion, string $operation): array
    {
        $this->authorize($context, 'sla.pause');

        return $this->command($context, $operation, ['action' => 'sla_resume', 'visit' => $visitId, 'version' => $slaVersion], function () use ($context, $visitId, $slaVersion, $operation) {
            [$visit, $instance, $policy] = $this->locked($context, $visitId);
            if ($visit->sla_version !== $slaVersion) {
                throw new DomainError('version_conflict');
            }
            if ($visit->exited_at !== null || $instance['lifecycle'] !== 'active') {
                throw new DomainError('sla_inactive');
            }
            if ($visit->paused_at === null) {
                throw new DomainError('sla_not_paused');
            }
            $now = $this->engine->now();
            $paused = $now->getTimestamp() - $this->engine->date($visit->paused_at)->getTimestamp();
            if ($paused < 0) {
                throw new DomainError('clock_moved_backwards');
            }
            if ($visit->paused_seconds + $paused > $policy['max_pause_seconds']) {
                throw new DomainError('sla_pause_limit_exceeded');
            }
            $this->db->table('tm_sla_pauses')->where('visit_id', $visitId)->whereNull('ended_at')->update(['ended_at' => $this->engine->format($now)]);
            $changes = ['paused_at' => null, 'paused_seconds' => $visit->paused_seconds + $paused, 'sla_version' => $slaVersion + 1,
                'deadline_at' => $this->engine->format($this->engine->add($now, $visit->remaining_seconds, $policy)),
                'warning_at' => $policy['warning_before_seconds'] > 0 ? $this->engine->format($this->engine->add($now, max(0, $visit->remaining_seconds - $policy['warning_before_seconds']), $policy)) : null,
                'stale_at' => $visit->stale_at === null ? null : $this->engine->format($this->engine->date($visit->stale_at)->modify('+'.$paused.' seconds')),
                'sla_state' => $visit->dead_candidate_at !== null ? 'dead_candidate' : ($visit->stale_detected_at !== null ? 'stale' : ($visit->breached_at !== null ? 'breached' : ($visit->warned_at !== null ? 'warning' : 'healthy')))];
            $this->db->table('tm_stage_visits')->where('id', $visitId)->update($changes);
            $this->event($context, $instance, 'sla_resumed', $operation, ['visit_id' => $visitId, 'paused_seconds' => $paused]);

            return $this->get($context, $visitId);
        });
    }

    public function reset(ExecutionContext $context, int $visitId, int $slaVersion, string $operation): array
    {
        $this->authorize($context, 'sla.manage');

        return $this->command($context, $operation, ['action' => 'sla_reset', 'visit' => $visitId, 'version' => $slaVersion], function () use ($context, $visitId, $slaVersion, $operation) {
            [$visit, $instance, $policy] = $this->locked($context, $visitId);
            if ($visit->sla_version !== $slaVersion) {
                throw new DomainError('version_conflict');
            }
            if ($visit->exited_at !== null || $instance['lifecycle'] !== 'active' || $visit->paused_at !== null) {
                throw new DomainError('sla_reset_requires_active');
            }
            $now = $this->engine->now();
            $duration = $policy['duration_seconds'];
            $this->db->table('tm_stage_visits')->where('id', $visitId)->update(['anchor_at' => $this->engine->format($now), 'last_activity_at' => $this->engine->format($now),
                'budget_seconds' => $duration, 'remaining_seconds' => $duration, 'deadline_at' => $this->engine->format($this->engine->add($now, $duration, $policy)),
                'warning_at' => $policy['warning_before_seconds'] > 0 ? $this->engine->format($this->engine->add($now, $duration - $policy['warning_before_seconds'], $policy)) : null,
                'stale_at' => $policy['stale_after_seconds'] !== null ? $this->engine->format($now->modify('+'.$policy['stale_after_seconds'].' seconds')) : null,
                'warned_at' => null, 'breached_at' => null, 'stale_detected_at' => null, 'dead_candidate_at' => null, 'sla_state' => 'healthy',
                'sla_version' => $slaVersion + 1, 'sla_generation' => $visit->sla_generation + 1, 'stale_generation' => $visit->stale_generation + 1]);
            $this->event($context, $instance, 'sla_reset', $operation, ['visit_id' => $visitId, 'generation' => $visit->sla_generation + 1]);

            return $this->get($context, $visitId);
        });
    }

    public function markDeadCandidate(ExecutionContext $context, string $instanceKey, int $visitId): array
    {
        $this->authorize($context, 'sla.run');
        $instance = $this->instances->get($context, $instanceKey);
        if (! $this->db->table('tm_stage_visits')->where('id', $visitId)->where('instance_id', $instance['id'])->where('scope_key', $context->scope)->exists()) {
            throw new DomainError('not_found');
        }

        return ['processed' => $this->apply($context, $visitId, 'dead_candidate')];
    }

    public function run(ExecutionContext $context, int $limit = 100): array
    {
        $this->authorize($context, 'sla.run');
        if ($limit < 1 || $limit > 1000) {
            throw new DomainError('batch_size_invalid');
        }
        $now = $this->engine->format($this->engine->now());
        $visits = $this->db->table('tm_stage_visits')->where('scope_key', $context->scope)->whereNotNull('policy_id')->whereNull('exited_at')->whereNull('paused_at')
            ->whereIn('instance_id', $this->instances->authorizedQuery($context)->where(fn ($q) => $q->whereNull('task_id')->orWhereIn('task_id', $this->db->table('tm_tasks')->whereNull('archived_at')->whereNull('deleted_at')->select('id')))->select('id'))->where(function ($q) use ($now) {
                $q->where(fn ($x) => $x->where('deadline_at', '<=', $now)->whereNull('breached_at'))
                    ->orWhere(fn ($x) => $x->where('warning_at', '<=', $now)->whereNull('warned_at'))
                    ->orWhere(fn ($x) => $x->where('stale_at', '<=', $now)->whereNull('stale_detected_at'))
                    ->orWhere(fn ($x) => $x->whereNotNull('breached_at')->whereNotNull('stale_detected_at')->whereNull('dead_candidate_at')
                        ->whereIn('policy_id', $this->db->table('tm_stage_policies')->where('dead_candidate', true)->select('id')));
            })->orderBy('id')->limit($limit)->get();
        $processed = 0;
        $skipped = 0;
        foreach ($visits as $visit) {
            foreach (['warning', 'breach', 'stale', 'dead_candidate'] as $key) {
                try {
                    if ($this->apply($context, $visit->id, $key)) {
                        $processed++;
                    }
                } catch (DomainError $e) {
                    if (in_array($e->getMessage(), ['not_found', 'version_conflict', 'sla_inactive'], true)) {
                        $skipped++;
                        break;
                    } throw $e;
                }
            }
        }

        return ['events' => $processed, 'skipped' => $skipped];
    }

    private function threshold(object $visit, array $policy, string $key): ?string
    {
        if ($visit->paused_at !== null || $visit->exited_at !== null) {
            return null;
        }
        $now = $this->engine->format($this->engine->now());
        if ($key === 'dead_candidate') {
            return $policy['dead_candidate'] && $visit->dead_candidate_at === null && $visit->breached_at !== null && $visit->stale_detected_at !== null
                ? max($visit->deadline_at, $visit->stale_at) : null;
        }
        [$at, $flag] = match ($key) {
            'warning' => ['warning_at', 'warned_at'], 'breach' => ['deadline_at', 'breached_at'], 'stale' => ['stale_at', 'stale_detected_at']
        };

        return $visit->{$at} !== null && $visit->{$at} <= $now && $visit->{$flag} === null ? $visit->{$at} : null;
    }

    private function apply(ExecutionContext $context, int $visitId, string $key): bool
    {
        $initial = (object) $this->get($context, $visitId);
        $policy = $this->engine->policy($initial);
        if ($this->threshold($initial, $policy, $key) === null) {
            return false;
        }
        $generation = in_array($key, ['stale', 'dead_candidate'], true) ? $initial->stale_generation : $initial->sla_generation;
        $operation = 'sla:'.$visitId.':'.$initial->policy_id.':'.$generation.':'.$key.':'.substr(hash('sha256', $context->actor), 0, 12);
        $executed = false;
        $result = $this->command($context, $operation, ['action' => 'sla_'.$key, 'visit' => $visitId, 'policy' => $initial->policy_id, 'generation' => $generation], function () use ($context, $visitId, $initial, $generation, $key, $operation, &$executed) {
            $executed = true;
            [$visit, $instance, $policy] = $this->locked($context, $visitId);
            if ($instance['task_id'] !== null && ! $this->db->table('tm_tasks')->where('id', $instance['task_id'])->whereNull('archived_at')->whereNull('deleted_at')->exists()) {
                throw new DomainError('sla_inactive');
            }
            $currentGeneration = in_array($key, ['stale', 'dead_candidate'], true) ? $visit->stale_generation : $visit->sla_generation;
            $threshold = $this->threshold($visit, $policy, $key);
            if ($instance['lifecycle'] !== 'active' || $threshold === null || $generation !== $currentGeneration || $visit->policy_id !== $initial->policy_id) {
                return ['id' => $visitId, 'processed' => false];
            }
            $now = $this->engine->format($this->engine->now());
            $inserted = $this->db->table('tm_sla_events')->insertOrIgnore(['scope_key' => $context->scope, 'visit_id' => $visitId, 'policy_id' => $visit->policy_id, 'generation' => $generation,
                'event_key' => $key, 'threshold_at' => $threshold, 'detected_at' => $now]);
            if (! $inserted) {
                return ['id' => $visitId, 'processed' => false];
            }
            $flag = match ($key) {
                'warning' => 'warned_at', 'breach' => 'breached_at', 'stale' => 'stale_detected_at', 'dead_candidate' => 'dead_candidate_at'
            };
            $flags = [...(array) $visit, $flag => $now];
            $state = $flags['dead_candidate_at'] !== null ? 'dead_candidate' : ($flags['stale_detected_at'] !== null ? 'stale' : ($flags['breached_at'] !== null ? 'breached' : 'warning'));
            $this->db->table('tm_stage_visits')->where('id', $visitId)->update([$flag => $now, 'sla_state' => $state, 'sla_version' => $visit->sla_version + 1]);
            $action = 'marked';
            if ($key === 'breach' && isset($policy['breach_transition'])) {
                try {
                    $this->instances->transition($context, $instance['instance_key'], $instance['version'], 'action:'.$operation, $policy['breach_transition'], ['reason_code' => $policy['breach_reason']]);
                    $action = 'transitioned';
                } catch (DomainError $e) {
                    $action = 'manual_review';
                    $this->db->table('tm_sla_events')->where('visit_id', $visitId)->where('policy_id', $visit->policy_id)->where('generation', $generation)->where('event_key', $key)->update(['reason' => 'transition_denied']);
                }
            }
            $this->db->table('tm_sla_events')->where('visit_id', $visitId)->where('policy_id', $visit->policy_id)->where('generation', $generation)->where('event_key', $key)->update(['action_state' => $action]);
            $this->event($context, $instance, 'sla_'.$key, $operation, ['visit_id' => $visitId, 'policy_id' => $visit->policy_id, 'generation' => $generation, 'threshold_at' => $threshold, 'action_state' => $action, 'notification_requested' => true]);

            return ['id' => $visitId, 'processed' => true];
        });

        return $executed && $result['processed'];
    }

    private function command(ExecutionContext $context, string $operation, array $payload, callable $execute): array
    {
        return (new CommandExecutor($this->db))->execute($context, $operation, $payload, $execute, fn ($r) => $this->get($context, $r['id']));
    }

    private function event(ExecutionContext $context, array $instance, string $action, string $operation, array $payload): void
    {
        (new EventRecorder($this->db, $this->clock))->append($context, 'instance', $instance['instance_key'], $action, $operation, $payload, $instance['task_id']);
    }
}
