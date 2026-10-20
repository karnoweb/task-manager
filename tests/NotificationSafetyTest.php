<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\NotificationTransport;
use Karnoweb\TaskManager\Contracts\NotificationPreferences;
use Karnoweb\TaskManager\Scheduling\ReminderService;
use Karnoweb\TaskManager\Scheduling\DailyQuietHours;
use Karnoweb\TaskManager\Integration\EventPump;
use Karnoweb\TaskManager\Tests\Support\RecordingNotificationTransport;

final class NotificationSafetyTest extends PhaseFiveTestCase
{
    private function worker():ExecutionContext{$base=$this->manager();return new ExecutionContext('one','a','notification-worker',true,$base->capabilities);}
    public function test_queued_old_reminder_is_suppressed_and_unchanged_deadline_does_not_resend():void
    {
        $transport=new RecordingNotificationTransport();$this->app->instance(NotificationTransport::class,$transport);$pump=$this->app->make(EventPump::class);$pump->subscribe($this->manager(),'reminder_due','notifications','subscribe');$reminders=$this->app->make(ReminderService::class);
        $task=$this->todo('Task');$task=$this->dates()->change($this->manager(),$task['public_key'],1,null,null,'2026-10-20 00:01:00','UTC',false,'reject','dates');$reminder=$reminders->create($this->manager(),$task['public_key'],'due',-60,null,'in_app','reminder');$reminders->dispatch($this->worker());
        $task=$this->dates()->change($this->manager(),$task['public_key'],$task['version'],null,null,'2026-10-20 00:02:00','UTC',false,'reject','changed');$pump->run($this->worker());self::assertCount(0,$transport->events);
        $this->clock->advance('+1 minute');$reminders->dispatch($this->worker());$pump->run($this->worker());self::assertCount(1,$transport->events);self::assertSame(2,array_values($transport->events)[0]['data']['generation']);
        $this->dates()->change($this->manager(),$task['public_key'],$task['version'],null,null,'2026-10-20 03:32:00','Asia/Tehran',false,'reject','same-instant');self::assertSame(2,$reminders->get($this->manager(),$reminder['reminder_key'])['generation']);self::assertSame('sent',$reminders->get($this->manager(),$reminder['reminder_key'])['state']);self::assertSame(0,$reminders->dispatch($this->worker())['queued']);
    }
    public function test_notification_quiet_hours_defer_until_window_without_spending_retry_budget():void
    {
        $transport=new RecordingNotificationTransport();$this->app->instance(NotificationTransport::class,$transport);$this->app->instance(NotificationPreferences::class,new DailyQuietHours('UTC','22:00','07:00'));$this->clock->time=new \DateTimeImmutable('2026-10-20T23:00:00Z');$pump=$this->app->make(EventPump::class);$pump->subscribe($this->manager(),'created','notifications','subscribe');$this->todo('Quiet');self::assertSame(1,$pump->run($this->worker())['deferred']);self::assertSame(0,$pump->deliveries($this->manager())[0]['attempts']);self::assertCount(0,$transport->events);$this->clock->advance('+8 hours');self::assertSame(1,$pump->run($this->worker())['sent']);self::assertCount(1,$transport->events);
    }
    public function test_unknown_event_schema_is_visible_as_dead_letter_and_keeps_outbox_evidence():void
    {
        $transport=new RecordingNotificationTransport();$this->app->instance(NotificationTransport::class,$transport);$pump=$this->app->make(EventPump::class);$pump->subscribe($this->manager(),'created','notifications','subscribe');$task=$this->todo('Versioned');DB::table('tm_outbox')->where('task_id',$task['id'])->where('action','created')->update(['schema_version'=>2]);$pump->enqueue($this->worker());$delivery=$pump->deliveries($this->manager())[0];self::assertSame('dead',$delivery['state']);self::assertSame('event_schema_unsupported',$delivery['reason_code']);self::assertCount(0,$transport->events);self::assertSame(1,DB::table('tm_outbox')->where('schema_version',2)->count());
    }
}
