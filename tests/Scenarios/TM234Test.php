<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\GroupTestCase;

final class TM234Test extends GroupTestCase
{
    public function test_tm234(): void
    {
        [$group, $instances] = $this->campaignFixture(3);
        $this->closeCampaign();
        $run = $this->groupService()->executeClosure($this->manager(), $this->groupService()->previewClosure($this->manager(), $group['group_key']), 'close');
        DB::table('host_subjects')->where('id', $instances[0]['subject_id'])->update(['veto' => true]);
        self::assertSame(['failed' => 2, 'manual_review' => 1], $this->processAll($run['run_key'])['counts']);
        self::assertSame('new', $this->instances()->get($this->manager(), $instances[0]['instance_key'])['current_state_key']);
        self::assertSame(0, DB::table('host_subjects')->where('id', $instances[0]['subject_id'])->value('guard_touches'));
        self::assertSame('host_guard_veto', DB::table('tm_group_operation_items')->where('result', 'manual_review')->value('error_code'));
    }
}
