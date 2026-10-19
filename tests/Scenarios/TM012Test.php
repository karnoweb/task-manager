<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\OutboxDispatcher;
use Karnoweb\TaskManager\Tests\PackageTestCase;

final class TM012Test extends PackageTestCase
{
    public function test_scenario(): void
    {
        $s = $this->service(); $c = $this->context();
        $s->create($c, 'اول', 'same'); $this->error('idempotency_key_conflict', fn () => $s->create($c, 'دوم', 'same')); self::assertSame('اول', DB::table('tm_tasks')->value('title')); self::assertSame(1, DB::table('tm_command_journal')->count());
    }
}

