<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Planning\TimeTrackingService;
use Karnoweb\TaskManager\Tests\PhaseFourTestCase;

final class TM111Test extends PhaseFourTestCase
{
    public function test_scenario(): void
    {
        $task = $this->todo('task');
        $start = new \DateTimeImmutable('2026-10-19T22:00:00Z');
        $entry = $this->time()->log($this->manager(), $task['public_key'], $start, $start->modify('+10 minutes'), null, 'log');
        self::assertSame('projected', $this->time()->rebuild($this->manager(), $task['public_key'], 'rebuild')['state']);
        $this->time()->amend($this->manager(), $entry['public_key'], 1, $start, $start->modify('+20 minutes'), null, false, 'amend');
        self::assertSame('stale', $this->time()->stats($this->manager(), $task['public_key'], false)['state']);
        self::assertSame(1200, $this->time()->stats($this->manager(), $task['public_key'])['spent_seconds']);
        self::assertSame(1200, $this->time()->rebuild($this->manager(), $task['public_key'], 'rebuild-again')['spent_seconds']);
        self::assertSame(1, DB::table('tm_task_stats')->count());
    }

    private function time(): TimeTrackingService
    {
        return $this->app->make(TimeTrackingService::class);
    }
}
