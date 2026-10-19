<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\OutboxDispatcher;
use Karnoweb\TaskManager\Tests\PackageTestCase;

final class TM003Test extends PackageTestCase
{
    public function test_scenario(): void
    {
        $s = $this->service(); $c = $this->context();
        $t = $s->create($c, 'کار', 'create'); $done = $s->mutate($c, $t['public_key'], 1, 'done', 'complete'); self::assertSame('done', $done['state']); self::assertNotNull($done['completed_at']); $open = $s->mutate($c, $t['public_key'], 2, 'open', 'reopen'); self::assertSame('pending', $open['state']); self::assertNull($open['completed_at']); self::assertSame(2, DB::table('tm_instance_transitions')->count());
    }
}

