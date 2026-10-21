<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\PhaseFiveTestCase;

final class TM060Test extends PhaseFiveTestCase
{
    public function test_scenario(): void
    {
        $task = $this->todo('daily');
        $series = $this->recurrence()->create($this->manager(), $task['public_key'], 1, 'gregorian', '2026-10-20 09:00:00', 'Asia/Tehran', 'FREQ=DAILY;COUNT=3', 'series');
        $from = new \DateTimeImmutable('2026-10-20T00:00:00Z');
        $until = $from->modify('+10 days');
        self::assertCount(3, $this->recurrence()->preview($this->manager(), $series['series_key'], $from, $until));
        self::assertSame(1, DB::table('tm_tasks')->count());
        $made = $this->recurrence()->generate($this->manager(), $series['series_key'], 1, $from, $until, 'generate');
        self::assertCount(3, $made['created']);
        self::assertSame($made, $this->recurrence()->generate($this->manager(), $series['series_key'], 1, $from, $until, 'generate'));
        self::assertSame([], $this->recurrence()->generate($this->manager(), $series['series_key'], 1, $from, $until, 'other-job')['created']);
        self::assertSame(3, DB::table('tm_recurrence_occurrences')->count());
    }
}
