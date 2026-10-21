<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Enterprise\PresetService;

final class RecurrenceHistoryTest extends PhaseFiveTestCase
{
    public function test_one_occurrence_exception_and_whole_rule_change_preserve_completed_history(): void
    {
        $task = $this->todo('master');
        $series = $this->recurrence()->create($this->manager(), $task['public_key'], 1, 'jalali', '2026-10-21 09:00:00', 'Asia/Tehran', ['frequency' => 'daily', 'count' => 3], 'series');
        $from = new \DateTimeImmutable('2026-10-21');
        $until = $from->modify('+5 days');
        $created = $this->recurrence()->generate($this->manager(), $series['series_key'], 1, $from, $until, 'generate');
        $first = $this->service()->get($this->manager(), $created['created'][0]);
        $this->service()->mutate($this->manager(), $first['public_key'], $first['version'], 'complete', 'complete');
        $row = DB::table('tm_recurrence_occurrences')->where('task_id', $first['id'])->first();
        $second = $this->service()->get($this->manager(), $created['created'][1]);
        $secondRow = DB::table('tm_recurrence_occurrences')->where('task_id', $second['id'])->first();
        $this->recurrence()->exception($this->manager(), $series['series_key'], 1, $secondRow->occurrence_key, $second['version'], new \DateTimeImmutable('2026-10-22T08:00:00Z'), false, 'exception');
        self::assertSame(1, DB::table('tm_recurrence_exceptions')->count());
        self::assertSame(3, $this->recurrence()->get($this->manager(), $series['series_key'])['rule']['count']);
        $this->recurrence()->changeRule($this->manager(), $series['series_key'], 2, ['frequency' => 'daily', 'count' => 5], 'rule-change');
        self::assertSame('done', $this->service()->get($this->manager(), $first['public_key'])['state']);
        self::assertNull($this->service()->get($this->manager(), $first['public_key'])['deleted_at']);
        self::assertEquals((array) $row, (array) DB::table('tm_recurrence_occurrences')->where('task_id', $first['id'])->first());
        $this->recurrence()->generate($this->manager(), $series['series_key'], 3, $from, $until, 'regenerate');
        self::assertSame(5, DB::table('tm_recurrence_occurrences')->count());
        self::assertSame(5, DB::table('tm_recurrence_occurrences')->where('state', 'active')->count());
        $this->recurrence()->changeRule($this->manager(), $series['series_key'], 3, ['frequency' => 'daily', 'count' => 5], 'stop', true);
        self::assertSame([], $this->recurrence()->generate($this->manager(), $series['series_key'], 4, $from, $until, 'stopped')['created']);
    }

    public function test_after_completion_materializes_only_one_followup_after_real_completion(): void
    {
        $task = $this->todo('master');
        $series = $this->recurrence()->create($this->manager(), $task['public_key'], 1, 'gregorian', '2026-10-20 09:00:00', 'UTC', ['frequency' => 'daily', 'after_completion' => true], 'series');
        $from = new \DateTimeImmutable('2026-10-20');
        $until = $from->modify('+10 days');
        self::assertSame([], $this->recurrence()->generate($this->manager(), $series['series_key'], 1, $from, $until, 'before')['created']);
        $this->service()->mutate($this->manager(), $task['public_key'], 1, 'complete', 'complete');
        $result = $this->recurrence()->generate($this->manager(), $series['series_key'], 1, $from, $until, 'after');
        self::assertCount(1, $result['created']);
        self::assertSame([], $this->recurrence()->generate($this->manager(), $series['series_key'], 1, $from, $until, 'still-pending')['created']);
        $next = $this->service()->get($this->manager(), $result['created'][0]);
        $this->service()->mutate($this->manager(), $next['public_key'], $next['version'], 'complete-next', 'complete');
        self::assertCount(1, $this->recurrence()->generate($this->manager(), $series['series_key'], 1, $from, $until, 'next')['created']);
    }

    public function test_preset_copies_safe_checklist_defaults_once_and_rejects_foreign_members(): void
    {
        $presets = $this->app->make(PresetService::class);
        $preset = $presets->create($this->manager(), 'Default', ['title' => 'Todo', 'checklists' => [['title' => 'Checks', 'items' => ['One', 'Two']]]], 'preset');
        $task = $presets->instantiate($this->manager(), $preset['preset_key'], 'instantiate');
        self::assertSame($task, $presets->instantiate($this->manager(), $preset['preset_key'], 'instantiate'));
        self::assertSame(2, DB::table('tm_checklist_items')->count());
        self::assertSame(0,DB::table('tm_checklist_items')->whereNotNull('assignee_ref')->count());
        $this->error('preset_invalid',fn () => $presets->create($this->manager(),'Bad',['title' => 'Bad', 'members' => ['other']],'bad'));
        $this->error('not_found',fn () => $presets->get($this->manager('b'),$preset['preset_key']));
    }
}
