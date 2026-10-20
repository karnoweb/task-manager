<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Tests\GroupTestCase;
use Karnoweb\TaskManager\Tests\Support\ProcessBarrier;

final class TM232Test extends GroupTestCase
{
    public function test_tm232(): void
    {
        [$group, $instances] = $this->campaignFixture(2, true); $this->clock->advance('+60 seconds');
        self::assertSame(2, $this->sla()->run($this->manager())['events']);
        foreach ($instances as $instance) { self::assertSame('new', $this->instances()->get($this->manager(), $instance['instance_key'])['current_state_key']); }
        self::assertSame(0, DB::table('tm_instance_transitions')->count()); $this->closeCampaign();
        $run = $this->groupService()->executeClosure($this->manager(), $this->groupService()->previewClosure($this->manager(), $group['group_key']), 'close');
        self::assertSame(['failed' => 2], $this->processAll($run['run_key'])['counts']);
        self::assertSame(2, DB::table('tm_group_operation_items')->where('reason_code', 'campaign_ended')->count());
    }
}
