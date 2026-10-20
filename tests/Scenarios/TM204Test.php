<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\SlaTestCase;

final class TM204Test extends SlaTestCase
{
    public function test_scenario(): void
    {
        $c = $this->manager();
        [$i,$v]=$this->instanceWithSla(['duration_seconds'=>60,'breach_transition'=>'review','breach_reason'=>'sla_expired']); $this->clock->advance('+59 seconds'); $next=$this->instances()->transition($c,$i['instance_key'],1,'review','review'); $this->clock->advance('+1 hour'); self::assertSame(0,$this->sla()->run($c)['events']); self::assertSame($next,$this->instances()->get($c,$i['instance_key'])); self::assertSame(0,DB::table('tm_sla_events')->count()); self::assertNotNull($this->sla()->get($c,$v['id'])['exited_at']);
    }
}

