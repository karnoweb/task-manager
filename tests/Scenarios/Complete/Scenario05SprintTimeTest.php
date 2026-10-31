<?php

namespace Karnoweb\TaskManager\Tests\Scenarios\Complete;

use Karnoweb\TaskManager\Planning\TimeTrackingService;
use Karnoweb\TaskManager\Tests\AgileTestCase;

final class Scenario05SprintTimeTest extends AgileTestCase
{
    public function test_complete_scenario_05(): void
    {

        [$revision, $project, $a] = $this->projectTask();
        $a = $this->points($a, '5');
        $sprint = $this->sprints()->create($this->manager(), $project['project_key'], 'One', null, null, 'sprint');
        $sprint = $this->enrollTasks($sprint, [$a], 'enroll');
        $sprint = $this->sprints()->start($this->manager(), $sprint['sprint_key'], $sprint['version'], 'start');
        self::assertSame(500, $this->sprints()->report($this->manager(), $sprint['sprint_key'])['committed']['points_minor']);
        $this->points($a, '13');
        $this->clock->advance('+1 day');
        $this->sprints()->snapshot($this->manager(), $sprint['sprint_key'], $sprint['version'], 'daily');
        $report = $this->sprints()->report($this->manager(), $sprint['sprint_key']);
        self::assertSame(500, $report['current']['points_minor']);
        self::assertSame('0.00', $report['scope_change_points']);

        $time = $this->app->make(TimeTrackingService::class);
        $entry = $time->log($this->manager(), $a['public_key'], new \DateTimeImmutable('2026-10-19T23:00:00Z'), new \DateTimeImmutable('2026-10-19T23:15:00Z'), null, 'time');
        self::assertSame(900, $entry['duration_seconds']);
        self::assertSame(2700, $time->stats($this->manager(), $a['public_key'])['remaining_seconds']);

    }
}
