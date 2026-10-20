<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Scheduling\LocalTime;
use Karnoweb\TaskManager\Scheduling\PersianCalendar;
use Karnoweb\TaskManager\Scheduling\RecurrenceRule;
use Karnoweb\TaskManager\Tests\PhaseFiveTestCase;

final class TM059Test extends PhaseFiveTestCase
{
    public function test_scenario():void
    {
        $registry = $this->app->make(\Karnoweb\TaskManager\SLA\CalendarRegistry::class); $from = new \DateTimeImmutable('2026-01-01'); $until = new \DateTimeImmutable('2026-04-01');
        foreach (['skip' => ['2026-01-31 09:00:00','2026-03-31 09:00:00'], 'last_day' => ['2026-01-31 09:00:00','2026-02-28 09:00:00','2026-03-31 09:00:00']] as $policy => $expected) { $items = RecurrenceRule::expand('gregorian', '2026-01-31 09:00:00', 'UTC', RecurrenceRule::parse(['frequency' => 'monthly', 'missing_day' => $policy]), $from, $until, $registry); self::assertSame($expected, array_column($items, 'local_at')); }
    }

}
