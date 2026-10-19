<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\OutboxDispatcher;
use Karnoweb\TaskManager\Tests\PackageTestCase;

final class TM129Test extends PackageTestCase
{
    public function test_scenario(): void
    {
        $s = $this->service(); $c = $this->context();
        $t = $s->create($c, 'اول', 'create'); $s->mutate($c, $t['public_key'], 1, 'same', 'update', ['priority' => 'high']); $this->error('idempotency_key_conflict', fn () => $s->mutate($c, $t['public_key'], 1, 'same', 'update', ['priority' => 'low'])); self::assertSame(2, DB::table('tm_command_journal')->count()); self::assertSame('high', $s->get($c, $t['public_key'])['priority']);
    }
}

