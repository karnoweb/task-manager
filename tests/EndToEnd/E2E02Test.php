<?php

namespace Karnoweb\TaskManager\Tests\EndToEnd;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\GroupTestCase;
use Karnoweb\TaskManager\Tests\Support\ProcessBarrier;

final class E2E02Test extends GroupTestCase
{
    public function test_campaign_deadlines_cutoff_and_real_crash_recovery_preserve_exact_business_outcomes(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Full flow requires real worker termination and PostgreSQL durable leases.');
        }
        [$group, $instances, $handoffs] = $this->campaignHundred(true);
        $this->clock->advance('+60 seconds');
        self::assertSame(12, $this->sla()->run($this->manager())['events']);
        self::assertSame(0, DB::table('tm_instance_transitions')->where('to_state', 'failed')->count());
        $this->closeCampaign();
        $preview = $this->groupService()->previewClosure($this->manager(), $group['group_key']);
        self::assertSame(['failed' => 75, 'continued' => 15, 'already_terminal' => 10, 'manual_review' => 0, 'denied' => 0], $preview['counts']);
        $run = $this->groupService()->executeClosure($this->manager(), $preview, 'campaign-close');
        try {
            ProcessBarrier::run([[$run['run_key'], 'crash', $this->clock->now()->format(DATE_ATOM)]], 'concurrent-group.php');
            self::fail();
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('injected_crash_after_40_and_durable_claim', $error->getMessage());
        }
        self::assertSame(40, DB::table('tm_group_operation_items')->where('state', 'done')->count());
        self::assertSame(60, DB::table('tm_group_operation_items')->where('state', 'processing')->count());
        $this->clock->advance('+121 seconds');
        $report = $this->processAll($run['run_key'], 20);
        self::assertSame(['already_terminal' => 10, 'continued' => 15, 'failed' => 75], $report['counts']);
        self::assertSame(75, DB::table('tm_group_operation_items')->where('result', 'failed')->where('reason_code', 'campaign_ended')->count());
        self::assertSame(100, DB::table('tm_outbox')->where('action', 'group_item_processed')->count());
        foreach ($handoffs as $handoff) {
            self::assertSame('active', $this->handoffs()->get($this->manager(), $handoff['handoff_key'])['status']);
            self::assertSame('waiting', DB::table('tm_workflow_instances')->where('id', $handoff['origin_id'])->value('lifecycle'));
            self::assertSame('active', DB::table('tm_workflow_instances')->where('id', $handoff['destination_id'])->value('lifecycle'));
        }
        $events = DB::table('tm_outbox')->count();
        $this->groupService()->process($this->manager(), $run['run_key']);
        self::assertSame($run, $this->groupService()->executeClosure($this->manager(), $preview, 'campaign-close'));
        self::assertSame($events, DB::table('tm_outbox')->count());
    }
}
