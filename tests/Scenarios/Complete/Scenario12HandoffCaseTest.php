<?php

namespace Karnoweb\TaskManager\Tests\Scenarios\Complete;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\HandoffTestCase;

final class Scenario12HandoffCaseTest extends HandoffTestCase
{
    public function test_complete_scenario_12(): void
    {

        [$h, $origin, $destination] = $this->delegateFixture();
        self::assertSame('waiting', $this->instances()->get($this->manager(), $origin['instance_key'])['lifecycle']);
        $this->error('instance_on_hold', fn () => $this->instances()->transition($this->manager(), $origin['instance_key'], 2, 'bypass', 'review'));
        $this->clock->advance('+60 seconds');
        $this->instances()->transition($this->manager(), $destination['instance_key'], 1, 'contract-accepted', 'accept');
        $result = $this->handoffs()->settle($this->manager(), $h['handoff_key'], 1, 'return');
        self::assertSame('approved', $result['outcome']);
        self::assertSame('resolved', $result['status']);
        self::assertSame('review', $this->instances()->get($this->manager(), $origin['instance_key'])['current_state_key']);
        self::assertSame(60, $this->sla()->get($this->manager(), $h['paused_visit_id'])['paused_seconds']);
        $count = DB::table('tm_audit_events')->count();
        self::assertSame($result, $this->handoffs()->settle($this->manager(), $h['handoff_key'], 1, 'return'));
        self::assertSame($result, $this->handoffs()->settle($this->manager(), $h['handoff_key'], 2, 'duplicate-return'));
        self::assertSame($count, DB::table('tm_audit_events')->count());

        $current = $this->instances()->get($this->manager(), $origin['instance_key']);
        self::assertNotNull($current['case_id']);
        $caseKey = DB::table('tm_process_cases')->where('id', $current['case_id'])->value('case_key');
        self::assertNotEmpty($this->cases()->timeline($this->manager(), $caseKey));

    }
}
