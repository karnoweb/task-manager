<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Scheduling\RecurrenceRule;
use Karnoweb\TaskManager\SLA\CalendarRegistry;

final class RecurrenceNonexistentTimeTest extends PhaseFiveTestCase
{
    private function expand(array $rule): array
    {
        return RecurrenceRule::expand('gregorian', '2026-03-07 02:30:00', 'America/New_York', RecurrenceRule::parse($rule + ['frequency' => 'daily', 'dst_policy' => 'earliest']), new \DateTimeImmutable('2026-03-07T00:00:00Z'), new \DateTimeImmutable('2026-03-11T00:00:00Z'), $this->app->make(CalendarRegistry::class));
    }

    public function test_default_skips_a_wall_time_that_does_not_exist(): void
    {
        self::assertSame('skip', RecurrenceRule::parse(['frequency' => 'daily'])['nonexistent_time']);
        self::assertSame(['2026-03-07 02:30:00', '2026-03-09 02:30:00', '2026-03-10 02:30:00'], array_column($this->expand([]), 'local_at'));
    }

    public function test_shift_forward_keeps_the_day_and_reads_the_wall_time_with_the_offset_before_the_gap(): void
    {
        $items = $this->expand(['nonexistent_time' => 'shift_forward']);
        self::assertSame(['2026-03-07 02:30:00', '2026-03-08 02:30:00', '2026-03-09 02:30:00', '2026-03-10 02:30:00'], array_column($items, 'local_at'));
        // 02:30 EST = 07:30 UTC = 03:30 EDT on the gap day; the following days are ordinary EDT times.
        self::assertSame(['2026-03-07 07:30:00.000000', '2026-03-08 07:30:00.000000', '2026-03-09 06:30:00.000000', '2026-03-10 06:30:00.000000'], array_column($items, 'scheduled_at'));
    }

    public function test_unknown_value_is_rejected(): void
    {
        $this->error('recurrence_rule_invalid', fn () => RecurrenceRule::parse(['frequency' => 'daily', 'nonexistent_time' => 'later']));
    }

    public function test_generated_task_gets_the_shifted_due_time_once(): void
    {
        $task = $this->todo('gap');
        $series = $this->recurrence()->create($this->manager(), $task['public_key'], 1, 'gregorian', '2026-03-07 02:30:00', 'America/New_York', ['frequency' => 'daily', 'dst_policy' => 'earliest', 'nonexistent_time' => 'shift_forward', 'count' => 3], 'series');
        $from = new \DateTimeImmutable('2026-03-07T12:00:00Z');
        $made = $this->recurrence()->generate($this->manager(), $series['series_key'], 1, $from, $from->modify('+5 days'), 'generate');
        self::assertCount(2, $made['created']);
        $gap = $this->service()->get($this->manager(), $made['created'][0]);
        self::assertSame('2026-03-08 07:30:00', substr((string) $gap['due_at'], 0, 19));
        self::assertSame([], $this->recurrence()->generate($this->manager(), $series['series_key'], 1, $from, $from->modify('+5 days'), 'again')['created']);
        self::assertSame(2, DB::table('tm_recurrence_occurrences')->count());
    }
}
