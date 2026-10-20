<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Tests\HandoffTestCase;
use Karnoweb\TaskManager\Tests\Support\ProcessBarrier;

final class TM168Test extends HandoffTestCase
{
    public function test_tm168(): void
    {
        $policy = $this->returnPolicy(); $policy['returns']['rejected'] = ['transition' => 'correct', 'reason_code' => 'invalid'];
        [$h, $origin, $destination] = $this->delegateFixture(false, $policy);
        $this->instances()->transition($this->manager(), $destination['instance_key'], 1, 'reject', 'reject', ['reason_code' => 'invalid']);
        $r = $this->handoffs()->settle($this->manager(), $h['handoff_key'], 1, 'return');
        self::assertSame('resolved', $r['status']); self::assertSame('rejected', $r['outcome']);
        $result = $this->instances()->get($this->manager(), $origin['instance_key']);
        self::assertSame('new', $result['current_state_key']); self::assertSame('active', $result['lifecycle']);
        self::assertStringContainsString('invalid', DB::table('tm_audit_events')->where('aggregate_key', $origin['instance_key'])->where('action', 'instance_transitioned')->value('payload'));
    }
}

