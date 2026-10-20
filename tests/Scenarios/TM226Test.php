<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Tests\GroupTestCase;
use Karnoweb\TaskManager\Tests\Support\ProcessBarrier;

final class TM226Test extends GroupTestCase
{
    public function test_tm226(): void
    {
        [$group, $instances] = $this->campaignFixture();
        try { $this->groupService()->previewClosure($this->manager(), $group['group_key']); self::fail(); }
        catch (DomainError $error) { self::assertSame('group_source_invalid', $error->getMessage()); }
        self::assertSame(0, DB::table('tm_group_operations')->count()); self::assertSame(0, DB::table('tm_group_operation_items')->count());
        self::assertSame('new', $this->instances()->get($this->manager(), $instances[0]['instance_key'])['current_state_key']);
    }
}
