<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\PhaseFiveTestCase;

final class TM120Test extends PhaseFiveTestCase
{
    public function test_scenario(): void
    {
        $task = $this->todo('master');
        $series = $this->recurrence()->create($this->manager(), $task['public_key'], 1, 'jalali', '2026-10-21 09:00:00', 'Asia/Tehran', ['frequency' => 'daily', 'count' => 3], 'series');
        $from = new \DateTimeImmutable('2026-10-21');
        $until = $from->modify('+5 days');
        $created = $this->recurrence()->generate($this->manager(), $series['series_key'], 1, $from, $until, 'generate');
        $first = $this->service()->get($this->manager(), $created['created'][0]);
        $this->service()->mutate($this->manager(), $first['public_key'], $first['version'], 'complete', 'complete');
        $row = DB::table('tm_recurrence_occurrences')->where('task_id', $first['id'])->first();
        $second = $this->service()->get($this->manager(), $created['created'][1]);
        $secondRow = DB::table('tm_recurrence_occurrences')->where('task_id', $second['id'])->first();
        $this->recurrence()->exception($this->manager(), $series['series_key'], 1, $secondRow->occurrence_key, $second['version'], new \DateTimeImmutable('2026-10-22T08:00:00Z'), false, 'exception');
        self::assertSame(1, DB::table('tm_recurrence_exceptions')->count());
        self::assertSame(3, $this->recurrence()->get($this->manager(), $series['series_key'])['rule']['count']);
        $this->recurrence()->changeRule($this->manager(), $series['series_key'], 2, ['frequency' => 'daily', 'count' => 5], 'rule-change');
        self::assertSame('done', $this->service()->get($this->manager(), $first['public_key'])['state']);
        self::assertNull($this->service()->get($this->manager(), $first['public_key'])['deleted_at']);
        self::assertEquals((array) $row, (array) DB::table('tm_recurrence_occurrences')->where('task_id', $first['id'])->first());
        $this->recurrence()->generate($this->manager(), $series['series_key'], 3, $from, $until, 'regenerate');
        self::assertSame(5, DB::table('tm_recurrence_occurrences')->count());
        self::assertSame(5, DB::table('tm_recurrence_occurrences')->where('state', 'active')->count());
        $this->recurrence()->changeRule($this->manager(), $series['series_key'], 3, ['frequency' => 'daily', 'count' => 5], 'stop', true);
        self::assertSame([],$this->recurrence()->generate($this->manager(),$series['series_key'],4,$from,$until,'stopped')['created']);
    }
}
