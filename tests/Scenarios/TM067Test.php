<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\AgileTestCase;

final class TM067Test extends AgileTestCase
{
    public function test_scenario(): void
    {
        [$revision, $project, $a] = $this->projectTask();
        $a = $this->points($a, '3');
        $b = $this->points($this->inProject($project, 'b'), '2');
        $one = $this->sprints()->create($this->manager(), $project['project_key'], 'One', null, null, 'one');
        $two = $this->sprints()->create($this->manager(), $project['project_key'], 'Two', null, null, 'two');
        $one = $this->enrollTasks($one, [$a, $b], 'enroll');
        $one = $this->sprints()->start($this->manager(), $one['sprint_key'], $one['version'], 'start');
        $this->clock->advance('+1 hour');
        $this->done($a, 'a');
        $closed = $this->sprints()->close($this->manager(), $one['sprint_key'], $one['version'], 'carry', $two['sprint_key'], 1, 'close');
        self::assertSame('completed', $closed['state']);
        self::assertSame('3.00', $this->sprints()->report($this->manager(), $one['sprint_key'])['velocity']);
        self::assertSame([$b['id']], DB::table('tm_sprint_memberships')->where('sprint_id', $two['id'])->pluck('task_id')->all());
        self::assertSame(2, DB::table('tm_sprint_tasks')->where('sprint_id', $one['id'])->whereNotNull('left_at')->count());
        self::assertSame(0, DB::table('tm_active_sprint_tasks')->count());
    }
}
