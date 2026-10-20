<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Scheduling\LocalTime;
use Karnoweb\TaskManager\Scheduling\PersianCalendar;
use Karnoweb\TaskManager\Scheduling\RecurrenceRule;
use Karnoweb\TaskManager\Tests\PhaseFiveTestCase;

final class TM118Test extends PhaseFiveTestCase
{
    public function test_scenario():void
    {
        self::assertSame('2025-03-20', PersianCalendar::date(1403, 12, 30)); self::assertSame(29, PersianCalendar::days(1404, 12)); self::assertSame(30, PersianCalendar::days(1403, 12));
        $calendars = $this->app->make(\Karnoweb\TaskManager\SLA\CalendarRegistry::class); $from = new \DateTimeImmutable('2026-03-21'); $until = new \DateTimeImmutable('2026-07-01'); $anchor = PersianCalendar::date(1405, 1, 25).' 09:00:00';
        $items = RecurrenceRule::expand('jalali', $anchor, 'Asia/Tehran', RecurrenceRule::parse(['frequency' => 'monthly', 'month_day' => 25]), $from, $until, $calendars);
        self::assertSame(['2026-04-14 09:00:00','2026-05-15 09:00:00','2026-06-15 09:00:00'], array_column($items, 'local_at'));
        $anchor = PersianCalendar::date(1404, 11, 30).' 09:00:00'; $from = new \DateTimeImmutable('2026-02-01'); $until = new \DateTimeImmutable('2026-04-01');
        $skip = RecurrenceRule::expand('jalali', $anchor, 'UTC', RecurrenceRule::parse(['frequency' => 'monthly', 'month_day' => 30]), $from, $until, $calendars);
        $last = RecurrenceRule::expand('jalali', $anchor, 'UTC', RecurrenceRule::parse(['frequency' => 'monthly', 'month_day' => 30, 'missing_day' => 'last_day']), $from, $until, $calendars);
        self::assertCount(1, $skip); self::assertCount(2, $last); self::assertSame('2026-03-20 09:00:00', $last[1]['local_at']);
    }

}
