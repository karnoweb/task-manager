<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\GroupTestCase;

final class TM229Test extends GroupTestCase
{
    public function test_tm229(): void
    {
        [$group] = $this->campaignFixture(3);
        $this->closeCampaign();
        $run = $this->groupService()->executeClosure($this->manager(), $this->groupService()->previewClosure($this->manager(), $group['group_key']), 'close');
        $this->processAll($run['run_key']);
        $one = $this->groupService()->report($this->manager(), $run['run_key'], 2);
        $two = $this->groupService()->report($this->manager(), $run['run_key'], 2, $one['next_after_id']);
        self::assertCount(2, $one['items']);
        self::assertCount(1, $two['items']);
        foreach ([...$one['items'], ...$two['items']] as $item) {
            self::assertSame('campaign_ended', $item['reason_code']);
            self::assertStringStartsWith('group-item:'.$run['run_key'], $item['operation_id']);
            self::assertSame(1, DB::table('tm_audit_events')->where('action', 'group_item_processed')->where('operation_id', $item['operation_id'])->count());
        }
        self::assertStringNotContainsString('PRIVATE-HOST-TEXT', json_encode([$one, $two]));
        DB::table('host_permissions')->delete();
        self::assertSame([], $this->groupService()->report($this->manager(), $run['run_key'])['items']);
        self::assertSame([], $this->groupService()->report($this->manager(), $run['run_key'])['counts']);
    }
}
