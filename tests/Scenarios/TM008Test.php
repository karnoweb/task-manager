<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\OutboxDispatcher;
use Karnoweb\TaskManager\Tests\PackageTestCase;

final class TM008Test extends PackageTestCase
{
    public function test_scenario(): void
    {
        $s = $this->service(); $c = $this->context();
        $t = $s->create($c, 'کار', 'create'); $s->share($c, $t['public_key'], 1, 'share', 'b'); $copy = $s->cloneTask($c, $t['public_key'], 'clone'); self::assertNotSame($t['public_key'], $copy['public_key']); self::assertSame('کار', $copy['title']); self::assertSame('pending', $copy['state']); self::assertSame(0, DB::table('tm_task_grants')->where('task_id', $copy['id'])->count()); self::assertCount(1, $s->activity($c, $copy['public_key']));
    }
}

