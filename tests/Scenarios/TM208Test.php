<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\OutboxDispatcher;
use Karnoweb\TaskManager\Tests\PackageTestCase;

final class TM208Test extends PackageTestCase
{
    public function test_scenario(): void
    {
        $s = $this->service(); $c = $this->context();
        $s->create($c, 'کار', 'same'); $before = DB::table('tm_tasks')->first(); $d = new OutboxDispatcher(DB::connection()); self::assertSame(1, $d->dispatch('one', fn ($e) => self::assertNotEmpty($e['event_id']))); self::assertSame(0, $d->dispatch('one', fn () => self::fail('Unexpected delivery'))); self::assertEquals($before, DB::table('tm_tasks')->first()); self::assertSame(1, DB::table('tm_command_journal')->count());
    }
}

