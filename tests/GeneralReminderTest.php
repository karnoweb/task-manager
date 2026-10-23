<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Integration\ConsumerRegistry;
use Karnoweb\TaskManager\Integration\EventPump;
use Karnoweb\TaskManager\Scheduling\ReminderService;

final class GeneralReminderTest extends PhaseFiveTestCase
{
    private function reminders(): ReminderService
    {
        return $this->app->make(ReminderService::class);
    }

    public function test_personal_reminder_has_no_task_and_lifecycle_is_versioned_and_retry_safe(): void
    {
        $service = $this->reminders();
        $due = $this->clock->now()->modify('+1 hour');
        $row = $service->createGeneral($this->manager(), 'Call supplier', $due, 'Asia/Tehran', 'create');
        self::assertNull($row['task_id']);
        self::assertNull($row['subject_type']);
        self::assertSame(0, DB::table('tm_tasks')->count());
        self::assertSame($row, $service->createGeneral($this->manager(), 'Call supplier', $due, 'Asia/Tehran', 'create'));
        self::assertSame(1, $service->count($this->manager()));
        $done = $service->changeState($this->manager(), $row['reminder_key'], 1, 'complete', 'done');
        self::assertSame('completed', $done['state']);
        self::assertNotNull($done['completed_at']);
        self::assertSame(0, $service->count($this->manager()));
        $this->clock->advance('+2 hours');
        $active = $service->changeState($this->manager(), $row['reminder_key'], 2, 'reopen', 'reopen');
        self::assertSame('pending', $active['state']);
        self::assertLessThan($this->clock->now(), new \DateTimeImmutable($active['scheduled_at'], new \DateTimeZone('UTC')));
        $this->error('version_conflict', fn () => $service->changeState($this->manager(), $row['reminder_key'], 1, 'delete', 'stale'));
        $service->changeState($this->manager(), $row['reminder_key'], 3, 'delete', 'delete');
        $this->error('not_found', fn () => $service->get($this->manager(), $row['reminder_key']));
    }

    public function test_due_outbox_is_once_and_deleted_reminder_cannot_send_a_stale_notification(): void
    {
        $service = $this->reminders();
        $row = $service->createGeneral($this->manager(), 'Private reminder text', $this->clock->now()->modify('+1 hour'), 'UTC', 'create');
        $this->clock->advance('+1 hour');
        $worker = new ExecutionContext('one', 'a', 'worker', true, $this->manager()->capabilities);
        $delivered = 0;
        $this->app->make(ConsumerRegistry::class)->register('reminder-fixture', function () use (&$delivered) {
            $delivered++;
        });
        $pump = $this->app->make(EventPump::class);
        $pump->subscribe($this->manager(), 'reminder_due', 'reminder-fixture', 'subscribe');
        self::assertSame(1, $service->dispatch($worker)['queued']);
        $pump->enqueue($worker);
        self::assertSame(0, $service->dispatch($worker)['queued']);
        self::assertSame(1, DB::table('tm_reminder_deliveries')->count());
        self::assertSame(1, DB::table('tm_outbox')->where('action', 'reminder_due')->count());
        $service->changeState($this->manager(), $row['reminder_key'], 1, 'delete', 'delete');
        self::assertSame(1, $pump->run($worker)['cancelled']);
        self::assertSame(0, $delivered);
        self::assertFalse(DB::table('tm_reminders')->where('state', 'sent')->exists());
        self::assertStringNotContainsString('Private reminder text', DB::table('tm_outbox')->pluck('payload')->implode(''));
    }

