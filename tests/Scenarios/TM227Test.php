<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\GroupTestCase;

final class TM227Test extends GroupTestCase
{
    public function test_tm227(): void
    {
        [$group, $instances] = $this->campaignFixture();
        $this->closeCampaign();
        $events = DB::table('tm_outbox')->count();
        $preview = $this->groupService()->previewClosure($this->manager(), $group['group_key']);
        self::assertSame($group['policy_id'], $preview['policy_id']);
        self::assertSame(1, $preview['policy_revision']);
        self::assertSame($events, DB::table('tm_outbox')->count());
        self::assertSame(0, DB::table('tm_group_operations')->count());
        $this->instances()->transition($this->manager(), $instances[0]['instance_key'], 1, 'update-stage', 'enter_reservation');
        $events = DB::table('tm_outbox')->count();
        $delta = $this->groupService()->previewDelta($this->manager(), $preview);
        self::assertTrue($delta['changed']);
        self::assertNotSame($preview['member_hash'], $delta['preview']['member_hash']);
        self::assertSame($events, DB::table('tm_outbox')->count());
        $this->expectExceptionMessage('preview_stale');
        $this->groupService()->executeClosure($this->manager(), $preview, 'stale');
    }
}
