<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\WorkflowTestCase;

final class TM157Test extends WorkflowTestCase
{
    public function test_scenario(): void
    {
        $c = $this->manager();
        if(DB::connection()->getDriverName()!=='pgsql'){$this->markTestSkipped('Requires real PostgreSQL process concurrency.');} $r=$this->sales(); $this->host(); $results=\Karnoweb\TaskManager\Tests\Support\ProcessBarrier::run([['start','lead-1',$r['id'],'a'],['start','lead-1',$r['id'],'b']]); self::assertCount(1,array_filter($results,fn($r)=>isset($r['key']))); self::assertCount(1,array_filter($results,fn($r)=>($r['error']??'')==='active_slot_conflict')); self::assertSame(1,DB::table('tm_workflow_instances')->count()); self::assertSame(1,DB::table('tm_active_process_slots')->count()); self::assertSame(2,DB::table('tm_command_journal')->count());
    }
}

