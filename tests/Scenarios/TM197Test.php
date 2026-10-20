<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\SlaTestCase;

final class TM197Test extends SlaTestCase
{
    public function test_scenario(): void
    {
        $c = $this->manager();
        $this->clock->time=new \DateTimeImmutable('2026-10-22T12:30:00Z'); [$i,$v]=$this->instanceWithSla(['duration_seconds'=>10800,'clock_kind'=>'business','calendar_ref'=>'iran-v1']); self::assertSame('2026-10-24 07:30:00',$v['deadline_at']); $calendar=new \Karnoweb\TaskManager\SLA\WeeklyWorkingCalendar('Asia/Tehran',[4=>[['09:00','17:00']],6=>[['09:00','17:00']],7=>[['09:00','17:00']]],['2026-10-24']); $start=new \DateTimeImmutable('2026-10-22T12:30:00Z'); $due=$calendar->addSeconds($start,10800); self::assertSame('2026-10-25 07:30:00',$due->format('Y-m-d H:i:s')); self::assertSame(10800,$calendar->secondsBetween($start,$due));
    }
}

