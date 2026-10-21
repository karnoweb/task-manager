<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\WorkflowTestCase;

final class TM015Test extends WorkflowTestCase
{
    public function test_scenario(): void
    {
        $c = $this->manager();
        $r = $this->sales();
        $this->host();
        $i = $this->instances()->start($c, 'crm.lead', 'lead-1', $r['id'], 'start');
        $before = DB::table('tm_outbox')->count();
        $this->error('transition_invalid', fn () => $this->instances()->transition($c, $i['instance_key'], 1, 'invalid', 'approve'));
        self::assertSame($i, $this->instances()->get($c, $i['instance_key']));
        self::assertSame($before, DB::table('tm_outbox')->count());
        self::assertSame(0, DB::table('tm_instance_transitions')->count());
    }
}
