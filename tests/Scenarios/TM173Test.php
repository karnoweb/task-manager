<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\HandoffTestCase;

final class TM173Test extends HandoffTestCase
{
    public function test_tm173(): void
    {
        [$h, $origin, $destination] = $this->delegateFixture();
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
    }
}
