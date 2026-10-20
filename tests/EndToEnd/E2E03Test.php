<?php

namespace Karnoweb\TaskManager\Tests\EndToEnd;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\ProcessE2ETestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class E2E03Test extends ProcessE2ETestCase
{
    public static function outcomes(): array { return [['approved', 'sale_next_stage'], ['rejected', 'rework'], ['cancelled', 'follow_up']]; }
    #[DataProvider('outcomes')]
    public function test_sales_contract_approval_and_explicit_return_policy(string $outcome, string $expectedState): void
    {
        [$origin, $handoff, $destination, $step] = $this->contractFlow();
        self::assertSame('waiting', $this->instances()->get($this->manager(), $origin['instance_key'])['lifecycle']);
        self::assertSame('c', $destination['primary_assignee']); self::assertSame('a', $destination['owner_ref']);
        self::assertSame(3590, $this->sla()->get($this->manager(), $handoff['paused_visit_id'])['remaining_seconds']); $this->clock->advance('+60 seconds');
        if ($outcome === 'approved') {
            $first = $this->approvals()->vote($this->manager('b'), $step['step_key'], 1, 1, 'vote-b', 'approve'); self::assertSame('pending', $first['state']);
            $last = $this->approvals()->vote($this->manager('c'), $step['step_key'], 2, 1, 'vote-c', 'approve'); self::assertSame('executed', $last['execution_state']);
            self::assertSame(2, DB::table('tm_approval_votes')->count());
        } elseif ($outcome === 'rejected') {
            $last = $this->approvals()->vote($this->manager('b'), $step['step_key'], 1, 1, 'vote-reject', 'reject', 'contract_rejected');
            self::assertSame('rejected', $last['state']); self::assertSame('executed', $last['execution_state']);
        } else {
            $this->approvals()->cancel($this->manager(), $step['step_key'], 1, 1, 'cancel-approval', 'request_recalled');
            $this->instances()->transition($this->manager(), $destination['instance_key'], 2, 'cancel-contract', 'cancel', ['reason_code' => 'contract_cancelled']);
        }
        $returned = $this->handoffs()->settle($this->manager(), $handoff['handoff_key'], 1, 'return');
        self::assertSame($outcome, $returned['outcome']); self::assertSame('resolved', $returned['status']);
        $source = $this->instances()->get($this->manager(), $origin['instance_key']); self::assertSame($expectedState, $source['current_state_key']);
        self::assertSame('a', $source['owner_ref']); self::assertSame('b', $source['primary_assignee']); self::assertSame('active', $source['lifecycle']);
        self::assertSame(60, $this->sla()->get($this->manager(), $handoff['paused_visit_id'])['paused_seconds']);
        $caseKey = DB::table('tm_process_cases')->value('case_key'); $timeline = $this->cases()->timeline($this->manager(), $caseKey);
        self::assertContains('handoff_returned', array_column($timeline, 'action'));
        self::assertContains($outcome === 'cancelled' ? 'approval_cancelled' : 'approval_decided', array_column($timeline, 'action'));
        self::assertStringNotContainsString('PRIVATE-HOST-TEXT', json_encode($timeline));
    }
}
