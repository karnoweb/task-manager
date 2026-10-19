<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\OutboxDispatcher;
use Karnoweb\TaskManager\Tests\PackageTestCase;

final class TM076Test extends PackageTestCase
{
    public function test_scenario(): void
    {
        $s = $this->service(); $c = $this->context();
        $t = $s->create($c, 'کار', 'create'); $s->mutate($c, $t['public_key'], 1, 'first', 'update', ['priority' => 'high']); $this->error('version_conflict', fn () => $s->mutate($c, $t['public_key'], 1, 'second', 'update', ['priority' => 'low'])); self::assertSame('high', $s->get($c, $t['public_key'])['priority']); self::assertSame(2, DB::table('tm_outbox')->count());
    }
}

