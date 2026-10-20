<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\Support\ProcessBarrier;

final class SlaConcurrencyTest extends SlaTestCase
{
    public function test_two_watchdogs_produce_one_event_and_one_notification_intent(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') { $this->markTestSkipped('Requires real PostgreSQL worker concurrency.'); }
        [$i, $v] = $this->instanceWithSla(['duration_seconds' => 60]); $this->clock->advance('+60 seconds');
        $events = DB::table('tm_outbox')->count(); $time = $this->clock->now()->format(DATE_ATOM);
        $results = ProcessBarrier::run([[$time], [$time]], 'concurrent-sla.php');
        self::assertSame(1, array_sum(array_column($results, 'events'))); self::assertSame(1, DB::table('tm_sla_events')->count());
        self::assertSame($events + 1, DB::table('tm_outbox')->count()); self::assertSame(1, DB::table('tm_outbox')->where('action', 'sla_breach')->count());
        self::assertSame('new', $this->instances()->get($this->manager(), $i['instance_key'])['current_state_key']);
    }
}
