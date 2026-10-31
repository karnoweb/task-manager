<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\Support\ProcessBarrier;

final class ProjectCreationConcurrencyTest extends WorkflowTestCase
{
    public function test_duplicate_creation_across_two_connections_creates_one_main_instance(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['pgsql', 'mariadb'], true)) {
            self::markTestSkipped('Two physical connections require the dedicated server fixture.');
        }
        $revision = $this->sales();
        $project = $this->projects()->create($this->manager(), 'RACE', 'Race', $revision['id'], 'project');
        $results = ProcessBarrier::run([[$project['project_key'], 'same-create'], [$project['project_key'], 'same-create']], 'concurrent-project-create.php');
        self::assertArrayHasKey('key', $results[0]);
        self::assertSame($results[0], $results[1]);
        self::assertSame(1, DB::table('tm_tasks')->count());
        self::assertSame(1, DB::table('tm_workflow_instances')->count());
        self::assertSame(1, DB::table('tm_active_process_slots')->count());
        self::assertSame(1, DB::table('tm_outbox')->where('action', 'created')->count());
    }

    public function test_distinct_creation_commands_allocate_distinct_project_display_keys(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['pgsql', 'mariadb'], true)) {
            self::markTestSkipped('Two physical connections require the dedicated server fixture.');
        }
        $revision = $this->sales();
        $project = $this->projects()->create($this->manager(), 'RACE', 'Race', $revision['id'], 'project');
        $results = ProcessBarrier::run([[$project['project_key'], 'create-a'], [$project['project_key'], 'create-b']], 'concurrent-project-create.php');
        self::assertArrayHasKey('key', $results[0]);
        self::assertArrayHasKey('key', $results[1]);
        self::assertNotSame($results[0]['key'], $results[1]['key']);
        $keys = array_column($results, 'display');
        sort($keys);
        self::assertSame(['RACE-1', 'RACE-2'], $keys);
        self::assertSame(2, DB::table('tm_workflow_instances')->count());
    }
}
