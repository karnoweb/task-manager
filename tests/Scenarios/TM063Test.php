<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\PhaseFiveTestCase;

final class TM063Test extends PhaseFiveTestCase
{
    public function test_scenario(): void
    {
        $task = $this->todo('Until');
        $series = $this->recurrence()->create($this->manager(), $task['public_key'], 1, 'gregorian', '2026-10-20 09:00:00', 'UTC', 'FREQ=DAILY;UNTIL=20261022T090000Z', 'series');
        $from = new \DateTimeImmutable('2026-10-20');
        $until = $from->modify('+10 days');
        $made = $this->recurrence()->generate($this->manager(), $series['series_key'], 1, $from, $until, 'generate');
        self::assertCount(3, $made['created']);
        $last = new \DateTimeImmutable(DB::table('tm_recurrence_occurrences')->max('scheduled_at'), new \DateTimeZone('UTC'));
        self::assertSame('2026-10-22 09:00:00.000000', $last->format('Y-m-d H:i:s.u'));
        $this->recurrence()->changeRule($this->manager(), $series['series_key'], 1, 'FREQ=DAILY;UNTIL=20261022T090000Z', 'stop', true);
        self::assertSame([], $this->recurrence()->generate($this->manager(), $series['series_key'], 2, $from, $until, 'stopped')['created']);
    }
}
