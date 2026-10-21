<?php

namespace Karnoweb\TaskManager\SLA;

use Illuminate\Database\Connection;
use Karnoweb\TaskManager\CommandExecutor;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\AuthorizationProvider;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\EventRecorder;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Workflow\Definition;
use Karnoweb\TaskManager\Workflow\PipelineService;

final class StagePolicyService
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuthorizationProvider $authorization,
        private readonly CalendarRegistry $calendars, private readonly PipelineService $pipelines)
    {
    }

    public function publishSla(ExecutionContext $context, string $pipelineKey, string $stageKey, int $pipelineVersion, string $operation, array $policy): array
    {
        $context->require('sla.manage');
        $this->authorization->authorize($context, 'sla.manage');
        if (! is_int($policy['duration_seconds'] ?? null) || $policy['duration_seconds'] <= 0 || $policy['duration_seconds'] > 315360000) {
            throw new DomainError('sla_duration_invalid');
        }
        $policy += ['clock_kind' => 'elapsed', 'warning_before_seconds' => 0, 'stale_after_seconds' => null, 'reentry' => 'reset', 'dead_candidate' => false,
            'pause_reasons' => ['manual', 'handoff', 'approval'], 'max_pause_seconds' => 31536000];
        if (array_diff(array_keys($policy), ['duration_seconds', 'clock_kind', 'warning_before_seconds', 'stale_after_seconds', 'reentry', 'dead_candidate', 'pause_reasons', 'max_pause_seconds', 'calendar_ref', 'breach_transition', 'breach_reason'])) {
            throw new DomainError('sla_policy_invalid');
        }
        if (! in_array($policy['clock_kind'], ['elapsed', 'business'], true) || ! in_array($policy['reentry'], ['reset', 'cumulative'], true)
            || ! is_int($policy['warning_before_seconds']) || $policy['warning_before_seconds'] < 0 || $policy['warning_before_seconds'] >= $policy['duration_seconds']
            || ($policy['stale_after_seconds'] !== null && (! is_int($policy['stale_after_seconds']) || $policy['stale_after_seconds'] < 1))
            || ! is_bool($policy['dead_candidate']) || ! is_array($policy['pause_reasons']) || ! is_int($policy['max_pause_seconds']) || $policy['max_pause_seconds'] < 1) {
            throw new DomainError('sla_policy_invalid');
        }
        foreach ($policy['pause_reasons'] as $reason) {
            if (! is_string($reason) || ! preg_match('/^[a-z][a-z0-9_]{0,99}$/D', $reason)) {
                throw new DomainError('sla_policy_invalid');
            }
        }
        if ($policy['clock_kind'] === 'business') {
            $this->calendars->get($policy['calendar_ref'] ?? '');
        }
        if (isset($policy['breach_transition']) && (! is_string($policy['breach_transition']) || ! is_string($policy['breach_reason'] ?? null))) {
            throw new DomainError('sla_action_invalid');
        }
        if (isset($policy['breach_transition'])) {
            Definition::key($policy['breach_transition']);
            Definition::key($policy['breach_reason']);
        }

        return (new CommandExecutor($this->db))->execute($context, $operation, ['action' => 'publish_sla', 'pipeline' => $pipelineKey, 'stage' => $stageKey, 'version' => $pipelineVersion, 'policy' => $policy], function () use ($context, $pipelineKey, $stageKey, $pipelineVersion, $operation, $policy) {
            $pipeline = $this->pipelines->get($context, $pipelineKey);
            $row = $this->db->table('tm_pipelines')->where('id', $pipeline['id'])->lockForUpdate()->first();
            if ($row->version !== $pipelineVersion) {
                throw new DomainError('version_conflict');
            }
            $stage = $this->db->table('tm_pipeline_stages')->where('pipeline_id', $pipeline['id'])->where('stage_key', $stageKey)->lockForUpdate()->first();
            if (! $stage) {
                throw new DomainError('not_found');
            }
            $revision = ($this->db->table('tm_stage_policies')->where('stage_id', $stage->id)->max('revision') ?? 0) + 1;
            $id = $this->db->table('tm_stage_policies')->insertGetId(['scope_key' => $context->scope, 'stage_id' => $stage->id, 'revision' => $revision, 'sla' => json_encode($policy, JSON_THROW_ON_ERROR), 'dead_candidate' => $policy['dead_candidate']]);
            $this->db->table('tm_pipeline_stages')->where('id', $stage->id)->update(['policy_id' => $id]);
            $this->db->table('tm_pipelines')->where('id', $pipeline['id'])->increment('version');
            (new EventRecorder($this->db, $this->clock))->append($context, 'pipeline', $pipelineKey, 'sla_policy_published', $operation, ['stage' => $stageKey, 'policy_id' => $id, 'revision' => $revision]);

            return ['id' => $id, 'pipeline_key' => $pipelineKey, 'stage_key' => $stageKey, 'revision' => $revision];
        }, fn ($r) => $this->pipelines->get($context, $r['pipeline_key']));
    }
}
