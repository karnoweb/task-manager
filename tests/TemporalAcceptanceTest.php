<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Scheduling\PersianCalendar;

final class TemporalAcceptanceTest extends PhaseFiveTestCase
{
    public function test_until_is_inclusive_and_no_occurrence_is_generated_after_the_series_stops():void
    {
        $task=$this->todo('Until');$series=$this->recurrence()->create($this->manager(),$task['public_key'],1,'gregorian','2026-10-20 09:00:00','UTC','FREQ=DAILY;UNTIL=20261022T090000Z','series');$from=new \DateTimeImmutable('2026-10-20');$until=$from->modify('+10 days');$made=$this->recurrence()->generate($this->manager(),$series['series_key'],1,$from,$until,'generate');self::assertCount(3,$made['created']);self::assertSame('2026-10-22 09:00:00.000000',DB::table('tm_recurrence_occurrences')->max('scheduled_at'));
        $this->recurrence()->changeRule($this->manager(),$series['series_key'],1,'FREQ=DAILY;UNTIL=20261022T090000Z','stop',true);self::assertSame([],$this->recurrence()->generate($this->manager(),$series['series_key'],2,$from,$until,'stopped')['created']);
    }
    public function test_jalali_thirty_first_missing_day_and_two_generator_commands_keep_one_occurrence_identity():void
    {
        $task=$this->todo('Jalali');$anchor=PersianCalendar::date(1405,6,31).' 09:00:00';$series=$this->recurrence()->create($this->manager(),$task['public_key'],1,'jalali',$anchor,'UTC',['frequency'=>'monthly','month_day'=>31,'missing_day'=>'last_day'],'series');$from=new \DateTimeImmutable('2026-09-01');$until=new \DateTimeImmutable('2026-11-25');self::assertSame(['2026-09-22 09:00:00','2026-10-22 09:00:00','2026-11-21 09:00:00'],array_column($this->recurrence()->preview($this->manager(),$series['series_key'],$from,$until),'local_at'));
        $first=$this->recurrence()->generate($this->manager(),$series['series_key'],1,$from,$until,'generator-a');$second=$this->recurrence()->generate($this->manager(),$series['series_key'],1,$from,$until,'generator-b');self::assertCount(3,$first['created']);self::assertSame([],$second['created']);self::assertSame(3,DB::table('tm_recurrence_occurrences')->count());
    }
}
