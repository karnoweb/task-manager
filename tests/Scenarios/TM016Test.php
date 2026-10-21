<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\WorkflowTestCase;

final class TM016Test extends WorkflowTestCase
{
    public function test_scenario(): void
    {
        $c = $this->manager();
        $r = $this->sales();
        $this->host();
        $i = $this->instances()->start($c, 'crm.lead', 'lead-1', $r['id'], 'start');
        $this->error('required_field_missing', fn () => $this->instances()->transition($c, $i['instance_key'], 1, 'bad', 'note'));
        $next = $this->instances()->transition($c, $i['instance_key'], 1, 'note', 'note', ['note' => 'valid']);
        self::assertSame(2, $next['version']);
        self::assertSame('new', $next['current_state_key']);
        self::assertSame(1, DB::table('tm_instance_transitions')->count());
    }
}
