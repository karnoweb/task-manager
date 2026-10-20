<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\ApprovalTestCase;

final class TM195Test extends ApprovalTestCase
{
    public function test_scenario(): void
    {
        $c = $this->manager();
        [$i,$step]=$this->instanceWithApproval(); DB::table('host_people')->where('actor','b')->update(['can_approve'=>false]); $this->error('approval_vote_denied',fn()=>$this->approvals()->vote($this->manager('b'),$step['step_key'],1,2,'revoked','approve')); self::assertSame(0,DB::table('tm_approval_votes')->count()); self::assertSame('pending',$this->approvals()->get($c,$step['step_key'])['state']); self::assertSame('approval_vote_denied',DB::table('tm_security_decisions')->value('reason')); $cancelled=$this->approvals()->cancel($c,$step['step_key'],1,2,'cancel','approver_unavailable'); self::assertSame('cancelled',$cancelled['state']); $replacement=$this->approvals()->begin($c,$i['instance_key'],3,'replacement',['approvers'=>['c','d'],'approved_transition'=>'approve','approved_reason'=>'approved','rejected_transition'=>'return']); self::assertNotSame($step['step_key'],$replacement['step_key']); self::assertSame(['b','c','d'],$this->approvals()->get($c,$step['step_key'])['policy']['approvers']); self::assertSame(['c','d'],$replacement['policy']['approvers']); self::assertSame('review',$this->instances()->get($c,$i['instance_key'])['current_state_key']); self::assertSame(1,DB::table('tm_active_approval_steps')->count());
    }
}

