<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;

use Karnoweb\TaskManager\Tests\AgileTestCase;

final class TM069Test extends AgileTestCase
{
    public function test_scenario(): void
    {
        [$revision, $project, $a] = $this->projectTask(); $a = $this->points($a, '5'); $sprint = $this->sprints()->create($this->manager(), $project['project_key'], 'Sprint', null, null, 'sprint'); $sprint = $this->enrollTasks($sprint, [$a], 'enroll'); $sprint = $this->sprints()->start($this->manager(), $sprint['sprint_key'], $sprint['version'], 'start');
        $this->clock->advance('+1 day'); $daily = $this->sprints()->snapshot($this->manager(), $sprint['sprint_key'], $sprint['version'], 'daily'); $again = $this->sprints()->snapshot($this->manager(), $sprint['sprint_key'], $sprint['version'], 'daily-again'); self::assertSame($daily['snapshot_key'], $again['snapshot_key']);
        $this->done($a, 'a'); self::assertSame(500, $this->sprints()->report($this->manager(), $sprint['sprint_key'])['current']['remaining_points_minor']);
        $this->clock->advance('+1 day'); $this->sprints()->snapshot($this->manager(), $sprint['sprint_key'], $sprint['version'], 'next-day'); $report = $this->sprints()->report($this->manager(), $sprint['sprint_key']); self::assertSame(0, $report['current']['remaining_points_minor']); self::assertSame(500, $report['history'][1]['remaining_points_minor']); self::assertSame(3, DB::table('tm_sprint_daily_snapshots')->count());
    }

}
