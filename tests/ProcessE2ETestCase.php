<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Approval\ApprovalService;
use Karnoweb\TaskManager\Context\ExecutionContext;

abstract class ProcessE2ETestCase extends HandoffTestCase
{
    protected function manager(string $actor = 'a', string $scope = 'one'): ExecutionContext
    {
        $base = parent::manager($actor, $scope);
        return new ExecutionContext($scope, $actor, 'process-e2e', capabilities: [...$base->capabilities, 'approval.manage', 'approval.vote', 'approval.execute']);
    }
    protected function approvals(): ApprovalService { return $this->app->make(ApprovalService::class); }
    protected function contractFlow(): array
    {
        $sourceDefinition = ['initial' => 'negotiation', 'states' => ['negotiation' => [], 'sale_next_stage' => [], 'rework' => [], 'follow_up' => []], 'transitions' => [
            'complete_contract' => ['from' => 'negotiation', 'to' => 'sale_next_stage', 'reason_required' => true, 'reason_codes' => ['contract_approved']],
            'rework_contract' => ['from' => 'negotiation', 'to' => 'rework', 'reason_required' => true, 'reason_codes' => ['contract_rejected']],
            'follow_up_contract' => ['from' => 'negotiation', 'to' => 'follow_up', 'reason_required' => true, 'reason_codes' => ['contract_cancelled']],
        ]];
        $contractDefinition = ['initial' => 'review', 'states' => ['review' => [], 'approved' => ['terminal' => 'success'], 'rejected' => ['terminal' => 'failure'], 'cancelled' => ['terminal' => 'cancelled']], 'transitions' => [
            'approve' => ['from' => 'review', 'to' => 'approved', 'reason_required' => true, 'reason_codes' => ['contract_approved']],
            'reject' => ['from' => 'review', 'to' => 'rejected', 'reason_codes' => ['contract_rejected']],
            'cancel' => ['from' => 'review', 'to' => 'cancelled', 'reason_codes' => ['contract_cancelled']],
        ]];
        $s = $this->workflows()->publish($this->manager(), 'sales-contract', 0, 'sales-definition', $sourceDefinition);
        $c = $this->workflows()->publish($this->manager(), 'contract-approval', 0, 'contract-definition', $contractDefinition);
        $sourceStages = []; foreach (array_keys($sourceDefinition['states']) as $state) { $sourceStages[$state] = ['state' => $state]; }
        $contractStages = []; foreach (array_keys($contractDefinition['states']) as $state) { $contractStages[$state] = ['state' => $state]; }
        $sales = $this->pipelines()->create($this->manager(), 'Sales', $s['id'], $sourceStages, 'sales-pipeline');
        $contracts = $this->pipelines()->create($this->manager(), 'Contracts', $c['id'], $contractStages, 'contracts-pipeline');
        $this->policy()->publishSla($this->manager(), $sales['pipeline_key'], 'negotiation', 1, 'sales-sla', ['duration_seconds' => 3600, 'pause_reasons' => ['handoff'], 'max_pause_seconds' => 86400]);
        $this->policy()->publishSla($this->manager(), $contracts['pipeline_key'], 'review', 1, 'contract-sla', ['duration_seconds' => 300]);
        $this->assignments()->configureStage($this->manager(), $contracts['pipeline_key'], 'review', 2, 'contract-assignee', ['primary' => 'c']);
        $this->approvals()->configureStage($this->manager(), $contracts['pipeline_key'], 'review', 3, 'contract-approvals', ['mode' => 'quorum', 'approvers' => ['b', 'c', 'd'], 'quorum' => 2,
            'approved_transition' => 'approve', 'approved_reason' => 'contract_approved', 'rejected_transition' => 'reject', 'reject_reasons' => ['contract_rejected']]);
        $this->host('opportunity-1', 'crm.opportunity', actors: ['a', 'b', 'c', 'd']);
        $origin = $this->instances()->start($this->manager(), 'crm.opportunity', 'opportunity-1', $s['id'], 'sales-start', pipelineKey: $sales['pipeline_key']);
        $this->assignments()->assign($this->manager(), $origin['instance_key'], 1, 'sales-assignee', 'b'); $this->clock->advance('+10 seconds');
        $handoff = $this->handoffs()->delegate($this->manager(), $origin['instance_key'], 2, $contracts['pipeline_key'], $c['id'], 'contracts', ['reason_code' => 'contract_review', 'target_team' => 'contracts',
            'returns' => ['approved' => ['transition' => 'complete_contract', 'reason_code' => 'contract_approved'], 'rejected' => ['transition' => 'rework_contract', 'reason_code' => 'contract_rejected'],
                'cancelled' => ['transition' => 'follow_up_contract', 'reason_code' => 'contract_cancelled']]], 'delegate');
        $destinationKey = DB::table('tm_workflow_instances')->where('id', $handoff['destination_id'])->value('instance_key');
        return [$origin, $handoff, $this->instances()->get($this->manager(), $destinationKey), $this->approvals()->active($this->manager(), $destinationKey)];
    }
}
