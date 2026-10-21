<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Karnoweb\TaskManager\TaskGraph\TaskPlanningService;
use Karnoweb\TaskManager\Tests\PhaseFourTestCase;

final class TM106Test extends PhaseFourTestCase
{
    public function test_scenario(): void
    {
        $a = $this->todo('a');
        $b = $this->todo('b');
        $this->link($a, $b);
        $preview = $this->planning()->previewSchedule($this->manager(), $a['public_key'], new \DateTimeImmutable('2026-10-19T08:00:00Z'), new \DateTimeImmutable('2026-10-19T09:00:00Z'));
        $this->planning()->executeSchedule($this->manager(), $preview, 'plan');
        $b = $this->service()->get($this->manager(), $b['public_key']);
        $this->error('dependency_date_missing', fn () => $this->service()->mutate($this->manager(), $b['public_key'], $b['version'], 'complete', 'complete'));
    }

    private function planning(): TaskPlanningService
    {
        return $this->app->make(TaskPlanningService::class);
    }
}
