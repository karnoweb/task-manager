<?php

namespace Karnoweb\TaskManager\Tests\Scenarios\Complete;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\ApprovalTestCase;

final class Scenario08AssignmentApprovalTest extends ApprovalTestCase
{
    protected function instanceWithApproval(string $mode = 'quorum'): array
    {
        $r = $this->sales();
        $p = $this->pipeline($r['id']);
        $this->approvals()->configureStage($this->manager(), $p['pipeline_key'], 'review', 1, 'approval-policy', $this->approvalPolicy($mode));
        $p = $this->pipelines()->get($this->manager(), $p['pipeline_key']);
        $this->assignments()->configureStage($this->manager(), $p['pipeline_key'], 'review', $p['version'], 'assignment-policy', ['mode' => 'manual', 'primary' => 'a', 'secondary' => ['b'], 'max_assignees' => 2]);
        $this->host('lead-1', actors: ['a', 'b', 'c', 'd']);
        $i = $this->instances()->start($this->manager(), 'crm.lead', 'lead-1', $r['id'], 'start', pipelineKey: $p['pipeline_key']);
        $i = $this->instances()->transition($this->manager(), $i['instance_key'], 1, 'review', 'review');
        self::assertSame('a', $i['owner_ref']);
        self::assertSame('a', $i['primary_assignee']);
        self::assertCount(2, DB::table('tm_assignment_slots')->where('instance_id', $i['id'])->get());

        return [$i, $this->approvals()->active($this->manager(), $i['instance_key'])];
    }

    public function test_complete_scenario_08(): void
    {

        [$i, $step] = $this->instanceWithApproval();
        $first = $this->approvals()->vote($this->manager('b'), $step['step_key'], 1, 2, 'vote-b', 'approve');
        self::assertSame('pending', $first['state']);
        self::assertCount(1, $first['votes']);
        self::assertSame($first, $this->approvals()->vote($this->manager('b'), $step['step_key'], 1, 2, 'vote-b', 'approve'));
        $duplicate = $this->approvals()->vote($this->manager('b'), $step['step_key'], 2, 2, 'vote-b-again', 'approve');
        self::assertCount(1, $duplicate['votes']);
        $resolved = $this->approvals()->vote($this->manager('c'), $step['step_key'], 2, 2, 'vote-c', 'approve');
        self::assertSame('approved', $resolved['state']);
        self::assertSame('executed', $resolved['execution_state']);
        self::assertSame('won', $this->instances()->get($this->manager(), $i['instance_key'])['current_state_key']);
        self::assertSame(2, DB::table('tm_approval_votes')->count());
        self::assertSame(1, DB::table('tm_outbox')->where('action', 'approval_decided')->count());
        $this->error('approval_already_resolved', fn () => $this->approvals()->vote($this->manager('d'), $step['step_key'], 3, 3, 'late', 'approve'));
        self::assertSame(2, DB::table('tm_approval_votes')->count());
        self::assertSame(0, DB::table('tm_active_approval_steps')->count());

    }
}
