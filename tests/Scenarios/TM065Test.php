<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;

use Karnoweb\TaskManager\Tests\AgileTestCase;

final class TM065Test extends AgileTestCase
{
    public function test_scenario(): void
    {
        [$revision, $project, $a] = $this->projectTask(); $a = $this->points($a, '5'); $sprint = $this->sprints()->create($this->manager(), $project['project_key'], 'One', null, null, 'sprint');
        $sprint = $this->enrollTasks($sprint, [$a], 'enroll'); $sprint = $this->sprints()->start($this->manager(), $sprint['sprint_key'], $sprint['version'], 'start');
        self::assertSame(500, $this->sprints()->report($this->manager(), $sprint['sprint_key'])['committed']['points_minor']);
        $this->points($a, '13'); $this->clock->advance('+1 day'); $this->sprints()->snapshot($this->manager(), $sprint['sprint_key'], $sprint['version'], 'daily');
        $report = $this->sprints()->report($this->manager(), $sprint['sprint_key']); self::assertSame(500, $report['current']['points_minor']); self::assertSame('0.00', $report['scope_change_points']);
    }

}
