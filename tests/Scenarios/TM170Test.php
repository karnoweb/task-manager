<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Tests\HandoffTestCase;

final class TM170Test extends HandoffTestCase
{
    public function test_tm170(): void
    {
        [$h] = $this->delegateFixture();
        try {
            $this->handoffs()->recall($this->manager(), $h['handoff_key'], 1, 'early-timeout', true);
            self::fail();
        } catch (DomainError $e) {
            self::assertSame('handoff_timeout_not_due', $e->getMessage());
        }
        $this->clock->advance('+120 seconds');
        $r = $this->handoffs()->recall($this->manager(), $h['handoff_key'], 1, 'timeout', true);
        self::assertSame('timed_out', $r['outcome']);
        self::assertSame('resolved', $r['status']);
        self::assertSame($r, $this->handoffs()->recall($this->manager(), $h['handoff_key'], 1, 'timeout', true));
    }
}
