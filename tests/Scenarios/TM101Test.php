<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\HierarchyTestCase;

final class TM101Test extends HierarchyTestCase
{
    public function test_scenario(): void
    {
        $a = $this->todo('a');
        $b = $this->attach($this->todo('b'), $a, 'b-a');
        $c = $this->attach($this->todo('c'), $b, 'c-b');
        $d = $this->todo('d');
        $moved = $this->attach($b, $d, 'b-d');
        self::assertSame(0, DB::table('tm_task_closure')->where('ancestor_task_id', $a['id'])->whereIn('descendant_task_id', [$b['id'], $c['id']])->count());
        self::assertSame(2, (int) DB::table('tm_task_closure')->where('ancestor_task_id', $d['id'])->where('descendant_task_id', $c['id'])->value('depth'));
        self::assertSame(1, (int) DB::table('tm_task_closure')->where('ancestor_task_id', $b['id'])->where('descendant_task_id', $c['id'])->value('depth'));
        self::assertGreaterThan($b['version'], $moved['version']);
        self::assertSame(3, $this->hierarchy()->tree($this->manager(), $d['public_key'])['total']);
    }
}
