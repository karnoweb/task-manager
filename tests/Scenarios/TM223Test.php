<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Karnoweb\TaskManager\Tests\GroupTestCase;

final class TM223Test extends GroupTestCase
{
    public function test_tm223(): void
    {
        [$group, $instances] = $this->campaignFixture();
        $this->reserve($instances[0], 'one');
        $this->closeCampaign();
        $preview = $this->groupService()->previewClosure($this->manager(), $group['group_key']);
        self::assertSame(1, $preview['counts']['continued']);
        $run = $this->groupService()->executeClosure($this->manager(), $preview, 'close');
        self::assertSame(['continued' => 1], $this->processAll($run['run_key'])['counts']);
        self::assertSame('reservation', $this->instances()->get($this->manager(), $instances[0]['instance_key'])['current_state_key']);
    }
}
