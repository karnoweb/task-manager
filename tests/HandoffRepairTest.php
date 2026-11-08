<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Exceptions\DomainError;

final class HandoffRepairTest extends HandoffTestCase
{
    public function test_manual_join_requires_recorded_result_and_explicit_versioned_repair(): void
    {
        [$origin, $destination, $r] = $this->handoffFixture(false);
        $targets = [];
        foreach (['legal', 'risk'] as $slot) {
            $targets[] = ['pipeline_key' => $destination['pipeline_key'], 'revision_id' => $r['id'], 'slot' => $slot];
        }
        $batch = $this->handoffs()->parallel($this->manager(), $origin['instance_key'], 1, $targets, $this->returnPolicy(), ['mode' => 'manual', 'outcomes' => ['approved'], 'priority' => ['approved'], 'remaining' => 'keep_active_detached'], 'manual-group');
        try {
            $this->handoffs()->manualJoin($this->manager(), $batch['group_key'], 1, 'too-early');
            self::fail();
        } catch (DomainError $error) {
            self::assertSame('handoff_join_no_result', $error->errorCode);
        }
        $member = $batch['members'][0];
        $key = DB::table('tm_workflow_instances')->where('id', $member['destination_id'])->value('instance_key');
        $this->instances()->transition($this->manager(), $key, 1, 'approve-legal', 'accept');
        $this->handoffs()->settle($this->manager(), $member['handoff_key'], 1, 'record-legal');
        $group = $this->handoffs()->getGroup($this->manager(), $batch['group_key']);
        self::assertSame('awaiting_manual', $group['status']);
        self::assertSame('waiting', $this->instances()->get($this->manager(), $origin['instance_key'])['lifecycle']);
        self::assertSame('resolved', $this->handoffs()->manualJoin($this->manager(), $batch['group_key'], $group['version'], 'manual-join')['status']);
        self::assertSame('review', $this->instances()->get($this->manager(), $origin['instance_key'])['current_state_key']);
        self::assertSame('detached', $this->handoffs()->get($this->manager(), $batch['members'][1]['handoff_key'])['status']);
        self::assertSame(1, DB::table('tm_handoff_repairs')->where('action', 'manual_join')->count());
    }

    public function test_reviewed_source_version_can_be_accepted_without_rewriting_original_snapshot(): void
    {
        [$h, $origin, $destination] = $this->delegateFixture();
        $this->instances()->transition($this->manager(), $origin['instance_key'], 2, 'progress', 'progress');
        $this->instances()->transition($this->manager(), $destination['instance_key'], 1, 'approve', 'accept');
        self::assertSame('return_conflict', $this->handoffs()->settle($this->manager(), $h['handoff_key'], 1, 'return')['status']);
        $repaired = $this->handoffs()->retryReturn($this->manager(), $h['handoff_key'], 2, 3, 'source_change_reviewed', 'repair');
        self::assertSame('resolved', $repaired['status']);
        self::assertSame(2, $repaired['origin_version']);
        self::assertSame(3, $repaired['repair_origin_version']);
        self::assertSame($h['origin_snapshot'], $repaired['origin_snapshot']);
        self::assertSame($h['policy_snapshot'], $repaired['policy_snapshot']);
        self::assertSame(4, $this->instances()->get($this->manager(), $origin['instance_key'])['version']);
        self::assertSame(1, DB::table('tm_handoff_outcomes')->count());
        self::assertSame(1, DB::table('tm_handoff_repairs')->count());
    }

    public function test_closed_origin_conflict_can_be_acknowledged_without_business_overwrite(): void
    {
        [$h, $origin, $destination] = $this->delegateFixture(false);
        $this->instances()->transition($this->manager(), $origin['instance_key'], 2, 'cancel-origin', 'cancel', ['reason_code' => 'recalled']);
        $this->instances()->transition($this->manager(), $destination['instance_key'], 1, 'approve', 'accept');
        $this->handoffs()->settle($this->manager(), $h['handoff_key'], 1, 'return');
        $closed = $this->handoffs()->closeConflict($this->manager(), $h['handoff_key'], 2, 'acknowledge');
        self::assertSame('manually_closed', $closed['status']);
        self::assertSame('approved', $closed['outcome']);
        self::assertSame('cancelled', $this->instances()->get($this->manager(), $origin['instance_key'])['current_state_key']);
        self::assertSame(3, $this->instances()->get($this->manager(), $origin['instance_key'])['version']);
        self::assertSame(0, DB::table('tm_active_handoff_origins')->count());
        $events = DB::table('tm_outbox')->count();
        self::assertSame($closed, $this->handoffs()->settle($this->manager(), $h['handoff_key'], 3, 'repeat-result'));
        self::assertSame($events, DB::table('tm_outbox')->count());
    }

    public function test_repair_requires_separate_capability(): void
    {
        [$h] = $this->delegateFixture(false);
        $base = $this->manager();
        $limited = new ExecutionContext('one', 'a', 'limited', capabilities: array_values(array_diff($base->capabilities, ['handoff.repair'])));
                $this->error('capability_denied', fn () => $this->handoffs()->retryReturn($limited, $h['handoff_key'], 1, 2, 'source_change_reviewed', 'repair'));
    }

    public function test_mixed_join_keeps_member_outcomes_distinct_from_aggregate_result(): void
    {
        [$origin, $destination, $r] = $this->handoffFixture(false);
        $targets = [];
        foreach (['legal', 'risk'] as $slot) {
            $targets[] = ['pipeline_key' => $destination['pipeline_key'], 'revision_id' => $r['id'], 'slot' => $slot];
        }
        $policy = $this->returnPolicy();
        $policy['returns']['rejected'] = ['transition' => 'correct', 'reason_code' => 'invalid'];
        $batch = $this->handoffs()->parallel($this->manager(), $origin['instance_key'], 1, $targets, $policy, ['mode' => 'all', 'outcomes' => ['approved', 'rejected'], 'priority' => ['rejected', 'approved'], 'remaining' => 'keep_active_detached'], 'mixed-group');
        foreach ($batch['members'] as $index => $member) {
            $key = DB::table('tm_workflow_instances')->where('id', $member['destination_id'])->value('instance_key');
            $this->instances()->transition($this->manager(), $key, 1, 'outcome-'.$index, $index === 0 ? 'accept' : 'reject', $index === 0 ? [] : ['reason_code' => 'invalid']);
            $this->handoffs()->settle($this->manager(), $member['handoff_key'], 1, 'settle-'.$index);
        }
        $group = $this->handoffs()->getGroup($this->manager(), $batch['group_key']);
        self::assertSame('rejected', $group['outcome']);
        self::assertSame(['approved', 'rejected'], array_column($group['members'], 'outcome'));
        self::assertSame('new', $this->instances()->get($this->manager(), $origin['instance_key'])['current_state_key']);
        self::assertSame('active', $this->instances()->get($this->manager(), $origin['instance_key'])['lifecycle']);
        self::assertSame(1, DB::table('tm_outbox')->where('action', 'handoff_returned')->count());
    }
}
