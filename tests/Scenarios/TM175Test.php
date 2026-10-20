<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Tests\HandoffTestCase;
use Karnoweb\TaskManager\Tests\Support\ProcessBarrier;

final class TM175Test extends HandoffTestCase
{
    public function test_tm175(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') { $this->markTestSkipped('Requires real PostgreSQL handoff concurrency.'); }
        [$origin, $destination, $r] = $this->handoffFixture(false);
        $results = ProcessBarrier::run([
            ['delegate', $origin['instance_key'], $destination['pipeline_key'], $r['id'], 'delegate-one'],
            ['delegate', $origin['instance_key'], $destination['pipeline_key'], $r['id'], 'delegate-two'],
        ], 'concurrent-handoff.php');
        self::assertCount(1, array_filter($results, fn ($result) => ($result['error'] ?? null) === 'version_conflict'));
        self::assertSame(1, DB::table('tm_handoffs')->count()); self::assertSame(2, DB::table('tm_workflow_instances')->count());
        self::assertSame(1, DB::table('tm_outbox')->where('action', 'handoff_created')->count());
    }
}

