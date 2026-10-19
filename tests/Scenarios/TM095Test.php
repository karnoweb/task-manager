<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\OutboxDispatcher;
use Karnoweb\TaskManager\Tests\PackageTestCase;

final class TM095Test extends PackageTestCase
{
    public function test_scenario(): void
    {
        $s = $this->service(); $c = $this->context();
        $t = $s->create($c, 'کار', 'create'); $b = $this->context('b'); $s->share($c, $t['public_key'], 1, 'share', 'b'); self::assertSame(1, $s->count($b)); $this->error('not_found', fn () => $s->mutate($b, $t['public_key'], 2, 'edit', 'update', ['priority' => 'high'])); $s->share($c, $t['public_key'], 2, 'revoke', 'b', revoke: true); self::assertSame(0, $s->count($b)); $this->error('not_found', fn () => $s->get($b, $t['public_key']));
    }
}

