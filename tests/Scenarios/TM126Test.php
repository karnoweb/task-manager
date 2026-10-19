<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\OutboxDispatcher;
use Karnoweb\TaskManager\Tests\PackageTestCase;

final class TM126Test extends PackageTestCase
{
    public function test_scenario(): void
    {
        $s = $this->service(); $c = $this->context();
        try { \Karnoweb\TaskManager\Facades\TaskManager::create(null, 'کار', 'create'); self::fail('Missing context accepted'); } catch (\TypeError $error) { self::assertStringContainsString('ExecutionContext', $error->getMessage()); } self::assertSame(0, DB::table('tm_tasks')->count()); $this->error('context_invalid', fn () => new ExecutionContext('', 'a', 'test'));
    }
}

