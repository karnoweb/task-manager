<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

final class HandoffAssignmentTest extends HandoffTestCase
{
    public static function modes(): array { return [['restore_origin', 'a'], ['keep_current', 'b'], ['resolve_stage', 'c']]; }
    #[DataProvider('modes')]
    public function test_return_assignment_is_explicit_and_owner_is_preserved(string $mode, string $expected): void
    {
        [$origin, $destination, $r, $pipeline] = $this->handoffFixture(false);
        $this->assignments()->configureStage($this->manager(), $pipeline['pipeline_key'], 'review', 1, 'return-stage-assignment', ['primary' => 'c']);
        $policy = $this->returnPolicy(); $policy['assignment'] = $mode;
        $h = $this->handoffs()->delegate($this->manager(), $origin['instance_key'], 1, $destination['pipeline_key'], $r['id'], 'contracts', $policy, 'delegate');
        $key = DB::table('tm_workflow_instances')->where('id', $h['destination_id'])->value('instance_key');
        $this->assignments()->assign($this->manager(), $key, 1, 'destination-assignee', 'b');
        $this->instances()->transition($this->manager(), $key, 2, 'accept', 'accept');
        self::assertSame('resolved', $this->handoffs()->settle($this->manager(), $h['handoff_key'], 1, 'return')['status']);
        $result = $this->assignments()->get($this->manager(), $origin['instance_key']);
        self::assertSame($expected, $result['primary_assignee']); self::assertSame('a', $result['owner_ref']);
        self::assertSame(1, DB::table('tm_assignment_slots')->where('instance_id', $origin['id'])->where('slot_key', 'primary')->count());
    }
}
