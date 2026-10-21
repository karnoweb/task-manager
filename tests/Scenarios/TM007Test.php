<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\PackageTestCase;

final class TM007Test extends PackageTestCase
{
    public function test_scenario(): void
    {
        $s = $this->service();
        $c = $this->context();
        $t = $s->create($c, 'کار', 'create');
        $s->mutate($c, $t['public_key'], 1, 'delete', 'delete');
        self::assertSame(0, $s->count($c));
        self::assertSame(1, DB::table('tm_workflow_instances')->count());
        $s->mutate($c, $t['public_key'], 2, 'restore', 'undelete');
        self::assertSame(1, $s->count($c));
        self::assertSame(3, DB::table('tm_audit_events')->count());
    }
}
