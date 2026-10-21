<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\GroupTestCase;
use Karnoweb\TaskManager\Tests\Support\ProcessBarrier;

final class TM231Test extends GroupTestCase
{
    public function test_tm231(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires real process termination and durable PostgreSQL leases.');
        }
        [$group, $instances, $handoffs] = $this->campaignHundred();
        $this->closeCampaign();
        $run = $this->groupService()->executeClosure($this->manager(), $this->groupService()->previewClosure($this->manager(), $group['group_key']), 'close');
        try {
            ProcessBarrier::run([[$run['run_key'], 'crash']], 'concurrent-group.php');
            self::fail('Worker should terminate after a durable claim.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('injected_crash_after_40_and_durable_claim', $error->getMessage());
        }
        self::assertSame(40, DB::table('tm_group_operation_items')->where('state', 'done')->count());
        self::assertSame(60, DB::table('tm_group_operation_items')->where('state', 'processing')->whereNotNull('lease_token')->count());
        self::assertSame(['processed' => 0, 'state' => 'processing'], $this->groupService()->process($this->manager(), $run['run_key'], 100));
        $this->clock->advance('+121 seconds');
        $report = $this->processAll($run['run_key'], 20);
        self::assertSame(['already_terminal' => 10, 'continued' => 15, 'failed' => 75], $report['counts']);
        self::assertSame(100, DB::table('tm_outbox')->where('action', 'group_item_processed')->count());
        self::assertSame(40, DB::table('tm_group_operation_items')->where('attempts', 1)->count());
        self::assertSame(60, DB::table('tm_group_operation_items')->where('attempts', 2)->count());
        self::assertSame(5, DB::table('tm_handoffs')->where('status', 'active')->count());
    }
}
