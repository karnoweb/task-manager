<?php

namespace Karnoweb\TaskManager\Tests;

use Karnoweb\TaskManager\Scheduling\ReminderService;
use Karnoweb\TaskManager\Scheduling\RecurrenceRule;

final class DatePlanningBridgeTest extends PhaseFiveTestCase
{
    public function test_impact_preview_execution_updates_calendar_and_start_reminders_without_faking_actual_work():void
    {
        $a=$this->todo('A');$b=$this->todo('B');$a=$this->dates()->change($this->manager(),$a['public_key'],1,'2026-10-20 08:00:00','2026-10-20 09:00:00',null,'UTC',false,'reject','dates');self::assertSame($a['starts_at'],$a['planned_start_at']);$this->planning()->estimate($this->manager(),$b['public_key'],1,60,'1','estimate');$this->link($a,$b);$reminders=$this->app->make(ReminderService::class);$reminder=$reminders->create($this->manager(),$a['public_key'],'start',0,null,'in_app','reminder');
        $preview=$this->planning()->previewSchedule($this->manager(),$a['public_key'],new \DateTimeImmutable('2026-10-21T08:00:00Z'),new \DateTimeImmutable('2026-10-21T09:00:00Z'));self::assertNull($this->service()->get($this->manager(),$b['public_key'])['starts_at']);$this->planning()->executeSchedule($this->manager(),$preview,'move');$events=$this->dates()->calendar($this->manager(),new \DateTimeImmutable('2026-10-21'),new \DateTimeImmutable('2026-10-22'))['events'];self::assertSame(['task_schedule','task_schedule'],array_column($events,'kind'));self::assertSame('2026-10-21 08:00:00.000000',$reminders->get($this->manager(),$reminder['reminder_key'])['scheduled_at']);
        $b=$this->service()->get($this->manager(),$b['public_key']);$this->error('dependency_date_missing',fn()=>$this->service()->mutate($this->manager(),$b['public_key'],$b['version'],'complete','complete'));
    }
    public function test_unsupported_or_malformed_recurrence_parts_fail_explicitly():void
    {
        $this->error('recurrence_rule_invalid',fn()=>RecurrenceRule::parse('FREQ=DAILY;INTERVAL=wrong'));$this->error('recurrence_part_unsupported',fn()=>RecurrenceRule::parse('FREQ=DAILY;BYSETPOS=1'));$this->error('recurrence_rule_invalid',fn()=>RecurrenceRule::parse(['frequency'=>'weekly','weekdays'=>[['MO']]]));$this->error('date_invalid',fn()=>RecurrenceRule::parse(['frequency'=>'daily','until'=>'2026-02-30T09:00:00Z']));
    }
}
