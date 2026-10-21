<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\WorkflowTestCase;

final class TM017Test extends WorkflowTestCase
{
    public function test_scenario(): void
    {
        $c = $this->manager();
        $r = $this->sales();
        $p = $this->pipeline($r['id']);
        $this->host();
        $i = $this->instances()->start($c, 'crm.lead', 'lead-1', $r['id'], 'start', pipelineKey: $p['pipeline_key']);
        $next = $this->instances()->transition($c, $i['instance_key'], 1, 'note', 'note', ['note' => 'valid']);
        self::assertSame('new', $next['current_state_key']);
        self::assertCount(1, $this->instances()->visits($c, $i['instance_key']));
        self::assertSame('new', DB::table('tm_instance_transitions')->value('from_state'));
        self::assertSame('new', DB::table('tm_instance_transitions')->value('to_state'));
    }
}
