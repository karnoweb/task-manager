<?php

namespace Karnoweb\TaskManager\Tests\Scenarios\Complete;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Integration\AutomationService;
use Karnoweb\TaskManager\Tests\EnterpriseProcessTestCase;

final class Scenario09SlaAutomationTest extends EnterpriseProcessTestCase
{
    public function test_complete_scenario_09(): void
    {

        [$handoff,$origin,$destination] = $this->delegateFixture();
        $this->clock->advance('+60 seconds');
        $this->instances()->transition($this->manager(), $destination['instance_key'], 1, 'accept', 'accept');
        $this->handoffs()->settle($this->manager(), $handoff['handoff_key'], 1, 'settle');
        $event = DB::table('tm_outbox')->where('action', 'handoff_returned')->first();
        self::assertNotNull($event);
        $automation = $this->app->make(AutomationService::class);
        $automation->publish($this->manager(), null, 0, 'handoff_returned', ['max_depth' => 2, 'actions' => [['type' => 'transition', 'args' => ['edge' => 'approve', 'payload' => ['reason_code' => 'approved']]], ['type' => 'notify']]], 'rule');
        $result = $automation->run($this->worker(), $event->event_id);
        self::assertSame(['completed', 'completed'], array_column($result['actions'], 'state'));
        self::assertSame('won', $this->instances()->get($this->manager(), $origin['instance_key'])['current_state_key']);
        $events = DB::table('tm_outbox')->count();
        self::assertSame($result, $automation->run($this->worker(), $event->event_id));
        self::assertSame($events, DB::table('tm_outbox')->count());
        self::assertGreaterThan(0, DB::table('tm_event_lineage')->where('root_event_id', $event->event_id)->where('depth', 1)->count());

        self::assertSame(60, $this->sla()->get($this->manager(), $handoff['paused_visit_id'])['paused_seconds']);

    }
}
