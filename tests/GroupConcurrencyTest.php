<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\Support\ProcessBarrier;

final class GroupConcurrencyTest extends GroupTestCase
{
    public function test_two_workers_claim_distinct_items_and_commit_one_action_per_member(): void
    {
        if (!in_array(DB::connection()->getDriverName(), ['pgsql', 'mariadb'], true)) { $this->markTestSkipped('Requires real PostgreSQL or MariaDB group worker row locks.'); }
        [$group] = $this->campaignFixture(100); $this->closeCampaign();
        $run = $this->groupService()->executeClosure($this->manager(), $this->groupService()->previewClosure($this->manager(), $group['group_key']), 'close');
        $results = ProcessBarrier::run([[$run['run_key'], 50], [$run['run_key'], 50]], 'concurrent-group.php');
        self::assertSame([50, 50], array_column($results, 'processed'));
        self::assertSame(['failed' => 100], $this->groupService()->report($this->manager(), $run['run_key'])['counts']);
        self::assertSame(100, DB::table('tm_group_operation_items')->where('attempts', 1)->where('state', 'done')->count());
        self::assertSame(100, DB::table('tm_instance_transitions')->where('to_state', 'failed')->count());
        self::assertSame(100, DB::table('tm_outbox')->where('action', 'group_item_processed')->count());
        self::assertSame(1, DB::table('tm_outbox')->where('action', 'group_closure_completed')->count());
    }
    public function test_process_crash_after_40_commits_and_durable_claim_resumes_60_after_lease_expiry(): void
    {
        if (!in_array(DB::connection()->getDriverName(), ['pgsql', 'mariadb'], true)) { $this->markTestSkipped('Requires real process termination and durable PostgreSQL or MariaDB leases.'); }
        [$group, $instances, $handoffs] = $this->campaignHundred(); $this->closeCampaign();
        $run = $this->groupService()->executeClosure($this->manager(), $this->groupService()->previewClosure($this->manager(), $group['group_key']), 'close');
        try { ProcessBarrier::run([[$run['run_key'], 'crash']], 'concurrent-group.php'); self::fail('Worker should terminate after a durable claim.'); }
        catch (\RuntimeException $error) { self::assertStringContainsString('injected_crash_after_40_and_durable_claim', $error->getMessage()); }
        self::assertSame(40, DB::table('tm_group_operation_items')->where('state', 'done')->count());
        self::assertSame(60, DB::table('tm_group_operation_items')->where('state', 'processing')->whereNotNull('lease_token')->count());
        self::assertSame(['processed' => 0, 'state' => 'processing'], $this->groupService()->process($this->manager(), $run['run_key'], 100));
        $this->clock->advance('+121 seconds'); $report = $this->processAll($run['run_key'], 20);
        self::assertSame(['already_terminal' => 10, 'continued' => 15, 'failed' => 75], $report['counts']);
        self::assertSame(100, DB::table('tm_outbox')->where('action', 'group_item_processed')->count());
        self::assertSame(40, DB::table('tm_group_operation_items')->where('attempts', 1)->count());
        self::assertSame(60, DB::table('tm_group_operation_items')->where('attempts', 2)->count());
        self::assertSame(5, DB::table('tm_handoffs')->where('status', 'active')->count());
    }
}
