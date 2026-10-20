<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\AssignmentTestCase;

final class TM024Test extends AssignmentTestCase
{
    public function test_scenario(): void
    {
        $c = $this->manager();
        $i=$this->assignableInstance(); $this->error('assignee_capacity_exceeded',fn()=>$this->assignments()->assign($c,$i['instance_key'],1,'too-many','b',['c'])); self::assertSame(0,DB::table('tm_assignment_slots')->count()); $state=$this->assignments()->configureInstance($c,$i['instance_key'],1,'policy',['mode'=>'manual','primary'=>'b','secondary'=>['c'],'max_assignees'=>2]); self::assertSame('b',$state['primary_assignee']); self::assertSame(['c'],$state['secondary']); self::assertSame('a',$state['owner_ref']); self::assertSame(2,DB::table('tm_assignment_slots')->count());
    }
}

