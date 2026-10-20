<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;

use Karnoweb\TaskManager\Tests\HierarchyTestCase;

final class TM031Test extends HierarchyTestCase
{
    public function test_scenario(): void
    {
        [$revision, $project, $root] = $this->projectTask();
        $bug = $this->workflows()->publish($this->manager(), 'bug', 0, 'bug-workflow', $this->definition());
        $project = $this->projects()->change($this->manager(), $project['project_key'], 1, 'bug-type', 'type_workflow', ['type' => 'bug', 'revision' => $bug['id']]);
        $child = $this->attach($this->inProject($project, 'bug-child', 'bug'), $root, 'bug-parent');
        self::assertSame($root['id'], $child['parent_task_id']);
        self::assertNotSame(DB::table('tm_workflow_instances')->where('task_id', $root['id'])->value('workflow_revision_id'), DB::table('tm_workflow_instances')->where('task_id', $child['id'])->value('workflow_revision_id'));
        $other = $this->todo('personal'); $this->error('parent_project_mismatch', fn () => $this->attach($other, $root, 'wrong-project'));
    }

}
