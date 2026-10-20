<?php

namespace Karnoweb\TaskManager\Tests;

use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Handoff\HandoffService;

abstract class HandoffTestCase extends AssignmentTestCase
{
    protected function manager(string $actor = 'a', string $scope = 'one'): ExecutionContext
    {
        $base = parent::manager($actor, $scope);
        return new ExecutionContext($scope, $actor, 'handoff-test', capabilities: [...$base->capabilities, 'handoff.manage', 'handoff.view', 'handoff.recall', 'handoff.timeout', 'handoff.run', 'handoff.repair']);
    }
    protected function handoffs(): HandoffService { return $this->app->make(HandoffService::class); }
    protected function handoffFixture(bool $sla = true): array
    {
        $definition = $this->definition();
        $definition['states'] += ['lost' => ['terminal' => 'failure'], 'cancelled' => ['terminal' => 'cancelled']];
        $definition['transitions'] += [
            'reject' => ['from' => 'new', 'to' => 'lost', 'reason_codes' => ['invalid']],
            'cancel' => ['from' => 'new', 'to' => 'cancelled', 'reason_codes' => ['recalled'], 'allow_while_waiting' => true],
            'accept' => ['from' => 'new', 'to' => 'won'],
            'correct' => ['from' => 'new', 'to' => 'new'],
            'progress' => ['from' => 'new', 'to' => 'new', 'allow_while_waiting' => true],
        ];
        $r = $this->workflows()->publish($this->manager(), 'sales', 0, 'definition', $definition);
        $stages = ['inbox' => ['state' => 'new'], 'review' => ['state' => 'review'], 'won' => ['state' => 'won'], 'lost' => ['state' => 'lost'], 'cancelled' => ['state' => 'cancelled']];
        $p = $this->pipelines()->create($this->manager(), 'Sales', $r['id'], $stages, 'sales-pipeline');
        $d = $this->pipelines()->create($this->manager(), 'Contracts', $r['id'], $stages, 'contracts-pipeline');
        if ($sla) { $this->policy()->publishSla($this->manager(), $p['pipeline_key'], 'inbox', 1, 'sla-policy', ['duration_seconds' => 3600, 'pause_reasons' => ['handoff'], 'max_pause_seconds' => 86400]); }
        $this->host(); $origin = $this->instances()->start($this->manager(), 'crm.lead', 'lead-1', $r['id'], 'origin', pipelineKey: $p['pipeline_key']);
        return [$origin, $d, $r, $p];
    }
    protected function returnPolicy(): array
    {
        return ['reason_code' => 'contract_review', 'target_team' => 'contracts', 'returns' => ['approved' => ['transition' => 'review'], 'rejected' => ['transition' => 'note'], 'cancelled' => ['transition' => 'review'], 'recalled' => ['transition' => 'review'], 'timed_out' => ['transition' => 'review']],
            'recall_transition' => 'cancel', 'timeout_transition' => 'cancel', 'cancel_reason' => 'recalled', 'timeout_seconds' => 120];
    }
    protected function delegateFixture(bool $sla = true, ?array $policy = null): array
    {
        [$origin, $destination, $r, $pipeline] = $this->handoffFixture($sla);
        $h = $this->handoffs()->delegate($this->manager(), $origin['instance_key'], 1, $destination['pipeline_key'], $r['id'], 'contracts', $policy ?? $this->returnPolicy(), 'delegate');
        $key = \Illuminate\Support\Facades\DB::table('tm_workflow_instances')->where('id', $h['destination_id'])->value('instance_key');
        return [$h, $origin, $this->instances()->get($this->manager(), $key), $pipeline];
    }
}
