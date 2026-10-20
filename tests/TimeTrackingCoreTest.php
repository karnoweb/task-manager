<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Planning\TimeTrackingService;
use Karnoweb\TaskManager\TaskGraph\TaskPlanningService;

final class TimeTrackingCoreTest extends PhaseFourTestCase
{
    private function time(): TimeTrackingService { return $this->app->make(TimeTrackingService::class); }
    public function test_manual_time_and_estimate_calculate_spent_and_remaining_from_truth(): void
    {
        $task = $this->todo('task'); $this->app->make(TaskPlanningService::class)->estimate($this->manager(), $task['public_key'], 1, 60, '3', 'estimate');
        $entry = $this->time()->log($this->manager(), $task['public_key'], new \DateTimeImmutable('2026-10-19T23:00:00Z'), new \DateTimeImmutable('2026-10-19T23:15:00Z'), 'PRIVATE note', 'log');
        self::assertSame(900, $entry['duration_seconds']); $stats = $this->time()->stats($this->manager(), $task['public_key']); self::assertSame(900, $stats['spent_seconds']); self::assertSame(2700, $stats['remaining_seconds']);
        self::assertStringNotContainsString('PRIVATE note', json_encode(DB::table('tm_outbox')->get()->all()));
    }
    public function test_half_open_overlap_rejects_or_warns_without_disclosing_other_tasks(): void
    {
        $a = $this->todo('a'); $b = $this->todo('b'); $start = new \DateTimeImmutable('2026-10-19T22:00:00Z'); $end = $start->modify('+1 hour');
        $this->time()->log($this->manager(), $a['public_key'], $start, $end, null, 'first');
        $this->error('time_overlap', fn () => $this->time()->log($this->manager(), $b['public_key'], $start->modify('+30 minutes'), $end->modify('+30 minutes'), null, 'overlap'));
        $warn = $this->time()->log($this->manager(), $b['public_key'], $start->modify('+30 minutes'), $end->modify('+30 minutes'), null, 'warn', 'warning'); self::assertTrue((bool) $warn['overlap_warning']);
        $adjacent = $this->time()->log($this->manager(), $a['public_key'], $end->modify('+30 minutes'), $end->modify('+45 minutes'), null, 'adjacent'); self::assertFalse((bool) $adjacent['overlap_warning']);
    }
    public function test_one_active_timer_per_scope_actor_and_retry_safe_start_stop(): void
    {
        $a = $this->todo('a'); $b = $this->todo('b'); $timer = $this->time()->start($this->manager(), $a['public_key'], 'start');
        self::assertSame($timer['timer_key'], $this->time()->start($this->manager(), $a['public_key'], 'start')['timer_key']);
        $this->error('timer_active', fn () => $this->time()->start($this->manager(), $b['public_key'], 'second'));
        $this->clock->advance('+15 minutes'); $entry = $this->time()->stop($this->manager(), $timer['timer_key'], 1, 'stop');
        self::assertSame(900, $entry['duration_seconds']); self::assertSame($entry['public_key'], $this->time()->stop($this->manager(), $timer['timer_key'], 1, 'stop')['public_key']);
        self::assertSame(1, DB::table('tm_task_time_entries')->count()); self::assertSame(0, DB::table('tm_active_timers')->count());
        self::assertSame('active', $this->time()->start($this->manager(), $b['public_key'], 'next')['state']);
    }
    public function test_projection_revision_detects_in_place_entry_edits_and_rebuilds_without_double_count(): void
    {
        $task = $this->todo('task'); $start = new \DateTimeImmutable('2026-10-19T22:00:00Z'); $entry = $this->time()->log($this->manager(), $task['public_key'], $start, $start->modify('+10 minutes'), null, 'log');
        self::assertSame('projected', $this->time()->rebuild($this->manager(), $task['public_key'], 'rebuild')['state']);
        $this->time()->amend($this->manager(), $entry['public_key'], 1, $start, $start->modify('+20 minutes'), null, false, 'amend');
        self::assertSame('stale', $this->time()->stats($this->manager(), $task['public_key'], false)['state']); self::assertSame(1200, $this->time()->stats($this->manager(), $task['public_key'])['spent_seconds']);
        self::assertSame(1200, $this->time()->rebuild($this->manager(), $task['public_key'], 'rebuild-again')['spent_seconds']);
        self::assertSame(1, DB::table('tm_task_stats')->count());
    }
    public function test_revoked_or_foreign_actor_cannot_read_time_notes_or_stop_someone_elses_timer(): void
    {
        $task = $this->todo('task'); $timer = $this->time()->start($this->manager(), $task['public_key'], 'start');
        $this->error('not_found', fn () => $this->time()->stop($this->manager('b'), $timer['timer_key'], 1, 'stop-b'));
        $this->clock->advance('+1 minute'); $entry = $this->time()->stop($this->manager(), $timer['timer_key'], 1, 'stop');
        $this->error('not_found', fn () => $this->time()->entry($this->manager('b'), $entry['public_key']));
        $this->error('not_found', fn () => $this->time()->stats($this->manager('a', 'two'), $task['public_key']));
    }
    public function test_clock_rollback_keeps_the_timer_reservation_and_does_not_create_negative_time(): void
    {
        $task = $this->todo('task'); $timer = $this->time()->start($this->manager(), $task['public_key'], 'start'); $this->clock->advance('-1 minute');
        $this->error('clock_rollback', fn () => $this->time()->stop($this->manager(), $timer['timer_key'], 1, 'bad-clock'));
        self::assertSame(1, DB::table('tm_active_timers')->count()); self::assertSame(0, DB::table('tm_task_time_entries')->count());
    }
}
