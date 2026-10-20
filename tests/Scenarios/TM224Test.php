<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Tests\GroupTestCase;
use Karnoweb\TaskManager\Tests\Support\ProcessBarrier;

final class TM224Test extends GroupTestCase
{
    public function test_tm224(): void
    {
        [$group, $instances] = $this->campaignFixture(); $this->closeCampaign(); $this->clock->advance('+1 second'); $this->reserve($instances[0], 'late');
        $preview = $this->groupService()->previewClosure($this->manager(), $group['group_key']); self::assertSame(1, $preview['counts']['failed']);
        $run = $this->groupService()->executeClosure($this->manager(), $preview, 'close'); self::assertSame(['failed' => 1], $this->processAll($run['run_key'])['counts']);
        self::assertSame('failed', $this->instances()->get($this->manager(), $instances[0]['instance_key'])['current_state_key']);
    }
}
