<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\WorkflowTestCase;

final class TM096Test extends WorkflowTestCase
{
    public function test_scenario(): void
    {
        $c = $this->manager();
        [$r,$p,$parent] = $this->projectTask();
        $bug = $this->workflows()->publish($c, 'bug', 0, 'bug-workflow', ['initial' => 'triage', 'states' => ['triage' => ['terminal' => 'none'], 'fixed' => ['terminal' => 'success']], 'transitions' => ['fix' => ['from' => 'triage', 'to' => 'fixed']]]);
        $this->projects()->change($c, $p['project_key'], 1, 'map', 'type_workflow', ['type' => 'bug', 'revision' => $bug['id']]);
        $todo = $this->service()->create($c, 'Bug', 'bug-task');
        $preview = $this->projects()->previewTaskMove($c, $todo['public_key'], $p['project_key'], 'bug', ['pending' => 'triage']);
        $child = $this->projects()->moveTask($c, $preview, 'convert-bug', $parent['public_key']);
        self::assertSame($parent['id'], $child['parent_task_id']);
        $i = DB::table('tm_workflow_instances')->where('task_id', $child['id'])->first();
        $this->instances()->transition($c, $i->instance_key, $i->version, 'fix', 'fix');
        self::assertSame('fixed', $this->service()->get($c, $child['public_key'])['state']);
        self::assertSame('new', $this->service()->get($c,$parent['public_key'])['state']);
    }
}
