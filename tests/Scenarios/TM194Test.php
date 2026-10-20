<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\ApprovalTestCase;

final class TM194Test extends ApprovalTestCase
{
    public function test_scenario(): void
    {
        $c = $this->manager();
        [$i,$step]=$this->instanceWithApproval('sequential'); $this->error('approval_sequence_violation',fn()=>$this->approvals()->vote($this->manager('c'),$step['step_key'],1,2,'early','approve')); self::assertSame(0,DB::table('tm_approval_votes')->count()); $this->approvals()->vote($this->manager('b'),$step['step_key'],1,2,'b','approve'); $this->error('version_conflict',fn()=>$this->approvals()->vote($this->manager('c'),$step['step_key'],1,2,'stale','approve')); $middle=$this->approvals()->vote($this->manager('c'),$step['step_key'],2,2,'c','approve'); self::assertSame('pending',$middle['state']); $done=$this->approvals()->vote($this->manager('d'),$step['step_key'],3,2,'d','approve'); self::assertSame('approved',$done['state']); self::assertSame('executed',$done['execution_state']); self::assertCount(3,$done['votes']); self::assertSame('won',$this->instances()->get($c,$i['instance_key'])['current_state_key']);
    }
}

