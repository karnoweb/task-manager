<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\AssignmentTestCase;

final class TM146Test extends AssignmentTestCase
{
    public function test_scenario(): void
    {
        $c = $this->manager();
        $i = $this->assignableInstance();
        $this->assignments()->assign($c, $i['instance_key'], 1, 'assign', 'b');
        DB::table('host_people')->where('actor', 'b')->update(['active' => false]);
        $this->error('member_has_active_work', fn () => $this->assignments()->depart($c, $i['instance_key'], 2, 'reject', 'b', 'reject'));
        $this->clock->advance('+1 hour');
        $state = $this->assignments()->depart($c, $i['instance_key'], 2, 'restore', 'b', 'reassign_owner');
        self::assertSame('a', $state['primary_assignee']);
        self::assertSame('a', $state['owner_ref']);
        self::assertNotNull($state['history'][1]['active_to']);
        self::assertSame('member_departed', $state['history'][0]['reason_code']);
        self::assertSame(1, DB::table('tm_audit_events')->where('action','assignment_member_departed')->count());
    }
}
