<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Karnoweb\TaskManager\TaskGraph\TaskPlanningService;
use Karnoweb\TaskManager\Tests\PhaseFourTestCase;

final class TM072Test extends PhaseFourTestCase
{
    public function test_scenario(): void
    {
        [$revision, $project, $a] = $this->projectTask();
        $b = $this->inProject($project, 'b');
        self::assertSame('insufficient_data', $this->planning()->criticalPath($this->manager(), $project['project_key'])['state']);
        $this->planning()->estimate($this->manager(), $a['public_key'], $a['version'], 60, '3', 'a-estimate');
        $this->planning()->estimate($this->manager(), $b['public_key'], $b['version'], 30, '2', 'b-estimate');
        $this->link($a, $b, 'FS', ['lag_minutes' => 10]);
        $result = $this->planning()->criticalPath($this->manager(), $project['project_key'], new \DateTimeImmutable('2026-10-20T08:00:00Z'));
        self::assertSame('calculated', $result['state']);
        self::assertEquals(100, $result['duration_minutes']);
        self::assertSame([$a['public_key'], $b['public_key']], $result['path']);
    }

    private function planning(): TaskPlanningService
    {
        return $this->app->make(TaskPlanningService::class);
    }
}
