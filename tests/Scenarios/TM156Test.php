<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\WorkflowTestCase;

final class TM156Test extends WorkflowTestCase
{
    public function test_scenario(): void
    {
        $c = $this->manager();
        $r=$this->sales(); $this->host('opportunity','crm.opportunity'); $a=$this->instances()->start($c,'crm.opportunity','opportunity',$r['id'],'legal',slot:'legal_review'); $b=$this->instances()->start($c,'crm.opportunity','opportunity',$r['id'],'risk',slot:'risk_review'); $this->instances()->transition($c,$a['instance_key'],1,'review','review'); self::assertSame('new',$this->instances()->get($c,$b['instance_key'])['current_state_key']); self::assertSame(1,$this->instances()->get($c,$b['instance_key'])['version']); self::assertSame(2,DB::table('tm_active_process_slots')->count());
    }
}

