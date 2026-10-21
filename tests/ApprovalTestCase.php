<?php

namespace Karnoweb\TaskManager\Tests;

use Karnoweb\TaskManager\Approval\ApprovalService;
use Karnoweb\TaskManager\Context\ExecutionContext;

abstract class ApprovalTestCase extends AssignmentTestCase
{
    protected function manager(string $actor = 'a', string $scope = 'one'): ExecutionContext
    {
        $base = parent::manager($actor, $scope);

        return new ExecutionContext($scope, $actor, 'approval-test', capabilities: [...$base->capabilities, 'approval.manage', 'approval.vote', 'approval.execute']);
    }

    protected function approvals(): ApprovalService
    {
        return $this->app->make(ApprovalService::class);
    }

    protected function approvalPolicy(string $mode = 'quorum'): array
    {
        return ['mode' => $mode, 'approvers' => ['b', 'c', 'd'], 'quorum' => 2, 'approved_transition' => 'approve', 'approved_reason' => 'approved', 'rejected_transition' => 'return'];
    }

    protected function instanceWithApproval(string $mode = 'quorum'): array
    {
        $r = $this->sales();
        $p = $this->pipeline($r['id']);
        $this->approvals()->configureStage($this->manager(), $p['pipeline_key'], 'review', 1, 'approval-policy', $this->approvalPolicy($mode));
        $this->host('lead-1', actors: ['a', 'b', 'c', 'd']);
        $i = $this->instances()->start($this->manager(), 'crm.lead', 'lead-1', $r['id'], 'start', pipelineKey: $p['pipeline_key']);
        $i = $this->instances()->transition($this->manager(), $i['instance_key'], 1, 'review', 'review');

        return [$i, $this->approvals()->active($this->manager(), $i['instance_key']), $p];
    }
}
