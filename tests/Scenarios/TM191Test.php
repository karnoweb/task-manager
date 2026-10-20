<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\AssignmentTestCase;

final class TM191Test extends AssignmentTestCase
{
    public function test_scenario(): void
    {
        $c = $this->manager();
        [$i,$v,$p]=$this->instanceWithSla(['duration_seconds'=>7200]); $this->assignments()->configureStage($c,$p['pipeline_key'],'review',2,'assignment-policy',['mode'=>'team','reference'=>'contracts']); DB::table('host_people')->where('team','contracts')->update(['active'=>false]); $events=DB::table('tm_outbox')->count(); $this->error('assignment_unresolvable',fn()=>$this->instances()->transition($c,$i['instance_key'],1,'review','review')); $current=$this->instances()->get($c,$i['instance_key']); self::assertSame('new',$current['current_state_key']); self::assertSame('a',$current['primary_assignee']); self::assertSame($events,DB::table('tm_outbox')->count()); self::assertSame(1,DB::table('tm_stage_visits')->count()); self::assertSame('assignment_unresolvable',DB::table('tm_security_decisions')->value('reason'));
    }
}

