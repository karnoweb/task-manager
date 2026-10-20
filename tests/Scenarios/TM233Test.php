<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Tests\GroupTestCase;
use Karnoweb\TaskManager\Tests\Support\ProcessBarrier;

final class TM233Test extends GroupTestCase
{
    public function test_tm233(): void
    {
        [$group, $instances, $pipeline, $contract] = $this->campaignFixture(1, true); $reserved = $this->reserve($instances[0], 'one');
        $handoff = $this->handoffs()->delegate($this->manager(), $reserved['instance_key'], 2, $contract['pipeline_key'], $reserved['workflow_revision_id'], 'contracts',
            ['reason_code' => 'contract_review', 'target_team' => 'contracts', 'returns' => ['approved' => ['transition' => 'contract_return']]], 'delegate');
        $this->closeCampaign(); $run = $this->groupService()->executeClosure($this->manager(), $this->groupService()->previewClosure($this->manager(), $group['group_key']), 'close');
        self::assertSame(['continued' => 1], $this->processAll($run['run_key'])['counts']);
        self::assertSame('active', $this->handoffs()->get($this->manager(), $handoff['handoff_key'])['status']);
        self::assertSame('waiting', $this->instances()->get($this->manager(), $reserved['instance_key'])['lifecycle']);
        self::assertSame('active', DB::table('tm_workflow_instances')->where('id', $handoff['destination_id'])->value('lifecycle'));
        self::assertSame('paused', $this->sla()->get($this->manager(), $handoff['paused_visit_id'])['sla_state']);
        self::assertSame(3, $this->instances()->get($this->manager(), $reserved['instance_key'])['version']);
    }
}
