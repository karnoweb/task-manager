<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Karnoweb\TaskManager\Tests\HandoffTestCase;

final class TM171Test extends HandoffTestCase
{
    public function test_tm171(): void
    {
        [$h, $origin, $destination] = $this->delegateFixture();
        $r = $this->handoffs()->recall($this->manager(), $h['handoff_key'], 1, 'recall');
        self::assertSame('recalled', $r['outcome']);
        self::assertSame('resolved', $r['status']);
        self::assertSame('cancelled', $this->instances()->get($this->manager(), $destination['instance_key'])['current_state_key']);
        self::assertSame('review', $this->instances()->get($this->manager(), $origin['instance_key'])['current_state_key']);
    }
}
