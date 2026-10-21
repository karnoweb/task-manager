<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\PackageTestCase;

final class TM011Test extends PackageTestCase
{
    public function test_scenario(): void
    {
        $s = $this->service();
        $c = $this->context();
        $a = $s->create($c, 'کار', 'create');
        self::assertSame($a, $s->create($c, 'کار', 'create'));
        self::assertSame(1, DB::table('tm_tasks')->count());
        self::assertSame(1, DB::table('tm_command_journal')->count());
        self::assertSame(1, DB::table('tm_outbox')->count());
    }
}
