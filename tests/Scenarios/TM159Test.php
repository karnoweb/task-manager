<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\WorkflowTestCase;

final class TM159Test extends WorkflowTestCase
{
    public function test_scenario(): void
    {
        $c = $this->manager();
        $r = $this->sales();
        $this->host();
        $i = $this->instances()->start($c, 'crm.lead', 'lead-1', $r['id'], 'start');
        DB::table('host_subjects')->update(['legacy_status' => 'legacy-won']);
        self::assertSame('conflict', $this->instances()->reconcile($c, $i['instance_key'])['status']);
        $this->error('host_state_conflict', fn () => $this->instances()->transition($c, $i['instance_key'], 1, 'review', 'review'));
        self::assertSame('new', $this->instances()->get($c, $i['instance_key'])['current_state_key']);
        self::assertSame(0, DB::table('tm_instance_transitions')->count());
    }
}
