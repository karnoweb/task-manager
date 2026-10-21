<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\PackageTestCase;

final class TM001Test extends PackageTestCase
{
    public function test_scenario(): void
    {
        $s = $this->service();
        $c = $this->context();
        $t = $s->create($c, 'تماس', 'create');
        self::assertNull($t['project_id']);
        self::assertSame('a', $t['owner_ref']);
        self::assertSame('pending', $t['state']);
        self::assertSame('personal', DB::table('tm_workflow_instances')->value('workflow_key'));
    }
}
