<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Tests\GroupTestCase;
use Karnoweb\TaskManager\Tests\Support\ProcessBarrier;

final class TM236Test extends GroupTestCase
{
    public function test_tm236(): void
    {
        [$group] = $this->campaignFixture(3); $this->closeCampaign();
        $first = $this->groupService()->executeClosure($this->manager(), $this->groupService()->previewClosure($this->manager(), $group['group_key']), 'first'); $this->processAll($first['run_key']);
        $transitions = DB::table('tm_instance_transitions')->count(); $preview = $this->groupService()->previewClosure($this->manager(), $group['group_key']);
        self::assertSame(3, $preview['counts']['already_terminal']); $second = $this->groupService()->executeClosure($this->manager(), $preview, 'second');
        self::assertSame(['already_terminal' => 3], $this->processAll($second['run_key'])['counts']); self::assertSame($transitions, DB::table('tm_instance_transitions')->count());
        self::assertSame(2, DB::table('tm_outbox')->where('action', 'group_closure_completed')->count());
        self::assertSame(6, DB::table('tm_outbox')->where('action', 'group_item_processed')->count());
    }
}
