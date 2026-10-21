<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\AgileTestCase;

final class TM037Test extends AgileTestCase
{
    public function test_scenario(): void
    {
        $a = $this->todo('a');
        $b = $this->todo('b');
        $this->link($a, $b, operation: 'first-client');
        $this->error('version_conflict', fn () => $this->dependencies()->add($this->manager(), $b['public_key'], 1, $a['public_key'], 1, 'FS', [], 'stale-client'));
        $this->error('dependency_cycle', fn () => $this->link($b, $a, operation: 'fresh-reverse'));
        self::assertSame(1, DB::table('tm_task_dependencies')->whereNull('deleted_at')->count());
        self::assertSame(1, DB::table('tm_active_dependencies')->count());
    }
}
