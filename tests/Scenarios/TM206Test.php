<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Tests\GroupTestCase;
use Karnoweb\TaskManager\Tests\Support\ProcessBarrier;

final class TM206Test extends GroupTestCase
{
    public function test_tm206(): void
    {
        [$group, $instances] = $this->campaignFixture(100); $this->closeCampaign();
        $run = $this->groupService()->executeClosure($this->manager(), $this->groupService()->previewClosure($this->manager(), $group['group_key']), 'close');
        DB::table('host_permissions')->where('subject_id', $instances[0]['subject_id'])->update(['can_edit' => false]);
        $report = $this->processAll($run['run_key']); self::assertSame(['denied' => 1, 'failed' => 99], $report['counts']);
        self::assertSame('new', $this->instances()->get($this->manager(), $instances[0]['instance_key'])['current_state_key']);
        self::assertSame(99, DB::table('tm_instance_transitions')->where('to_state', 'failed')->count());
        self::assertSame(1, DB::table('tm_group_operation_items')->where('result', 'denied')->where('error_code', 'not_found')->count());
    }
}
