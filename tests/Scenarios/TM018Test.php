<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\WorkflowTestCase;

final class TM018Test extends WorkflowTestCase
{
    public function test_scenario(): void
    {
        $c = $this->manager();
        $r = $this->sales();
        $this->host();
        $i = $this->instances()->start($c, 'crm.lead', 'lead-1', $r['id'], 'start');
        $events = DB::table('tm_outbox')->count();
        $commands = DB::table('tm_command_journal')->count();
        self::assertSame($i, $this->instances()->transition($c, $i['instance_key'], 1, 'cancel', 'review', confirmed: false));
        self::assertSame($commands, DB::table('tm_command_journal')->count());
        self::assertSame($events, DB::table('tm_outbox')->count());
        self::assertSame(0, DB::table('tm_instance_transitions')->count());
    }
}
