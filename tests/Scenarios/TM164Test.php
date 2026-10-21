<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\WorkflowTestCase;

final class TM164Test extends WorkflowTestCase
{
    public function test_scenario(): void
    {
        $c = $this->manager();
        $r = $this->sales();
        $p = $this->pipeline($r['id']);
        $this->host();
        $i = $this->instances()->start($c, 'crm.lead', 'lead-1', $r['id'], 'start', pipelineKey: $p['pipeline_key']);
        $events = DB::table('tm_outbox')->count();
        foreach (['board-one', 'board-two'] as $board) {
            self::assertSame($i, $this->instances()->changeBoardVisibility($c, $i['instance_key']));
        } self::assertSame($events, DB::table('tm_outbox')->count());
        self::assertSame(1, DB::table('tm_workflow_instances')->count());
        self::assertSame('new', DB::table('tm_workflow_instances')->value('current_state_key'));
    }
}
