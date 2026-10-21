<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\GroupTestCase;

final class TM222Test extends GroupTestCase
{
    public function test_tm222(): void
    {
        [$group, $instances] = $this->campaignFixture();
        $this->instances()->transition($this->manager(), $instances[0]['instance_key'], 1, 'enter-stage', 'enter_reservation');
        $this->closeCampaign();
        self::assertSame(0, DB::table('tm_milestone_intents')->count());
        $preview = $this->groupService()->previewClosure($this->manager(), $group['group_key']);
        self::assertSame(1, $preview['counts']['failed']);
        $run = $this->groupService()->executeClosure($this->manager(), $preview, 'close');
        self::assertSame(['failed' => 1], $this->processAll($run['run_key'])['counts']);
    }
}
