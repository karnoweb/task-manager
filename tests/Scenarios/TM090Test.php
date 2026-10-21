<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Karnoweb\TaskManager\Planning\ReportingService;
use Karnoweb\TaskManager\Planning\TimeTrackingService;
use Karnoweb\TaskManager\Tests\AgileTestCase;

final class TM090Test extends AgileTestCase
{
    public function test_scenario(): void
    {
        [$revision, $project, $parent] = $this->projectTask();
        $a = $this->points($this->inProject($project, 'a'), '3');
        $b = $this->points($this->inProject($project, 'b'), '5');
        $this->attach($a, $parent, 'a-parent');
        $this->attach($b, $parent, 'b-parent');
        $reports = $this->app->make(ReportingService::class);
        $this->app->make(TimeTrackingService::class)->log($this->manager(), $a['public_key'], new \DateTimeImmutable('2026-10-19T22:00:00Z'), new \DateTimeImmutable('2026-10-19T22:10:00Z'), null, 'time');
        self::assertSame(600, $reports->subtreeTime($this->manager(), $parent['public_key'])['spent_seconds']);
        $totals = $reports->totals($this->manager(), $project['project_key']);
        self::assertSame(2, $totals['leaf_tasks']);
        self::assertSame('8.00', $totals['points']);
        self::assertSame(600, $totals['spent_seconds']);
        self::assertSame(0, $reports->totals($this->manager('b'))['total_tasks']);
        $this->clock->advance('+1 hour');
        $this->step($a, 'review', 'review');
        $this->clock->advance('+10 minutes');
        $this->step($a, 'approve', 'approve');
        $flow = $reports->flow($this->manager(), $a['public_key']);
        self::assertSame(600, $flow['cycle_seconds']);
        self::assertSame(4200, $flow['lead_seconds']);
    }
}
