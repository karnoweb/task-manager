<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Karnoweb\TaskManager\Planning\TimeTrackingService;
use Karnoweb\TaskManager\Tests\PhaseFourTestCase;

final class TM050Test extends PhaseFourTestCase
{
    public function test_scenario(): void
    {
        $a = $this->todo('a');
        $b = $this->todo('b');
        $start = new \DateTimeImmutable('2026-10-19T22:00:00Z');
        $end = $start->modify('+1 hour');
        $this->time()->log($this->manager(), $a['public_key'], $start, $end, null, 'first');
        $this->error('time_overlap', fn () => $this->time()->log($this->manager(), $b['public_key'], $start->modify('+30 minutes'), $end->modify('+30 minutes'), null, 'overlap'));
        $warn = $this->time()->log($this->manager(), $b['public_key'], $start->modify('+30 minutes'), $end->modify('+30 minutes'), null, 'warn', 'warning');
        self::assertTrue((bool) $warn['overlap_warning']);
        $adjacent = $this->time()->log($this->manager(), $a['public_key'], $end->modify('+30 minutes'), $end->modify('+45 minutes'), null, 'adjacent');
        self::assertFalse((bool) $adjacent['overlap_warning']);
    }

    private function time(): TimeTrackingService
    {
        return $this->app->make(TimeTrackingService::class);
    }
}
