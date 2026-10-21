<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\SlaTestCase;
use Karnoweb\TaskManager\Tests\Support\ProcessBarrier;

final class TM203Test extends SlaTestCase
{
    public function test_scenario(): void
    {
        $c = $this->manager();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires real PostgreSQL watchdog concurrency.');
        } [$i,$v] = $this->instanceWithSla(['duration_seconds' => 60]);
        $this->clock->advance('+60 seconds');
        $time = $this->clock->now()->format(DATE_ATOM);
        $before = DB::table('tm_outbox')->count();
        $results = ProcessBarrier::run([[$time], [$time]], 'concurrent-sla.php');
        self::assertSame(1, array_sum(array_column($results, 'events')));
        self::assertSame(1, DB::table('tm_sla_events')->count());
        self::assertSame($before + 1, DB::table('tm_outbox')->count());
        self::assertSame(1, DB::table('tm_outbox')->where('action', 'sla_breach')->count());
        self::assertSame('new', $this->instances()->get($c, $i['instance_key'])['current_state_key']);
    }
}
