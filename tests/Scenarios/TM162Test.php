<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\WorkflowTestCase;

final class TM162Test extends WorkflowTestCase
{
    public function test_scenario(): void
    {
        $c = $this->manager();
        $r = $this->sales();
        $this->host();
        $p = $this->pipelines()->create($c, 'Ambiguous', $r['id'], ['first' => ['state' => 'new'], 'second' => ['state' => 'new']], 'pipeline');
        $this->error('stage_ambiguous', fn () => $this->instances()->start($c, 'crm.lead', 'lead-1', $r['id'], 'ambiguous', pipelineKey: $p['pipeline_key']));
        self::assertSame(0, DB::table('tm_workflow_instances')->count());
        $i = $this->instances()->start($c, 'crm.lead', 'lead-1', $r['id'], 'explicit', pipelineKey: $p['pipeline_key'], stageKey: 'second');
        self::assertSame('second', $i['active_stage_key']);
        self::assertSame('new', $i['current_state_key']);
        self::assertCount(1, $this->instances()->visits($c, $i['instance_key']));
    }
}
