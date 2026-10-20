<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Tests\GroupTestCase;
use Karnoweb\TaskManager\Tests\Support\ProcessBarrier;

final class TM225Test extends GroupTestCase
{
    public function test_tm225(): void
    {
        [$group, $instances] = $this->campaignFixture();
        $terminal = $this->instances()->transition($this->manager(), $instances[0]['instance_key'], 1, 'win', 'win'); $this->closeCampaign();
        $transitions = DB::table('tm_instance_transitions')->count();
        $run = $this->groupService()->executeClosure($this->manager(), $this->groupService()->previewClosure($this->manager(), $group['group_key']), 'close');
        self::assertSame(['already_terminal' => 1], $this->processAll($run['run_key'])['counts']); self::assertSame($transitions, DB::table('tm_instance_transitions')->count());
        self::assertSame($terminal['version'], $this->instances()->get($this->manager(), $instances[0]['instance_key'])['version']);
    }
}
