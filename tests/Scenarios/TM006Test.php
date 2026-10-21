<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Karnoweb\TaskManager\Tests\PackageTestCase;

final class TM006Test extends PackageTestCase
{
    public function test_scenario(): void
    {
        $s = $this->service();
        $c = $this->context();
        $t = $s->create($c, 'کار', 'create');
        $s->mutate($c, $t['public_key'], 1, 'archive', 'archive');
        self::assertSame(0, $s->count($c));
        $s->mutate($c, $t['public_key'], 2, 'restore', 'restore');
        self::assertSame(1, $s->count($c));
        self::assertSame($t['public_key'], $s->get($c, $t['public_key'])['public_key']);
    }
}
