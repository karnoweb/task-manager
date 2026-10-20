<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\WorkflowTestCase;

final class TM152Test extends WorkflowTestCase
{
    public function test_scenario(): void
    {
        $c = $this->manager();
        $r=$this->sales(); $this->host(); $i=$this->instances()->start($c,'crm.lead','lead-1',$r['id'],'start'); self::assertSame('crm.lead',$i['subject_type']); self::assertSame('lead-1',$i['subject_id']); self::assertNull($i['task_id']); self::assertSame(0,DB::table('tm_tasks')->count()); self::assertSame('new',$i['current_state_key']); self::assertNull(DB::table('host_subjects')->value('legacy_status'));
    }
}

