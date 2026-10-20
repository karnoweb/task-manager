<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;

use Karnoweb\TaskManager\Tests\HierarchyTestCase;

final class TM029Test extends HierarchyTestCase
{
    public function test_scenario(): void
    {
        $a = $this->todo('a'); $b = $this->attach($this->todo('b'), $a, 'b-a'); $c = $this->attach($this->todo('c'), $b, 'c-b');
        $before = DB::table('tm_task_closure')->count();
        $this->error('task_hierarchy_cycle', fn () => $this->hierarchy()->reparent($this->manager(), $a['public_key'], $a['version'], $a['public_key'], $a['version'], 'self'));
        $this->error('task_hierarchy_cycle', fn () => $this->attach($a, $c, 'cycle'));
        self::assertSame($before, DB::table('tm_task_closure')->count()); self::assertNull($this->service()->get($this->manager(), $a['public_key'])['parent_task_id']);
    }

}
