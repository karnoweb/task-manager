<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\OutboxDispatcher;
use Karnoweb\TaskManager\Tests\PackageTestCase;

final class TM098Test extends PackageTestCase
{
    public function test_scenario(): void
    {
        $s = $this->service(); $c = $this->context();
        $s->create($c, 'اول', 'one'); $s->create($this->context('a', 'two'), 'دوم', 'two'); $rows = DB::table('tm_workflow_definitions')->orderBy('scope_key')->get(); self::assertCount(2, $rows); self::assertNotSame($rows[0]->id, $rows[1]->id); DB::table('tm_workflow_definitions')->where('scope_key', 'one')->update(['definition' => '{}']); self::assertStringContainsString('pending', DB::table('tm_workflow_definitions')->where('scope_key', 'two')->value('definition'));
    }
}

