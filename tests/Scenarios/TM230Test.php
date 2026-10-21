<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\GroupTestCase;
use Karnoweb\TaskManager\Tests\Support\ProcessBarrier;

final class TM230Test extends GroupTestCase
{
    public function test_tm230(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires real PostgreSQL group worker row locks.');
        }
        [$group] = $this->campaignFixture(100);
        $this->closeCampaign();
        $run = $this->groupService()->executeClosure($this->manager(), $this->groupService()->previewClosure($this->manager(), $group['group_key']), 'close');
        $results = ProcessBarrier::run([[$run['run_key'], 50], [$run['run_key'], 50]], 'concurrent-group.php');
        self::assertSame([50, 50], array_column($results, 'processed'));
        self::assertSame(['failed' => 100], $this->groupService()->report($this->manager(), $run['run_key'])['counts']);
        self::assertSame(100, DB::table('tm_group_operation_items')->where('attempts', 1)->where('state', 'done')->count());
        self::assertSame(100, DB::table('tm_instance_transitions')->where('to_state', 'failed')->count());
        self::assertSame(100, DB::table('tm_outbox')->where('action', 'group_item_processed')->count());
        self::assertSame(1, DB::table('tm_outbox')->where('action', 'group_closure_completed')->count());
    }
}
