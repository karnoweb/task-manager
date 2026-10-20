<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\NotificationPreferences;
use Karnoweb\TaskManager\Scheduling\DailyQuietHours;
use Karnoweb\TaskManager\Scheduling\ReminderService;
use Karnoweb\TaskManager\Tests\PhaseFiveTestCase;

final class TM094Test extends PhaseFiveTestCase
{
    public function test_scenario():void
    {
        $task=$this->todo('due'); $task=$this->dates()->change($this->manager(),$task['public_key'],1,null,null,'2026-10-20 01:00:00','UTC',false,'reject','dates');
        $reminder=$this->reminders()->create($this->manager(),$task['public_key'],'due',-600,null,'in_app','reminder'); self::assertSame('2026-10-20 00:50:00.000000',$reminder['scheduled_at']);
        $task=$this->dates()->change($this->manager(),$task['public_key'],$task['version'],null,null,'2026-10-20 02:00:00','UTC',false,'reject','reschedule');
        $this->clock->advance('+1 hour'); self::assertSame(0,$this->reminders()->dispatch($this->worker())['queued']);
        $this->clock->advance('+50 minutes'); self::assertSame(1,$this->reminders()->dispatch($this->worker())['queued']); self::assertSame(0,$this->reminders()->dispatch($this->worker())['queued']);
        self::assertSame(1,DB::table('tm_reminder_deliveries')->count()); self::assertSame(2,DB::table('tm_reminder_deliveries')->value('generation')); self::assertSame(1,DB::table('tm_outbox')->where('action','reminder_due')->count());
        $this->service()->mutate($this->manager(),$task['public_key'],$task['version'],'complete','complete'); self::assertSame('cancelled',$this->reminders()->get($this->manager(),$reminder['reminder_key'])['state']);
    }
    private function reminders(): ReminderService { return $this->app->make(ReminderService::class); }
    private function worker(): ExecutionContext { $base=$this->manager(); return new ExecutionContext($base->scope,$base->actor,'worker',true,$base->capabilities); }
}
