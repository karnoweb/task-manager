<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Planning\TimeTrackingService;
use Karnoweb\TaskManager\TaskGraph\TaskPlanningService;

use Karnoweb\TaskManager\Tests\PhaseFourTestCase;

final class TM110Test extends PhaseFourTestCase
{
    public function test_scenario(): void
    {
        $a = $this->todo('a'); $b = $this->todo('b'); $timer = $this->time()->start($this->manager(), $a['public_key'], 'start');
        self::assertSame($timer['timer_key'], $this->time()->start($this->manager(), $a['public_key'], 'start')['timer_key']);
        $this->error('timer_active', fn () => $this->time()->start($this->manager(), $b['public_key'], 'second'));
        $this->clock->advance('+15 minutes'); $entry = $this->time()->stop($this->manager(), $timer['timer_key'], 1, 'stop');
        self::assertSame(900, $entry['duration_seconds']); self::assertSame($entry['public_key'], $this->time()->stop($this->manager(), $timer['timer_key'], 1, 'stop')['public_key']);
        self::assertSame(1, DB::table('tm_task_time_entries')->count()); self::assertSame(0, DB::table('tm_active_timers')->count());
        self::assertSame('active', $this->time()->start($this->manager(), $b['public_key'], 'next')['state']);
    }
    private function time(): TimeTrackingService { return $this->app->make(TimeTrackingService::class); }
}
