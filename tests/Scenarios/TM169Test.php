<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\HandoffTestCase;

final class TM169Test extends HandoffTestCase
{
    public function test_tm169(): void
    {
        [$h, $origin, $destination] = $this->delegateFixture(false);
        $this->instances()->transition($this->manager(), $destination['instance_key'], 1, 'cancel-destination', 'cancel', ['reason_code' => 'recalled']);
        $result = $this->handoffs()->settle($this->manager(), $h['handoff_key'], 1, 'return');
        self::assertSame('cancelled', $result['outcome']);
        self::assertSame('resolved', $result['status']);
        self::assertSame('review', $this->instances()->get($this->manager(), $origin['instance_key'])['current_state_key']);
        self::assertSame('cancelled', DB::table('tm_handoff_outcomes')->value('outcome'));
    }
}
