<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\WorkflowTestCase;

final class TM151Test extends WorkflowTestCase
{
    public function test_scenario(): void
    {
        $c = $this->manager();
        $r = $this->sales();
        $this->host();
        $i = $this->instances()->start($c, 'crm.lead', 'lead-1', $r['id'], 'start');
        $events = DB::table('tm_outbox')->count();
        $this->error('transition_invalid', fn () => $this->instances()->transition($c, $i['instance_key'], 1, 'invalid', 'approve'));
        $this->error('not_found', fn () => $this->instances()->transition($this->manager('guest'), $i['instance_key'], 1, 'unauthorized', 'review'));
        self::assertSame(0, DB::table('tm_instance_transitions')->count());
        self::assertSame($events, DB::table('tm_outbox')->count());
        self::assertSame(2, DB::table('tm_security_decisions')->count());
        self::assertStringNotContainsString('PRIVATE-HOST-TEXT', json_encode(DB::table('tm_security_decisions')->get()));
    }
}
