<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\AssignmentTestCase;

final class TM189Test extends AssignmentTestCase
{
    public function test_scenario(): void
    {
        $c = $this->manager();
        $i = $this->assignableInstance();
        $state = $this->assignments()->configureInstance($c, $i['instance_key'], 1, 'policy', ['primary' => 'b', 'secondary' => ['c', 'd'], 'max_assignees' => 3]);
        self::assertSame(1, DB::table('tm_assignment_slots')->where('slot_key', 'primary')->count());
        self::assertSame(3, DB::table('tm_assignment_slots')->count());
        $this->error('duplicate_assignee', fn () => $this->assignments()->assign($c, $i['instance_key'], 2, 'duplicate', 'b', ['b']));
        self::assertSame(3, DB::table('tm_assignment_slots')->count());
        self::assertSame('a', $state['owner_ref']);
        self::assertSame(0, $this->instances()->count($this->manager('b')));
    }
}
