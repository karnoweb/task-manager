<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;

use Karnoweb\TaskManager\Tests\AgileTestCase;

final class TM066Test extends AgileTestCase
{
    public function test_scenario(): void
    {
        [$revision, $project, $a] = $this->projectTask(); $a = $this->points($a, '3'); $b = $this->points($this->inProject($project, 'b'), '2');
        $sprint = $this->sprints()->create($this->manager(), $project['project_key'], 'One', null, null, 'sprint'); $sprint = $this->enrollTasks($sprint, [$a], 'enroll'); $sprint = $this->sprints()->start($this->manager(), $sprint['sprint_key'], $sprint['version'], 'start');
        $sprint = $this->enrollTasks($sprint, [$b], 'add-active'); self::assertSame('2.00', $this->sprints()->report($this->manager(), $sprint['sprint_key'])['scope_change_points']); $this->clock->advance('+1 day'); $this->sprints()->snapshot($this->manager(), $sprint['sprint_key'], $sprint['version'], 'daily');
        $report = $this->sprints()->report($this->manager(), $sprint['sprint_key']); self::assertSame(300, $report['committed']['points_minor']); self::assertSame('2.00', $report['scope_change_points']);
        $b = $this->service()->get($this->manager(), $b['public_key']); $this->sprints()->remove($this->manager(), $sprint['sprint_key'], $sprint['version'], $b['public_key'], $b['version'], 'remove');
        self::assertSame(500, $this->sprints()->report($this->manager(), $sprint['sprint_key'])['current']['points_minor']);
    }

}
