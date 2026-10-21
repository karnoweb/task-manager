<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\WorkflowTestCase;

final class TM158Test extends WorkflowTestCase
{
    public function test_scenario(): void
    {
        $c = $this->manager();
        $r = $this->sales();
        $this->host();
        $a = $this->instances()->start($c, 'crm.lead', 'lead-1', $r['id'], 'start');
        $a = $this->instances()->transition($c, $a['instance_key'], 1, 'review', 'review');
        $closed = $this->instances()->transition($c, $a['instance_key'], 2, 'approve', 'approve', ['reason_code' => 'approved']);
        self::assertSame(0, DB::table('tm_active_process_slots')->count());
        $new = $this->instances()->start($c, 'crm.lead', 'lead-1', $r['id'], 'new');
        self::assertNotSame($closed['instance_key'], $new['instance_key']);
        self::assertSame('closed', $this->instances()->get($c, $closed['instance_key'])['lifecycle']);
        self::assertSame(2,DB::table('tm_workflow_instances')->count());
    }
}
