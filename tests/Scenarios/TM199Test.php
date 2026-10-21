<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\HandoffTestCase;

final class TM199Test extends HandoffTestCase
{
    public function test_tm199(): void
    {
        [$origin, $destination, $r] = $this->handoffFixture();
        $this->policy()->publishSla($this->manager(), $destination['pipeline_key'], 'inbox', 1, 'destination-sla', ['duration_seconds' => 60]);
        $h = $this->handoffs()->delegate($this->manager(), $origin['instance_key'], 1, $destination['pipeline_key'], $r['id'], 'contracts', $this->returnPolicy(), 'delegate');
        $this->clock->advance('+120 seconds');
        self::assertSame(1, $this->sla()->run($this->manager())['events']);
        $visit = DB::table('tm_stage_visits')->where('instance_id', $h['destination_id'])->first();
        self::assertSame('breached', $visit->sla_state);
        self::assertSame('paused', $this->sla()->get($this->manager(), $h['paused_visit_id'])['sla_state']);
        self::assertSame(3600, $this->sla()->get($this->manager(), $h['paused_visit_id'])['remaining_seconds']);
        $destinationKey = DB::table('tm_workflow_instances')->where('id', $h['destination_id'])->value('instance_key');
        $this->instances()->transition($this->manager(), $destinationKey, 1, 'accept', 'accept');
        $this->handoffs()->settle($this->manager(), $h['handoff_key'], 1, 'return');
        self::assertSame(120, $this->sla()->get($this->manager(), $h['paused_visit_id'])['paused_seconds']);
        self::assertSame(0, DB::table('tm_sla_events')->where('visit_id', $h['paused_visit_id'])->count());
    }
}
