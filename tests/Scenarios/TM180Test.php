<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\HandoffTestCase;

final class TM180Test extends HandoffTestCase
{
    public function test_tm180(): void
    {
        [$h, $origin, $destination] = $this->delegateFixture(false);
        $this->instances()->transition($this->manager(), $destination['instance_key'], 1, 'review-destination', 'review');
        $all = $this->instances()->query($this->manager());
        self::assertCount(2, $all);
        self::assertSame($origin['subject_id'], $destination['subject_id']);
        self::assertSame('new', $this->instances()->get($this->manager(), $origin['instance_key'])['current_state_key']);
        self::assertSame('review', $this->instances()->get($this->manager(), $destination['instance_key'])['current_state_key']);
        self::assertSame(2, DB::table('tm_active_process_slots')->count());
    }
}
