<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\SlaTestCase;

final class TM196Test extends SlaTestCase
{
    public function test_scenario(): void
    {
        $c = $this->manager();
        [$i,$v]=$this->instanceWithSla(['duration_seconds'=>86400,'warning_before_seconds'=>3600]); $this->clock->advance('+22 hours 59 minutes 59 seconds'); self::assertSame(0,$this->sla()->run($c)['events']); $this->clock->advance('+1 second'); self::assertSame(1,$this->sla()->run($c)['events']); self::assertSame('warning',$this->sla()->get($c,$v['id'])['sla_state']); $this->clock->advance('+1 hour'); self::assertSame(1,$this->sla()->run($c)['events']); self::assertSame('breached',$this->sla()->get($c,$v['id'])['sla_state']); self::assertSame('new',$this->instances()->get($c,$i['instance_key'])['current_state_key']); self::assertSame(0,$this->sla()->run($c)['events']); self::assertSame(2,DB::table('tm_sla_events')->count());
    }
}

