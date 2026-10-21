<?php

namespace Karnoweb\TaskManager\Tests;

use Karnoweb\TaskManager\Planning\SprintService;
use Karnoweb\TaskManager\TaskGraph\TaskPlanningService;

abstract class AgileTestCase extends PhaseFourTestCase
{
    protected function sprints(): SprintService
    {
        return $this->app->make(SprintService::class);
    }

    protected function planning(): TaskPlanningService
    {
        return $this->app->make(TaskPlanningService::class);
    }

    protected function points(array $task, string $points): array
    {
        $task = $this->service()->get($this->manager(), $task['public_key']);

        return $this->planning()->estimate($this->manager(), $task['public_key'], $task['version'], 60, $points, 'points-'.$task['id'].'-'.$points);
    }

    protected function enrollTasks(array $sprint, array $tasks, string $operation): array
    {
        $references = [];
        foreach ($tasks as $task) {
            $current = $this->service()->get($this->manager(), $task['public_key']);
            $references[] = ['task_key' => $current['public_key'], 'version' => $current['version']];
        }
        $sprint = $this->sprints()->get($this->manager(), $sprint['sprint_key']);

        return $this->sprints()->enroll($this->manager(), $sprint['sprint_key'], $sprint['version'], $references, $operation);
    }

    protected function done(array $task, string $prefix): void
    {
        $this->step($task, 'review', $prefix.'-review');
        $this->clock->advance('+1 minute');
        $this->step($task, 'approve', $prefix.'-done');
    }
}
