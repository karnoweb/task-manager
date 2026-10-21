<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\NotificationPreferences;
use Karnoweb\TaskManager\Scheduling\DailyQuietHours;
use Karnoweb\TaskManager\Scheduling\ReminderService;

final class ReminderCoreTest extends PhaseFiveTestCase
{
    private function reminders(): ReminderService
    {
        return $this->app->make(ReminderService::class);
    }

    private function worker(): ExecutionContext
    {
        $base = $this->manager();

        return new ExecutionContext($base->scope, $base->actor, 'worker', true, $base->capabilities);
    }

    public function test_relative_reminder_queues_once_and_due_changes_invalidate_old_generation(): void
    {
        $task = $this->todo('due');
        $task = $this->dates()->change($this->manager(), $task['public_key'], 1, null, null, '2026-10-20 01:00:00', 'UTC', false, 'reject', 'dates');
        $reminder = $this->reminders()->create($this->manager(), $task['public_key'], 'due', -600, null, 'in_app', 'reminder');
        self::assertSame('2026-10-20 00:50:00.000000', $reminder['scheduled_at']);
        $task = $this->dates()->change($this->manager(), $task['public_key'], $task['version'], null, null, '2026-10-20 02:00:00', 'UTC', false, 'reject', 'reschedule');
        $this->clock->advance('+1 hour');
        self::assertSame(0, $this->reminders()->dispatch($this->worker())['queued']);
        $this->clock->advance('+50 minutes');
        self::assertSame(1, $this->reminders()->dispatch($this->worker())['queued']);
        self::assertSame(0, $this->reminders()->dispatch($this->worker())['queued']);
        self::assertSame(1, DB::table('tm_reminder_deliveries')->count());
        self::assertSame(2, DB::table('tm_reminder_deliveries')->value('generation'));
        self::assertSame(1, DB::table('tm_outbox')->where('action', 'reminder_due')->count());
        $this->service()->mutate($this->manager(), $task['public_key'], $task['version'], 'complete', 'complete');
        self::assertSame('cancelled', $this->reminders()->get($this->manager(), $reminder['reminder_key'])['state']);
    }

    public function test_quiet_hours_snooze_and_permission_revocation_never_deliver_stale_work(): void
    {
        $this->clock->time = new \DateTimeImmutable('2026-10-20T23:00:00Z');
        $this->app->instance(NotificationPreferences::class, new DailyQuietHours('UTC', '22:00', '07:00'));
        $task = $this->todo('quiet');
        $reminder = $this->reminders()->create($this->manager(), $task['public_key'], 'absolute', 0, $this->clock->now(), 'in_app', 'reminder');
        self::assertSame(1, $this->reminders()->dispatch($this->worker())['deferred']);
        self::assertSame('2026-10-21 07:00:00.000000', $this->reminders()->get($this->manager(), $reminder['reminder_key'])['scheduled_at']);
        $this->clock->advance('+8 hours');
        $reminder = $this->reminders()->snooze($this->manager(), $reminder['reminder_key'], 1, $this->clock->now()->modify('+1 hour'), 'snooze');
        self::assertSame(0, $this->reminders()->dispatch($this->worker())['queued']);
        $this->clock->advance('+1 hour');
        self::assertSame(1, $this->reminders()->dispatch($this->worker())['queued']);
        self::assertSame(1,DB::table('tm_reminder_deliveries')->count());
        $this->error('scheduler_context_invalid',fn () => $this->reminders()->dispatch($this->manager()));
    }
}
