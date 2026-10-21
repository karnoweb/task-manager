<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\TaskManager;
use Karnoweb\TaskManager\Tests\WorkflowTestCase;
use Karnoweb\TaskManager\Workflow\InstanceService;
use Karnoweb\TaskManager\Workflow\ProjectService;

final class TM212Test extends WorkflowTestCase
{
    public function test_scenario(): void
    {
        $c = $this->manager();
        $r = $this->sales();
        $t = $this->service()->create($c, 'Native', 'task');
        $i = $this->instances()->start($c, 'task', $t['public_key'], $r['id'], 'process', slot: 'review');
        self::assertSame($t['id'], $i['task_id']);
        self::assertSame('pending', $this->service()->get($c, $t['public_key'])['state']);
        self::assertSame('new', $i['current_state_key']);
        self::assertSame(0, DB::table('host_subjects')->count());
        $manager = $this->app->make(TaskManager::class);
        self::assertInstanceOf(InstanceService::class, $manager->instances());
        self::assertInstanceOf(ProjectService::class, $manager->projects());
    }
}
