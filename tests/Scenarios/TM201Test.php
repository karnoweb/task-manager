<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\SlaTestCase;

final class TM201Test extends SlaTestCase
{
    public function test_scenario(): void
    {
        $c = $this->manager();
        [$i,$v]=$this->instanceWithSla(['duration_seconds'=>3600,'stale_after_seconds'=>1800,'dead_candidate'=>true]); $this->clock->advance('+1 hour'); self::assertSame(3,$this->sla()->run($c)['events']); self::assertSame('dead_candidate',$this->sla()->get($c,$v['id'])['sla_state']); $instance=$this->instances()->get($c,$i['instance_key']); self::assertSame('new',$instance['current_state_key']); self::assertSame('active',$instance['lifecycle']); self::assertSame(1,DB::table('tm_active_process_slots')->count()); self::assertSame(0,DB::table('tm_instance_transitions')->count());
    }
}

