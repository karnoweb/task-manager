<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Karnoweb\TaskManager\Tests\HandoffTestCase;

final class TM172Test extends HandoffTestCase
{
    public function test_tm172(): void
    {
        [$h, $origin, $destination] = $this->delegateFixture();
        $changed = $this->instances()->transition($this->manager(), $origin['instance_key'], 2, 'progress', 'progress');
        self::assertSame('waiting', $changed['lifecycle']);
        $this->instances()->transition($this->manager(), $destination['instance_key'], 1, 'accept', 'accept');
        $r = $this->handoffs()->settle($this->manager(), $h['handoff_key'], 1, 'return');
        self::assertSame('return_conflict', $r['status']);
        self::assertSame('handoff_return_conflict', $r['failure_code']);
        $after = $this->instances()->get($this->manager(), $origin['instance_key']);
        self::assertSame('new', $after['current_state_key']);
        self::assertSame(3, $after['version']);
        self::assertNotNull($this->sla()->get($this->manager(), $h['paused_visit_id'])['paused_at']);
    }
}
