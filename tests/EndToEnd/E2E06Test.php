<?php

namespace Karnoweb\TaskManager\Tests\EndToEnd;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Tests\HandoffTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class E2E06Test extends HandoffTestCase
{
    public static function outcomes(): array
    {
        return [['approved'], ['cancelled']];
    }

    #[DataProvider('outcomes')]
    public function test_parallel_legal_and_risk_have_independent_slot_truth_and_explicit_join(string $outcome): void
    {
        [$origin, $destination, $revision] = $this->handoffFixture(false);
        $targets = [];
        foreach (['legal_review', 'risk_review'] as $slot) {
            $targets[] = ['pipeline_key' => $destination['pipeline_key'], 'revision_id' => $revision['id'], 'slot' => $slot];
        }
        $group = $this->handoffs()->parallel($this->manager(), $origin['instance_key'], 1, $targets, $this->returnPolicy(), ['mode' => 'all', 'outcomes' => ['approved'], 'priority' => ['approved'], 'remaining' => 'keep_active_detached'], 'parallel');
        $keys = [];
        foreach ($group['members'] as $member) {
            $keys[] = DB::table('tm_workflow_instances')->where('id', $member['destination_id'])->value('instance_key');
        }
        $this->instances()->transition($this->manager(), $keys[0], 1, 'legal-approve', 'accept');
        self::assertSame('awaiting_join', $this->handoffs()->settle($this->manager(), $group['members'][0]['handoff_key'], 1, 'legal-result')['status']);
        self::assertSame('new', $this->instances()->get($this->manager(), $keys[1])['current_state_key']);
        try {
            $this->instances()->transition($this->manager(), $keys[1], 2, 'stale-risk', 'accept');
            self::fail();
        } catch (DomainError $error) {
            self::assertSame('version_conflict', $error->getMessage());
        }
        if ($outcome === 'approved') {
            $this->instances()->transition($this->manager(), $keys[1], 1, 'risk-approve', 'accept');
        } else {
            $this->instances()->transition($this->manager(), $keys[1], 1, 'risk-cancel', 'cancel', ['reason_code' => 'recalled']);
        }
        $this->handoffs()->settle($this->manager(), $group['members'][1]['handoff_key'], 1, 'risk-result');
        $source = $this->instances()->get($this->manager(), $origin['instance_key']);
        self::assertSame($outcome === 'approved' ? 'active' : 'waiting', $source['lifecycle']);
        self::assertSame($outcome === 'approved' ? 'review' : 'new', $source['current_state_key']);
        self::assertSame($outcome === 'approved' ? 'resolved' : 'manual_review', DB::table('tm_handoff_groups')->where('id', $group['group_id'])->value('status'));
        self::assertSame(2, DB::table('tm_handoff_outcomes')->count());
        self::assertSame($outcome === 'approved' ? 1 : 0, DB::table('tm_outbox')->where('action', 'handoff_returned')->count());
    }
}
