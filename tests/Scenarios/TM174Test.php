<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Tests\HandoffTestCase;
use Karnoweb\TaskManager\Tests\Support\ProcessBarrier;

final class TM174Test extends HandoffTestCase
{
    public function test_tm174(): void
    {
        [$h, $origin, $destination] = $this->delegateFixture(false);
        $this->instances()->transition($this->manager(), $destination['instance_key'], 1, 'accept', 'accept');
        $this->handoffs()->settle($this->manager(), $h['handoff_key'], 1, 'return');
        $this->instances()->transition($this->manager(), $destination['instance_key'], 2, 'reopen', 'reopen');
        $this->instances()->transition($this->manager(), $destination['instance_key'], 3, 'reject', 'reject', ['reason_code' => 'invalid']);
        try { $this->handoffs()->settle($this->manager(), $h['handoff_key'], 2, 'contradictory-return'); self::fail(); }
        catch (DomainError $e) { self::assertSame('handoff_already_resolved', $e->getMessage()); }
        self::assertSame('approved', DB::table('tm_handoff_outcomes')->value('outcome'));
        self::assertSame(3, $this->instances()->get($this->manager(), $origin['instance_key'])['version']);
    }
}

