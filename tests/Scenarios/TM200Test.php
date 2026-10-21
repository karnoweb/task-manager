<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\SlaTestCase;

final class TM200Test extends SlaTestCase
{
    public function test_scenario(): void
    {
        $c = $this->manager();
        [$i,$v] = $this->instanceWithSla(['duration_seconds' => 86400, 'stale_after_seconds' => 7200]);
        $this->clock->advance('+2 hours');
        self::assertSame(1, $this->sla()->run($c)['events']);
        $state = $this->sla()->get($c, $v['id']);
        self::assertSame('stale', $state['sla_state']);
        self::assertNull($state['breached_at']);
        self::assertSame('new', $this->instances()->get($c, $i['instance_key'])['current_state_key']);
        self::assertSame(0, DB::table('tm_instance_transitions')->count());
    }
}
