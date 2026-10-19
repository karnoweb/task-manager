<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\OutboxDispatcher;
use Karnoweb\TaskManager\Tests\PackageTestCase;

final class TM005Test extends PackageTestCase
{
    public function test_scenario(): void
    {
        $s = $this->service(); $c = $this->context();
        $t = $s->create($c, 'اول', 'create'); $edited = $s->mutate($c, $t['public_key'], 1, 'edit', 'update', ['title' => 'دوم', 'priority' => 'high']); self::assertSame('دوم', $edited['title']); self::assertSame('high', $edited['priority']); self::assertSame(2, $edited['version']); self::assertCount(2, $s->activity($c, $t['public_key']));
    }
}

