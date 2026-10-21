<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\Support\ProcessBarrier;

final class ApprovalConcurrencyTest extends ApprovalTestCase
{
    public function test_parallel_votes_have_explicit_conflict_retry_and_one_completion(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires real PostgreSQL vote concurrency.');
        }
        [$i, $step] = $this->instanceWithApproval();
        $results = ProcessBarrier::run([['b', $step['step_key']], ['c', $step['step_key']]], 'concurrent-approval.php');
        $failed = array_values(array_filter($results, fn ($r) => ($r['error'] ?? '') === 'version_conflict'));
        self::assertCount(1, $failed);
        self::assertSame(1, DB::table('tm_approval_votes')->count());
        $resolved = $this->approvals()->vote($this->manager($failed[0]['actor']), $step['step_key'], 2, 2, 'retry-'.$failed[0]['actor'], 'approve');
        self::assertSame('approved', $resolved['state']);
        self::assertSame('executed', $resolved['execution_state']);
        self::assertSame('won', $this->instances()->get($this->manager(), $i['instance_key'])['current_state_key']);
        self::assertSame(2, DB::table('tm_approval_votes')->count());
        self::assertSame(1, DB::table('tm_outbox')->where('action', 'approval_decided')->count());
    }
}
