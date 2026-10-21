<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Planning\TimeTrackingService;

final class PlanningSafetyTest extends CollaborationTestCase
{
    public function test_checklist_delete_restore_keeps_items_and_enforces_retention_window(): void
    {
        $task = $this->todo('task');
        $list = $this->checklists()->create($this->manager(), $task['public_key'], 'List', 'list');
        $item = $this->itemIn($list, 'item');
        $list = $this->checklists()->get($this->manager(), $list['public_key']);
        $list = $this->checklists()->change($this->manager(), $list['public_key'], $list['version'], 'Renamed', true, 'delete');
        self::assertSame(0, $this->checklists()->progress($this->manager(), $task['public_key'])['total']);
        $this->error('resource_deleted', fn () => $this->checklists()->items($this->manager(), $list['public_key']));
        $list = $this->checklists()->change($this->manager(), $list['public_key'], $list['version'], 'Restored', false, 'restore');
        self::assertSame(1, $this->checklists()->progress($this->manager(), $task['public_key'])['total']);
        $list = $this->checklists()->change($this->manager(), $list['public_key'], $list['version'], 'Deleted', true, 'delete-again');
        $this->clock->advance('+31 days');
        $this->error('restore_window_expired', fn () => $this->checklists()->change($this->manager(), $list['public_key'], $list['version'], 'Late', false, 'late'));
        self::assertSame(1, DB::table('tm_checklist_items')->count());
    }

    public function test_timer_owner_can_release_own_slot_after_task_access_is_revoked_without_reading_task(): void
    {
        $task = $this->todo('task');
        $task = $this->service()->share($this->manager(), $task['public_key'], 1, 'share', 'b', 'edit');
        $time = $this->app->make(TimeTrackingService::class);
        $timer = $time->start($this->manager('b'), $task['public_key'], 'timer');
        $this->service()->share($this->manager(), $task['public_key'], $task['version'], 'revoke', 'b', revoke: true);
        $this->error('not_found', fn () => $time->timer($this->manager('b'), $timer['timer_key']));
        $cancelled = $time->cancelOwn($this->manager('b'), $timer['timer_key'], 1, 'cancel');
        self::assertSame(['timer_key' => $timer['timer_key'], 'state' => 'cancelled'], $cancelled);
        self::assertSame(0, DB::table('tm_active_timers')->count());
        self::assertSame(0, DB::table('tm_task_time_entries')->count());
    }
}
