<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Planning\TimeTrackingService;
use Karnoweb\TaskManager\TaskGraph\TaskPlanningService;

use Karnoweb\TaskManager\Tests\PhaseFourTestCase;

final class TM049Test extends PhaseFourTestCase
{
    public function test_scenario(): void
    {
        $task = $this->todo('task'); $this->app->make(TaskPlanningService::class)->estimate($this->manager(), $task['public_key'], 1, 60, '3', 'estimate');
        $entry = $this->time()->log($this->manager(), $task['public_key'], new \DateTimeImmutable('2026-10-19T23:00:00Z'), new \DateTimeImmutable('2026-10-19T23:15:00Z'), 'PRIVATE note', 'log');
        self::assertSame(900, $entry['duration_seconds']); $stats = $this->time()->stats($this->manager(), $task['public_key']); self::assertSame(900, $stats['spent_seconds']); self::assertSame(2700, $stats['remaining_seconds']);
        self::assertStringNotContainsString('PRIVATE note', json_encode(DB::table('tm_outbox')->get()->all()));
    }
    private function time(): TimeTrackingService { return $this->app->make(TimeTrackingService::class); }
}
