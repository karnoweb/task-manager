<?php

namespace Karnoweb\TaskManager\Tests;

use Karnoweb\TaskManager\Enterprise\ReportService;
use Karnoweb\TaskManager\SLA\CalendarRegistry;
use Karnoweb\TaskManager\SLA\WeeklyWorkingCalendar;

final class EnterpriseReportTest extends PhaseFiveTestCase
{
    public function test_historical_categories_and_projection_rebuild_follow_truth_and_current_permissions():void
    {
        [$revision,$project,$task]=$this->projectTask();$reports=$this->app->make(ReportService::class);$this->clock->advance('+1 hour');$this->step($task,'review','review');$this->clock->advance('+30 minutes');$this->step($task,'approve','done');
        self::assertSame(['todo'=>1],$reports->read($this->manager(),['as_of'=>'2026-10-20T00:30:00Z'])['metrics']['categories']);self::assertSame(['in_progress'=>1],$reports->read($this->manager(),['as_of'=>'2026-10-20T01:15:00Z'])['metrics']['categories']);
        self::assertSame('projected',$reports->rebuild($this->manager(),[],'rebuild')['state']);self::assertSame('projected',$reports->read($this->manager())['state']);self::assertSame(['done'=>1],$reports->read($this->manager())['metrics']['categories']);
        $this->clock->advance('+1 minute');self::assertSame('stale',$reports->read($this->manager(),[],false)['state']);self::assertSame('from_truth',$reports->read($this->manager())['state']);self::assertSame(0,$reports->read($this->manager('b'))['metrics']['total_instances']);
    }
    public function test_critical_path_shares_the_host_calendar_with_holidays_and_configurable_working_weekends():void
    {
        [$revision,$project,$a]=$this->projectTask();$b=$this->inProject($project,'b');$this->planning()->estimate($this->manager(),$a['public_key'],$a['version'],60,'3','estimate-a');$this->planning()->estimate($this->manager(),$b['public_key'],$b['version'],30,'2','estimate-b');
        $shifts=[];foreach([6,7,1,2,3,4] as $day){$shifts[$day]=[['09:00','17:00']];}$this->app->instance(CalendarRegistry::class,new CalendarRegistry(['advanced-v1'=>new WeeklyWorkingCalendar('Asia/Tehran',$shifts,['2026-10-24'])]));$this->link($a,$b,'FS',['lag_minutes'=>120,'clock'=>'business','calendar'=>'advanced-v1']);
        $path=$this->planning()->criticalPath($this->manager(),$project['project_key'],new \DateTimeImmutable('2026-10-22T11:30:00Z'));self::assertSame('2026-10-25 07:00:00.000000',$path['finish']);self::assertSame([$a['public_key'],$b['public_key']],$path['path']);
    }
}
