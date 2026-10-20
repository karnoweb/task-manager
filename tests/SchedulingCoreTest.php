<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Scheduling\LocalTime;
use Karnoweb\TaskManager\Scheduling\PersianCalendar;
use Karnoweb\TaskManager\Scheduling\RecurrenceRule;

final class SchedulingCoreTest extends PhaseFiveTestCase
{
    public function test_half_open_schedule_due_only_and_all_day_preserve_local_dates_across_zones(): void
    {
        $task = $this->todo('span'); $this->dates()->change($this->manager(), $task['public_key'], 1, '2026-10-20 09:00:00', '2026-10-22 09:00:00', null, 'Asia/Tehran', false, 'reject', 'span');
        self::assertSame([], $this->dates()->calendar($this->manager(), new \DateTimeImmutable('2026-10-22T05:30:00Z'), new \DateTimeImmutable('2026-10-23T05:30:00Z'))['events']);
        $due = $this->todo('due'); $this->dates()->change($this->manager(), $due['public_key'], 1, null, null, '2026-10-22 12:00:00', 'Asia/Tehran', false, 'reject', 'due');
        $events = $this->dates()->calendar($this->manager(), new \DateTimeImmutable('2026-10-22T06:00:00Z'), new \DateTimeImmutable('2026-10-23T06:00:00Z'))['events']; self::assertSame(['task_due'], array_column($events, 'kind'));
        foreach (['America/New_York','Asia/Tehran'] as $zone) { $a = $this->todo($zone); $a = $this->dates()->change($this->manager(), $a['public_key'], 1, '2026-11-01', '2026-11-03', null, $zone, true, 'earliest', 'all-day-'.$zone); self::assertSame('2026-11-03', $a['local_end_date']); self::assertSame(2, (new \DateTimeImmutable($a['local_start_date']))->diff(new \DateTimeImmutable($a['local_end_date']))->days); }
        self::assertSame(0, $this->dates()->calendar($this->manager('b'), new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-12-01'))['total_tasks']);
    }
    public function test_dst_gap_rejects_and_fold_has_explicit_earliest_latest_choices(): void
    {
        $this->error('local_time_nonexistent', fn () => LocalTime::resolve('2026-03-08 02:30:00', 'America/New_York'));
        $this->error('local_time_ambiguous', fn () => LocalTime::resolve('2026-11-01 01:30:00', 'America/New_York'));
        self::assertSame(3600, LocalTime::resolve('2026-11-01 01:30:00', 'America/New_York', 'latest')->getTimestamp() - LocalTime::resolve('2026-11-01 01:30:00', 'America/New_York', 'earliest')->getTimestamp());
        $this->error('timezone_invalid', fn () => LocalTime::resolve('2026-10-21 01:00:00', 'Unknown/Zone'));
    }
    public function test_daily_generation_is_horizon_bounded_unique_and_retry_safe(): void
    {
        $task = $this->todo('daily'); $series = $this->recurrence()->create($this->manager(), $task['public_key'], 1, 'gregorian', '2026-10-20 09:00:00', 'Asia/Tehran', 'FREQ=DAILY;COUNT=3', 'series');
        $from = new \DateTimeImmutable('2026-10-20T00:00:00Z'); $until = $from->modify('+10 days'); self::assertCount(3, $this->recurrence()->preview($this->manager(), $series['series_key'], $from, $until)); self::assertSame(1, DB::table('tm_tasks')->count());
        $made = $this->recurrence()->generate($this->manager(), $series['series_key'], 1, $from, $until, 'generate'); self::assertCount(3, $made['created']);
        self::assertSame($made, $this->recurrence()->generate($this->manager(), $series['series_key'], 1, $from, $until, 'generate')); self::assertSame([], $this->recurrence()->generate($this->manager(), $series['series_key'], 1, $from, $until, 'other-job')['created']); self::assertSame(3, DB::table('tm_recurrence_occurrences')->count());
    }
    public function test_weekly_local_time_survives_dst_and_configured_friday_holidays(): void
    {
        $rule = RecurrenceRule::parse('FREQ=WEEKLY;BYDAY=SU;WKST=SA'); $items = RecurrenceRule::expand('gregorian', '2026-10-25 09:00:00', 'America/New_York', $rule, new \DateTimeImmutable('2026-10-25'), new \DateTimeImmutable('2026-11-09'), $this->app->make(\Karnoweb\TaskManager\SLA\CalendarRegistry::class));
        self::assertSame(['2026-10-25 13:00:00.000000','2026-11-01 14:00:00.000000','2026-11-08 14:00:00.000000'], array_column($items, 'scheduled_at'));
        $rule = RecurrenceRule::parse(['frequency' => 'weekly', 'weekdays' => ['FR','SA'], 'holiday_policy' => 'skip', 'calendar_ref' => 'iran-v1']); $items = RecurrenceRule::expand('gregorian', '2026-10-23 09:00:00', 'Asia/Tehran', $rule, new \DateTimeImmutable('2026-10-23'), new \DateTimeImmutable('2026-10-26'), $this->app->make(\Karnoweb\TaskManager\SLA\CalendarRegistry::class)); self::assertSame(['2026-10-24 09:00:00'], array_column($items, 'local_at'));
    }
    public function test_persian_months_leap_esfand_and_missing_day_policies_are_real_calendar_operations(): void
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
    public function test_monthly_gregorian_skip_and_last_day_are_explicit(): void
    {
        $registry = $this->app->make(\Karnoweb\TaskManager\SLA\CalendarRegistry::class); $from = new \DateTimeImmutable('2026-01-01'); $until = new \DateTimeImmutable('2026-04-01');
        foreach (['skip' => ['2026-01-31 09:00:00','2026-03-31 09:00:00'], 'last_day' => ['2026-01-31 09:00:00','2026-02-28 09:00:00','2026-03-31 09:00:00']] as $policy => $expected) { $items = RecurrenceRule::expand('gregorian', '2026-01-31 09:00:00', 'UTC', RecurrenceRule::parse(['frequency' => 'monthly', 'missing_day' => $policy]), $from, $until, $registry); self::assertSame($expected, array_column($items, 'local_at')); }
    }
}
