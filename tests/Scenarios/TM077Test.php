<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\WorkflowTestCase;

final class TM077Test extends WorkflowTestCase
{
    public function test_scenario(): void
    {
        $c = $this->manager();
        $r=$this->sales(); $this->host(); $i=$this->instances()->start($c,'crm.lead','lead-1',$r['id'],'start'); $events=DB::table('tm_outbox')->count(); DB::table('host_subjects')->update(['veto'=>true]); $this->error('host_guard_veto',fn()=>$this->instances()->transition($c,$i['instance_key'],1,'veto','review')); self::assertSame(0,DB::table('host_subjects')->value('guard_touches')); self::assertSame($events,DB::table('tm_outbox')->count()); self::assertSame(0,DB::table('tm_instance_transitions')->count()); self::assertSame(0,DB::table('tm_command_journal')->where('operation_id','veto')->count());
    }
}

