<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;

use Karnoweb\TaskManager\Tests\HierarchyTestCase;

final class TM032Test extends HierarchyTestCase
{
    public function test_scenario(): void
    {
        $root = $this->todo('root'); $child = $this->attach($this->todo('child'), $root, 'attach');
        $this->error('parent_has_children', fn () => $this->service()->mutate($this->manager(), $root['public_key'], 1, 'delete-root', 'delete'));
        $preview = $this->hierarchy()->previewDelete($this->manager(), $root['public_key'], 'cascade_soft_delete');
        $this->hierarchy()->executeDelete($this->manager(), $preview, 'cascade'); $events = DB::table('tm_outbox')->count();
        $this->hierarchy()->executeDelete($this->manager(), $preview, 'cascade');
        self::assertSame(2, DB::table('tm_tasks')->whereNotNull('deleted_at')->count()); self::assertSame($events, DB::table('tm_outbox')->count());
        $this->error('parent_deleted', fn () => $this->service()->mutate($this->manager(), $child['public_key'], $child['version'] + 1, 'restore-child', 'undelete'));
        self::assertSame(3, DB::table('tm_task_closure')->count());
    }

}
