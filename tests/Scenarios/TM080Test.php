<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\OutboxDispatcher;
use Karnoweb\TaskManager\Tests\PackageTestCase;

final class TM080Test extends PackageTestCase
{
    public function test_scenario(): void
    {
        $s = $this->service(); $c = $this->context();
        $limited = new ExecutionContext('one', 'worker', 'job', true, []); $this->error('capability_denied', fn () => $s->create($limited, 'کار', 'create')); $this->error('capability_denied', fn () => $s->count($limited)); self::assertSame(0, DB::table('tm_tasks')->count()); $this->error('service_capabilities_required', fn () => new ExecutionContext('one', 'worker', 'job', true));
    }
}

