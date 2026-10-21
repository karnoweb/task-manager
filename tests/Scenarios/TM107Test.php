<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\TaskGraph\TaskPlanningService;
use Karnoweb\TaskManager\Tests\PhaseFourTestCase;

final class TM107Test extends PhaseFourTestCase
{
    public function test_scenario(): void
    {
        [$revision, $project, $a] = $this->projectTask();
        $other = $this->projects()->create($this->manager(), 'OTHER', 'Other', $revision['id'], 'other');
        $b = $this->inProject($other, 'other-task');
        $this->projects()->change($this->manager(), $project['project_key'], 1, 'member-b', 'member', ['actor' => 'b', 'role' => 'member']);
        $this->error('not_found', fn () => $this->dependencies()->add($this->manager('b'), $a['public_key'], $a['version'], $b['public_key'], $b['version'], 'FS', [], 'limited'));
        $link = $this->link($a, $b);
        self::assertSame('FS', $link['type']);
        self::assertSame(1, DB::table('tm_task_dependencies')->count());
    }

    private function planning(): TaskPlanningService
    {
        return $this->app->make(TaskPlanningService::class);
    }
}
