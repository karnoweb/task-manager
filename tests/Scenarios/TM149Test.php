<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\WorkflowTestCase;

final class TM149Test extends WorkflowTestCase
{
    public function test_scenario(): void
    {
        $c = $this->manager();
        $r=$this->sales(); $this->host('ticket','support.ticket'); $this->host('opportunity','crm.opportunity',actors:['a','sales']); $case=$this->cases()->create($c,'Case','case'); $this->instances()->start($c,'support.ticket','ticket',$r['id'],'ticket',caseKey:$case['case_key']); $this->instances()->start($c,'crm.opportunity','opportunity',$r['id'],'opportunity',caseKey:$case['case_key']); self::assertCount(2,$this->cases()->timeline($c,$case['case_key'])); $events=$this->cases()->timeline($this->manager('sales'),$case['case_key']); self::assertCount(1,$events); self::assertStringNotContainsString('PRIVATE-HOST-TEXT',json_encode($events)); self::assertSame([],$this->cases()->timeline($this->manager('guest'),$case['case_key']));
    }
}

