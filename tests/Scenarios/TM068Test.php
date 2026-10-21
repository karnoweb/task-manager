<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Karnoweb\TaskManager\Tests\AgileTestCase;

final class TM068Test extends AgileTestCase
{
    public function test_scenario(): void
    {
        [$revision, $project, $epic] = $this->projectTask();
        $epic = $this->points($epic, '100');
        $a = $this->points($this->inProject($project, 'a'), '3');
        $b = $this->points($this->inProject($project, 'b'), '5');
        $this->attach($a, $epic, 'a-epic');
        $this->attach($b, $epic, 'b-epic');
        $sprint = $this->sprints()->create($this->manager(), $project['project_key'], 'Sprint', null, null, 'sprint');
        $sprint = $this->enrollTasks($sprint, [$epic, $a, $b], 'enroll');
        $sprint = $this->sprints()->start($this->manager(), $sprint['sprint_key'], $sprint['version'], 'start');
        self::assertSame(800, $this->sprints()->report($this->manager(), $sprint['sprint_key'])['committed']['points_minor']);
        $this->clock->advance('+1 hour');
        $this->done($a, 'a');
        $this->done($epic, 'epic');
        $this->sprints()->close($this->manager(), $sprint['sprint_key'], $sprint['version'], 'backlog', null, null, 'close');
        self::assertSame('3.00', $this->sprints()->report($this->manager(), $sprint['sprint_key'])['velocity']);
    }
}
