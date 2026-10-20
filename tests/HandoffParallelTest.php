<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;

final class HandoffParallelTest extends HandoffTestCase
{
    private function parallelFixture(string $mode, int $quorum = 2): array
    {
        [$origin, $destination, $r] = $this->handoffFixture();
        $destinations = [];
        foreach (['legal', 'risk', 'contracts'] as $slot) { $destinations[] = ['pipeline_key' => $destination['pipeline_key'], 'revision_id' => $r['id'], 'slot' => $slot]; }
        $join = ['mode' => $mode, 'quorum' => $quorum, 'outcomes' => ['approved'], 'priority' => ['approved'], 'remaining' => 'keep_active_detached'];
        $group = $this->handoffs()->parallel($this->manager(), $origin['instance_key'], 1, $destinations, $this->returnPolicy(), $join, 'parallel');
        return [$origin, $group];
    }
    private function complete(array $handoff, string $suffix): array
    {
        $destinationKey = DB::table('tm_workflow_instances')->where('id', $handoff['destination_id'])->value('instance_key');
        $this->instances()->transition($this->manager(), $destinationKey, 1, 'accept-'.$suffix, 'accept');
        return $this->handoffs()->settle($this->manager(), $handoff['handoff_key'], 1, 'settle-'.$suffix);
    }
    public function test_all_waits_for_every_destination_then_resumes_exactly_once(): void
    {
        [$origin, $group] = $this->parallelFixture('all');
        self::assertSame('awaiting_join', $this->complete($group['members'][0], 'one')['status']);
        self::assertSame('waiting', $this->instances()->get($this->manager(), $origin['instance_key'])['lifecycle']);
        self::assertSame('awaiting_join', $this->complete($group['members'][1], 'two')['status']);
        self::assertSame('resolved', $this->complete($group['members'][2], 'three')['status']);
        $result = $this->instances()->get($this->manager(), $origin['instance_key']);
        self::assertSame('review', $result['current_state_key']); self::assertSame(3, $result['version']);
        self::assertSame(3, DB::table('tm_handoff_outcomes')->count()); self::assertSame(0, DB::table('tm_active_handoff_origins')->count());
    }
    public function test_any_detaches_remaining_processes_with_no_second_return(): void
    {
        [$origin, $group] = $this->parallelFixture('any');
        self::assertSame('resolved', $this->complete($group['members'][0], 'one')['status']);
        self::assertSame('detached', $this->handoffs()->get($this->manager(), $group['members'][1]['handoff_key'])['status']);
        self::assertSame('detached_resolved', $this->complete($group['members'][1], 'two')['status']);
        self::assertSame('detached_resolved', $this->complete($group['members'][2], 'three')['status']);
        self::assertSame(3, $this->instances()->get($this->manager(), $origin['instance_key'])['version']);
        self::assertSame(1, DB::table('tm_audit_events')->where('action', 'handoff_returned')->count());
    }
    public function test_quorum_counts_distinct_destinations_and_extra_outcomes_do_not_resume_again(): void
    {
        [$origin, $group] = $this->parallelFixture('quorum');
        $first = $this->complete($group['members'][0], 'one');
        self::assertSame('awaiting_join', $first['status']);
        self::assertSame($first, $this->handoffs()->settle($this->manager(), $first['handoff_key'], 2, 'duplicate'));
        self::assertSame('waiting', $this->instances()->get($this->manager(), $origin['instance_key'])['lifecycle']);
        self::assertSame('resolved', $this->complete($group['members'][1], 'two')['status']);
        self::assertSame('detached_resolved', $this->complete($group['members'][2], 'three')['status']);
        self::assertSame(1, DB::table('tm_audit_events')->where('action', 'handoff_returned')->count());
    }
    public function test_duplicate_slots_roll_back_the_whole_parallel_batch(): void
    {
        [$origin, $destination, $r] = $this->handoffFixture(false);
        $entry = ['pipeline_key' => $destination['pipeline_key'], 'revision_id' => $r['id'], 'slot' => 'legal'];
        try { $this->handoffs()->parallel($this->manager(), $origin['instance_key'], 1, [$entry, $entry], $this->returnPolicy(), ['mode' => 'all', 'outcomes' => ['approved'], 'priority' => ['approved'], 'remaining' => 'keep_active_detached'], 'parallel'); self::fail(); }
        catch (\Karnoweb\TaskManager\Exceptions\DomainError $e) { self::assertSame('active_slot_conflict', $e->getMessage()); }
        self::assertSame(0, DB::table('tm_handoff_groups')->count()); self::assertSame(0, DB::table('tm_handoffs')->count());
        self::assertSame(1, DB::table('tm_workflow_instances')->count());
        self::assertSame('active', $this->instances()->get($this->manager(), $origin['instance_key'])['lifecycle']);
    }
}
