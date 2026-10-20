<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\TaskGraph\TaskPlanningService;

use Karnoweb\TaskManager\Tests\PhaseFourTestCase;

final class TM040Test extends PhaseFourTestCase
{
    public function test_scenario(): void
    {
        $a = $this->todo('a'); $b = $this->todo('b'); $this->planning()->estimate($this->manager(), $b['public_key'], 1, 30, '3', 'estimate'); $this->link($a, $b);
        $before = DB::table('tm_outbox')->count();
        $preview = $this->planning()->previewSchedule($this->manager(), $a['public_key'], new \DateTimeImmutable('2026-10-20T08:00:00Z'), new \DateTimeImmutable('2026-10-20T09:00:00Z'));
        self::assertSame($before, DB::table('tm_outbox')->count()); self::assertSame(0, DB::table('tm_tasks')->whereNotNull('planned_start_at')->count());
        $bPreview = array_values(array_filter($preview['items'], fn ($item) => $item['task_key'] === $b['public_key']))[0]; self::assertSame('2026-10-20 09:30:00.000000', $bPreview['finish']);
        self::assertSame(2, $this->planning()->executeSchedule($this->manager(), $preview, 'apply')['changed']);
        self::assertSame('2026-10-20 09:00:00.000000', $this->service()->get($this->manager(), $b['public_key'])['planned_start_at']);
        $events = DB::table('tm_outbox')->count(); $this->planning()->executeSchedule($this->manager(), $preview, 'apply'); self::assertSame($events, DB::table('tm_outbox')->count());
    }
    private function planning(): TaskPlanningService { return $this->app->make(TaskPlanningService::class); }
}
