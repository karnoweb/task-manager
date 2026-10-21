<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\WorkflowTestCase;

final class TM023Test extends WorkflowTestCase
{
    public function test_scenario(): void
    {
        $c = $this->manager();
        [$r,$p,$t] = $this->projectTask();
        $bug = $this->workflows()->publish($c, 'bug', 0, 'bug-workflow', ['initial' => 'triage', 'states' => ['triage' => ['terminal' => 'none'], 'fixed' => ['terminal' => 'success']], 'transitions' => ['fix' => ['from' => 'triage', 'to' => 'fixed']]]);
        $this->projects()->change($c, $p['project_key'], 1, 'map', 'type_workflow', ['type' => 'bug', 'revision' => $bug['id']]);
        $todo = $this->service()->create($c, 'Bug', 'bug-task');
        $preview = $this->projects()->previewTaskMove($c, $todo['public_key'], $p['project_key'], 'bug', ['pending' => 'triage']);
        $converted = $this->projects()->moveTask($c, $preview, 'convert-bug');
        self::assertSame('triage', $converted['state']);
        $instance = DB::table('tm_workflow_instances')->where('task_id', $converted['id'])->first();
        $fixed = $this->instances()->transition($c, $instance->instance_key, $instance->version, 'fix', 'fix');
        self::assertSame('fixed', $fixed['current_state_key']);
        self::assertSame('new', $this->service()->get($c,$t['public_key'])['state']);
    }
}
