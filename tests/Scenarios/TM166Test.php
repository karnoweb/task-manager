<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Karnoweb\TaskManager\Tests\HandoffTestCase;

final class TM166Test extends HandoffTestCase
{
    public function test_tm166(): void
    {
        [$h, $origin, $destination] = $this->delegateFixture();
        $waiting = $this->instances()->get($this->manager(), $origin['instance_key']);
        self::assertSame('waiting', $waiting['lifecycle']);
        self::assertSame('new', $waiting['current_state_key']);
        self::assertSame('active', $destination['lifecycle']);
        self::assertSame('contracts', $destination['slot_key']);
        self::assertNotNull($this->sla()->get($this->manager(), $h['paused_visit_id'])['paused_at']);
        self::assertCount(1, $this->instances()->visits($this->manager(), $origin['instance_key']));
        self::assertSame($waiting['case_id'], $destination['case_id']);
                $this->error('instance_on_hold', fn () => $this->instances()->transition($this->manager(), $origin['instance_key'], 2, 'bypass', 'review'));
    }
}
