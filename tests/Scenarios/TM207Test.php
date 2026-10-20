<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\WorkflowTestCase;

final class TM207Test extends WorkflowTestCase
{
    public function test_scenario(): void
    {
        $c = $this->manager();
        $r=$this->sales(); $this->host(); $i=$this->instances()->start($c,'crm.lead','lead-1',$r['id'],'start'); $wrong=new \Karnoweb\TaskManager\Context\ExecutionContext('two','a','job',true,$c->capabilities); self::assertSame(0,$this->instances()->count($wrong)); self::assertSame([],$this->instances()->query($wrong)); $this->error('not_found',fn()=>$this->instances()->transition($wrong,$i['instance_key'],1,'wrong','review')); self::assertSame('new',$this->instances()->get($c,$i['instance_key'])['current_state_key']);
    }
}

