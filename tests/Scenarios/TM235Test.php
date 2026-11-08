<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Tests\GroupTestCase;

final class TM235Test extends GroupTestCase
{
    public function test_tm235(): void
    {
        [$group] = $this->campaignFixture();
        $this->closeCampaign();
        $preview = $this->groupService()->previewClosure($this->manager(), $group['group_key']);
        $run = $this->groupService()->executeClosure($this->manager(), $preview, 'same-key');
        self::assertSame($run, $this->groupService()->executeClosure($this->manager(), $preview, 'same-key'));
        $preview['counts']['failed'] = 999;
        try {
            $this->groupService()->executeClosure($this->manager(), $preview, 'same-key');
            self::fail();
        } catch (DomainError $error) {
            self::assertSame('idempotency_key_conflict', $error->errorCode);
        }
        self::assertSame(1, DB::table('tm_group_operations')->count());
    }
}
