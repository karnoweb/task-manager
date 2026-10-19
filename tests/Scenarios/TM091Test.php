<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\OutboxDispatcher;
use Karnoweb\TaskManager\Tests\PackageTestCase;

final class TM091Test extends PackageTestCase
{
    public function test_scenario(): void
    {
        $s = $this->service(); $c = $this->context();
        $first = $s->create($c, 'اول', 'first'); $second = $s->create($c, 'دوم', 'second'); self::assertSame($first['list_id'], $second['list_id']); self::assertCount(1, $s->lists($c)); self::assertSame('Inbox', $s->lists($c)[0]['name']); self::assertSame([], $s->lists($this->context('b'))); self::assertSame(0, $s->count($this->context('b')));
    }
}

