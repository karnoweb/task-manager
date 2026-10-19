<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\OutboxDispatcher;
use Karnoweb\TaskManager\Tests\PackageTestCase;

final class TM092Test extends PackageTestCase
{
    public function test_scenario(): void
    {
        $s = $this->service(); $c = $this->context();
        $t = $s->create($c, 'کار', 'create'); $list = $s->createList($c, 'Work', 'list'); $moved = $s->mutate($c, $t['public_key'], 1, 'move', 'move_list', ['list_id' => $list['list_id']]); self::assertSame($list['list_id'], $moved['list_id']); self::assertEquals($t['rank'], $moved['rank']); self::assertSame($t['public_key'], $moved['public_key']); self::assertSame(2, $moved['version']);
    }
}

