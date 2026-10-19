<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\OutboxDispatcher;
use Karnoweb\TaskManager\Tests\PackageTestCase;

final class TM153Test extends PackageTestCase
{
    public function test_scenario(): void
    {
        $s = $this->service(); $c = $this->context();
        $t = $s->create($c, 'کار', 'same'); self::assertSame($t, $s->create($c, 'کار', 'same')); $instance = DB::table('tm_workflow_instances')->first(); self::assertSame($t['id'], $instance->task_id); self::assertSame('personal', $instance->workflow_key); self::assertSame('pending', $instance->current_state_key); self::assertSame(1, DB::table('tm_workflow_instances')->count());
    }
}

