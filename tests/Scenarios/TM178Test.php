<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Tests\HandoffTestCase;
use Karnoweb\TaskManager\Tests\Support\ProcessBarrier;

final class TM178Test extends HandoffTestCase
{
    private function parallelFixture(string $mode, int $quorum = 2): array
    {
        [$origin, $destination, $r] = $this->handoffFixture();
        $destinations = [];
        foreach (['legal', 'risk', 'contracts'] as $slot) { $destinations[] = ['pipeline_key' => $destination['pipeline_key'], 'revision_id' => $r['id'], 'slot' => $slot]; }
        $join = ['mode' => $mode, 'quorum' => $quorum, 'outcomes' => ['approved'], 'priority' => ['approved'], 'remaining' => 'keep_active_detached'];
        $group = $this->handoffs()->parallel($this->manager(), $origin['instance_key'], 1, $destinations, $this->returnPolicy(), $join, 'parallel');
        return [$origin, $group];
    }
    private function complete(array $handoff, string $suffix): array
    {
        $destinationKey = DB::table('tm_workflow_instances')->where('id', $handoff['destination_id'])->value('instance_key');
        $this->instances()->transition($this->manager(), $destinationKey, 1, 'accept-'.$suffix, 'accept');
        return $this->handoffs()->settle($this->manager(), $handoff['handoff_key'], 1, 'settle-'.$suffix);
    }
    public function test_tm178(): void
    {
        [$origin, $group] = $this->parallelFixture('any');
        self::assertSame('resolved', $this->complete($group['members'][0], 'one')['status']);
        self::assertSame('detached', $this->handoffs()->get($this->manager(), $group['members'][1]['handoff_key'])['status']);
        self::assertSame('detached_resolved', $this->complete($group['members'][1], 'two')['status']);
        self::assertSame('detached_resolved', $this->complete($group['members'][2], 'three')['status']);
        self::assertSame(3, $this->instances()->get($this->manager(), $origin['instance_key'])['version']);
        self::assertSame(1, DB::table('tm_audit_events')->where('action', 'handoff_returned')->count());
    }
}

