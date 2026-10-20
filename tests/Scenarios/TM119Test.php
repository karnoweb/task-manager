<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Scheduling\PersianCalendar;
use Karnoweb\TaskManager\Tests\PhaseFiveTestCase;

final class TM119Test extends PhaseFiveTestCase
{
    public function test_scenario():void
    {
        $task=$this->todo('Jalali');$anchor=PersianCalendar::date(1405,6,31).' 09:00:00';$series=$this->recurrence()->create($this->manager(),$task['public_key'],1,'jalali',$anchor,'UTC',['frequency'=>'monthly','month_day'=>31,'missing_day'=>'last_day'],'series');$from=new \DateTimeImmutable('2026-09-01');$until=new \DateTimeImmutable('2026-11-25');self::assertSame(['2026-09-22 09:00:00','2026-10-22 09:00:00','2026-11-21 09:00:00'],array_column($this->recurrence()->preview($this->manager(),$series['series_key'],$from,$until),'local_at'));
        $first=$this->recurrence()->generate($this->manager(),$series['series_key'],1,$from,$until,'generator-a');$second=$this->recurrence()->generate($this->manager(),$series['series_key'],1,$from,$until,'generator-b');self::assertCount(3,$first['created']);self::assertSame([],$second['created']);self::assertSame(3,DB::table('tm_recurrence_occurrences')->count());
    }

}
