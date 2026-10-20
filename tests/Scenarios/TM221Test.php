<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Tests\GroupTestCase;
use Karnoweb\TaskManager\Tests\Support\ProcessBarrier;

final class TM221Test extends GroupTestCase
{
    public function test_tm221(): void
    {
        [$group, $instances, $handoffs] = $this->campaignHundred(true); $this->clock->advance('+60 seconds');
        self::assertSame(12, $this->sla()->run($this->manager(), 100)['events']); $this->closeCampaign();
        $preview = $this->groupService()->previewClosure($this->manager(), $group['group_key']);
        self::assertSame(100, $preview['total']); self::assertSame(['failed' => 75, 'continued' => 15, 'already_terminal' => 10, 'manual_review' => 0, 'denied' => 0], $preview['counts']);
        $run = $this->groupService()->executeClosure($this->manager(), $preview, 'campaign-close');
        $report = $this->processAll($run['run_key'], 20);
        self::assertSame(['already_terminal' => 10, 'continued' => 15, 'failed' => 75], $report['counts']);
        self::assertSame(75, DB::table('tm_group_operation_items')->where('result', 'failed')->where('reason_code', 'campaign_ended')->count());
        self::assertSame(100, DB::table('tm_outbox')->where('action', 'group_item_processed')->count());
        foreach ($handoffs as $handoff) {
            self::assertSame('active', $this->handoffs()->get($this->manager(), $handoff['handoff_key'])['status']);
            self::assertSame('waiting', DB::table('tm_workflow_instances')->where('id', $handoff['origin_id'])->value('lifecycle'));
            self::assertSame('active', DB::table('tm_workflow_instances')->where('id', $handoff['destination_id'])->value('lifecycle'));
            self::assertSame('paused', $this->sla()->get($this->manager(), $handoff['paused_visit_id'])['sla_state']);
        }
        $events = DB::table('tm_outbox')->count();
        self::assertSame(['processed' => 0, 'state' => 'complete'], $this->groupService()->process($this->manager(), $run['run_key']));
        self::assertSame($run, $this->groupService()->executeClosure($this->manager(), $preview, 'campaign-close'));
        self::assertSame($events, DB::table('tm_outbox')->count());
    }
}
