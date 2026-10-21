<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\ApprovalTestCase;

final class TM193Test extends ApprovalTestCase
{
    public function test_scenario(): void
    {
        $c = $this->manager();
        [$i,$step] = $this->instanceWithApproval();
        $first = $this->approvals()->vote($this->manager('b'), $step['step_key'], 1, 2, 'vote-b', 'approve');
        self::assertSame('pending', $first['state']);
        self::assertCount(1, $first['votes']);
        self::assertSame($first, $this->approvals()->vote($this->manager('b'), $step['step_key'], 1, 2, 'vote-b', 'approve'));
        $again = $this->approvals()->vote($this->manager('b'), $step['step_key'], 2, 2, 'vote-b-again', 'approve');
        self::assertCount(1, $again['votes']);
        $done = $this->approvals()->vote($this->manager('c'), $step['step_key'], 2, 2, 'vote-c', 'approve');
        self::assertSame('approved', $done['state']);
        self::assertSame('executed', $done['execution_state']);
        self::assertSame('won', $this->instances()->get($c, $i['instance_key'])['current_state_key']);
        self::assertSame(2, DB::table('tm_approval_votes')->count());
        self::assertSame(1,DB::table('tm_outbox')->where('action','approval_decided')->count());
    }
}
