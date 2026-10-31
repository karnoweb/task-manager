<?php

namespace Karnoweb\TaskManager\Tests\Scenarios\Complete;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Facades\TaskManager;
use Karnoweb\TaskManager\Tests\WorkflowTestCase;

final class Scenario03ProjectTest extends WorkflowTestCase
{
    private function project(): array
    {
        $revision = $this->sales();

        return $this->projects()->create($this->manager(), 'DX', 'Developer experience', $revision['id'], 'project');
    }

    public function test_complete_scenario_03(): void
    {

        $project = $this->project();
        $c = $this->manager();
        $task = TaskManager::projects()->createTask($c, $project['project_key'], 'bug', 'Fix login', 'direct');
        self::assertSame('new', $task['state']);
        self::assertSame(1, $task['version']);
        self::assertSame('DX-1', $task['display_key']);
        self::assertSame('bug', $task['type_key']);
        self::assertSame($project['id'], $task['project_id']);
        self::assertSame(1, DB::table('tm_tasks')->count());
        self::assertSame(1, DB::table('tm_workflow_instances')->count());
        self::assertSame(1, DB::table('tm_workflow_instances')->value('version'));
        self::assertSame(0, DB::table('tm_workflow_definitions')->where('key', 'personal')->count());
        self::assertSame(0, DB::table('tm_task_key_aliases')->where('display_key', 'like', 'TM-%')->count());
        self::assertSame(1, DB::table('tm_outbox')->where('action', 'created')->count());
        self::assertSame($task, TaskManager::projects()->createTask($c, $project['project_key'], 'bug', 'Fix login', 'direct'));
        self::assertSame(1, DB::table('tm_tasks')->count());
        $this->error('idempotency_key_conflict', fn () => TaskManager::projects()->createTask($c, $project['project_key'], 'bug', 'Different', 'direct'));

        $main = DB::table('tm_workflow_instances')->where('task_id', $task['id'])->first();
        $this->error('workflow_command_required', fn () => $this->service()->inContext($c)->complete($task['public_key'], 1, 'bad-complete'));
        $review = $this->instances()->transition($c, $main->instance_key, 1, 'review-task', 'review');
        self::assertSame('review', $review['current_state_key']);
        self::assertSame(2, $review['version']);
        $edited = $this->service()->inContext($c)->update($task['public_key'], 2, 'edit-title', ['title' => 'Fix login updated']);
        self::assertSame(3, $edited->version);
        self::assertSame(2, $this->instances()->get($c, $main->instance_key)['version']);

    }
}
