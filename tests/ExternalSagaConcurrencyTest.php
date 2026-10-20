<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\Support\ProcessBarrier;

final class ExternalSagaConcurrencyTest extends RouteTestCase
{
    public function test_two_workers_use_one_remote_idempotency_key_and_one_local_confirmation(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') { $this->markTestSkipped('Requires PostgreSQL and a separate physical remote database.'); }
        [$origin] = $this->ticketFixture();
        $saga = $this->sagas()->begin($this->manager(), $origin['instance_key'], 1, 'ticket.remote-v1', 'refer', 'sales_opportunity_identified', 'saga-begin');
        $command = [$saga['saga_key'], $this->remoteRouter->remote->getDatabaseName()];
        $results = ProcessBarrier::run([$command, $command], 'concurrent-saga.php');
        foreach ($results as $result) { self::assertSame('confirmed', $result['state'] ?? $result['error'] ?? null); }
        self::assertSame(1, $this->remoteRouter->remote->table('remote_opportunities')->count());
        self::assertSame(1, DB::table('tm_external_case_subjects')->count());
        self::assertSame(1, DB::table('tm_outbox')->where('action', 'external_spawn_confirmed')->count());
        self::assertSame(2, $this->instances()->get($this->manager(), $origin['instance_key'])['version']);
        self::assertSame(0, DB::table('tm_active_external_sagas')->count());
    }
}
