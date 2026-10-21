<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\Support\ProcessBarrier;

final class HandoffConcurrencyTest extends HandoffTestCase
{
    public function test_concurrent_delegations_reserve_only_one_destination(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires real PostgreSQL handoff concurrency.');
        }
        [$origin, $destination, $r] = $this->handoffFixture(false);
        $results = ProcessBarrier::run([
            ['delegate', $origin['instance_key'], $destination['pipeline_key'], $r['id'], 'delegate-one'],
            ['delegate', $origin['instance_key'], $destination['pipeline_key'], $r['id'], 'delegate-two'],
        ], 'concurrent-handoff.php');
        self::assertCount(1, array_filter($results, fn ($result) => ($result['error'] ?? null) === 'version_conflict'));
        self::assertSame(1, DB::table('tm_handoffs')->count());
        self::assertSame(2, DB::table('tm_workflow_instances')->count());
        self::assertSame(1, DB::table('tm_outbox')->where('action', 'handoff_created')->count());
    }

    public function test_concurrent_returns_commit_one_outcome_and_one_origin_transition(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires real PostgreSQL handoff concurrency.');
        }
        [$h, $origin, $destination] = $this->delegateFixture(false);
        $this->instances()->transition($this->manager(), $destination['instance_key'], 1, 'accept', 'accept');
        $results = ProcessBarrier::run([['settle', $h['handoff_key'], 'return-one'], ['settle', $h['handoff_key'], 'return-two']], 'concurrent-handoff.php');
        self::assertGreaterThanOrEqual(1, count(array_filter($results, fn ($result) => ($result['status'] ?? null) === 'resolved')));
        foreach ($results as $result) {
            self::assertTrue(($result['status'] ?? null) === 'resolved' || ($result['error'] ?? null) === 'version_conflict');
        }
        self::assertSame('resolved', $this->handoffs()->settle($this->manager(), $h['handoff_key'], 2, 'retry-return')['status']);
        self::assertSame(1, DB::table('tm_handoff_outcomes')->count());
        self::assertSame(3, $this->instances()->get($this->manager(), $origin['instance_key'])['version']);
        self::assertSame(1, DB::table('tm_outbox')->where('action', 'handoff_returned')->count());
    }
}
