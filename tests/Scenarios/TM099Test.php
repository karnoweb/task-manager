<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Karnoweb\TaskManager\Tests\PackageTestCase;

final class TM099Test extends PackageTestCase
{
    public function test_scenario(): void
    {
        $s = $this->service();
        $c = $this->context();
        $a = $s->create($c, 'الف', 'a');
        $b = $s->create($c, 'ب', 'b');
        self::assertNotSame($a['public_key'], $b['public_key']);
        self::assertTrue(Str::isUuid($a['public_key']));
        self::assertNull($a['project_id']);
        self::assertNull($b['project_id']);
        self::assertSame(2, DB::table('tm_tasks')->distinct()->count('public_key'));
    }
}