    public function test_personal_reminder_needs_no_task_capability_and_text_edit_does_not_resend_due_event(): void
    {
        $context = new ExecutionContext('one', 'a', 'personal', capabilities: ['reminder.manage']);
        $row = $this->reminders()->createGeneral($context, 'Original', $this->clock->now()->modify('+1 hour'), 'UTC', 'create');
        $this->clock->advance('+1 hour');
        $worker = new ExecutionContext('one', 'a', 'worker', true, ['reminder.manage']);
        self::assertSame(1, $this->reminders()->dispatch($worker)['queued']);
        $changed = $this->reminders()->updateGeneral($context, $row['reminder_key'], 1, ['body' => 'Corrected text'], 'edit');
        self::assertSame('sent', $changed['state']);
        self::assertSame(1, $changed['generation']);
        self::assertSame(0, $this->reminders()->dispatch($worker)['queued']);
        self::assertSame(1, DB::table('tm_outbox')->where('action', 'reminder_due')->count());
    }

    public function test_external_reminder_does_not_transition_or_complete_when_ticket_closes(): void
    {
        $revision = $this->sales();
        $this->host();
        $instance = $this->instances()->start($this->manager(), 'crm.lead', 'lead-1', $revision['id'], 'start');
        $service = $this->reminders();
        $row = $service->createGeneral($this->manager(), 'Review response', $this->clock->now()->modify('+1 hour'), 'UTC', 'reminder', 'crm.lead', 'lead-1');
        self::assertSame($instance, $this->instances()->get($this->manager(), $instance['instance_key']));
        $next = $this->instances()->transition($this->manager(), $instance['instance_key'], 1, 'review', 'review');
        $this->instances()->transition($this->manager(), $instance['instance_key'], $next['version'], 'approve', 'approve', ['reason_code' => 'approved']);
        self::assertSame('pending', $service->get($this->manager(), $row['reminder_key'])['state']);
        $this->clock->advance('+1 hour');
        self::assertSame(1, $service->dispatch(new ExecutionContext('one', 'a', 'worker', true, $this->manager()->capabilities))['queued']);
    }

    public function test_current_scope_subject_visibility_and_recipient_policy_fail_closed(): void
    {
        $service = $this->reminders();
        $due = $this->clock->now()->modify('+1 hour');
        $row = $service->createGeneral($this->manager(), 'Only mine', $due, 'UTC', 'create');
        $this->error('not_found', fn () => $service->get($this->manager('b'), $row['reminder_key']));
        self::assertSame(0, $service->count($this->manager('b')));
        self::assertSame(0, $service->count($this->manager('a', 'two')));
        $this->error('reminder_recipient_denied', fn () => $service->createGeneral($this->manager(), 'Other recipient', $due, 'UTC', 'other', recipient: 'b'));
        $this->host();
        $linked = $service->createGeneral($this->manager(), 'Linked', $due, 'UTC', 'linked', 'crm.lead', 'lead-1');
        DB::table('host_permissions')->where('subject_id', 'lead-1')->delete();
        $this->error('not_found', fn () => $service->get($this->manager(), $linked['reminder_key']));
    }

    public function test_invalid_past_jalali_digits_and_dst_are_validated_without_writes(): void
    {
        $service = $this->reminders();
        $this->error('reminder_in_past', fn () => $service->createGeneral($this->manager(), 'Old', $this->clock->now()->modify('-1 second'), 'UTC', 'old'));
        self::assertSame('2025-03-21 06:30:00', $service->parseTime('۱۴۰۴-۰۱-۰۱ ۱۰:۰۰:۰۰', 'Asia/Tehran', 'jalali')->format('Y-m-d H:i:s'));
        self::assertSame('2025-03-21 06:30:00', $service->parseTime('١٤٠٤-٠١-٠١ ١٠:٠٠:٠٠', 'Asia/Tehran', 'jalali')->format('Y-m-d H:i:s'));
        $this->error('jalali_date_invalid', fn () => $service->parseTime('1404-12-30 10:00:00', 'UTC', 'jalali'));
        $this->error('local_time_nonexistent', fn () => $service->parseTime('2026-03-08 02:30:00', 'America/New_York'));
        self::assertSame(0, DB::table('tm_reminders')->count());
    }
}
