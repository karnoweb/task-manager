<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Karnoweb\TaskManager\Scheduling\RecurrenceRule;
use Karnoweb\TaskManager\SLA\CalendarRegistry;
use Karnoweb\TaskManager\Tests\PhaseFiveTestCase;

final class TM114Test extends PhaseFiveTestCase
{
    public function test_scenario(): void
    {
        $rule = RecurrenceRule::parse('FREQ=WEEKLY;BYDAY=SU;WKST=SA');
        $items = RecurrenceRule::expand('gregorian', '2026-10-25 09:00:00', 'America/New_York', $rule, new \DateTimeImmutable('2026-10-25'), new \DateTimeImmutable('2026-11-09'), $this->app->make(CalendarRegistry::class));
        self::assertSame(['2026-10-25 13:00:00.000000', '2026-11-01 14:00:00.000000', '2026-11-08 14:00:00.000000'], array_column($items, 'scheduled_at'));
        $rule = RecurrenceRule::parse(['frequency' => 'weekly', 'weekdays' => ['FR', 'SA'], 'holiday_policy' => 'skip', 'calendar_ref' => 'iran-v1']);
        $items = RecurrenceRule::expand('gregorian', '2026-10-23 09:00:00', 'Asia/Tehran', $rule, new \DateTimeImmutable('2026-10-23'), new \DateTimeImmutable('2026-10-26'), $this->app->make(CalendarRegistry::class));
        self::assertSame(['2026-10-24 09:00:00'], array_column($items, 'local_at'));
    }
}
