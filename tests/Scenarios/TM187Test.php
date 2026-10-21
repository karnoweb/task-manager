<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Karnoweb\TaskManager\Tests\HandoffTestCase;

final class TM187Test extends HandoffTestCase
{
    public function test_tm187(): void
    {
        [$h, $origin, $destination] = $this->delegateFixture();
        $this->instances()->transition($this->manager(), $origin['instance_key'], 2, 'origin-cancel', 'cancel', ['reason_code' => 'recalled']);
        $this->instances()->transition($this->manager(), $destination['instance_key'], 1, 'destination-success', 'accept');
        $r = $this->handoffs()->settle($this->manager(), $h['handoff_key'], 1, 'return');
        self::assertSame('return_conflict', $r['status']);
        self::assertSame('approved', $r['outcome']);
        self::assertSame('handoff_return_conflict', $r['failure_code']);
        self::assertSame('cancelled', $this->instances()->get($this->manager(), $origin['instance_key'])['current_state_key']);
    }
}
