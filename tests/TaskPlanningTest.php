<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\TaskGraph\TaskPlanningService;

final class TaskPlanningTest extends PhaseFourTestCase
{
    private function planning(): TaskPlanningService { return $this->app->make(TaskPlanningService::class); }
    public function test_date_propagation_is_read_only_until_execute_and_retains_successor_duration(): void
    {
        $a = $this->todo('a'); $b = $this->todo('b'); $this->planning()->estimate($this->manager(), $b['public_key'], 1, 30, '3', 'estimate'); $this->link($a, $b);
        $before = DB::table('tm_outbox')->count();
        $preview = $this->planning()->previewSchedule($this->manager(), $a['public_key'], new \DateTimeImmutable('2026-10-20T08:00:00Z'), new \DateTimeImmutable('2026-10-20T09:00:00Z'));
        self::assertSame($before, DB::table('tm_outbox')->count()); self::assertSame(0, DB::table('tm_tasks')->whereNotNull('planned_start_at')->count());
        $bPreview = array_values(array_filter($preview['items'], fn ($item) => $item['task_key'] === $b['public_key']))[0]; self::assertSame('2026-10-20 09:30:00.000000', $bPreview['finish']);
        self::assertSame(2, $this->planning()->executeSchedule($this->manager(), $preview, 'apply')['changed']);
        self::assertSame('2026-10-20 09:00:00.000000', $this->service()->get($this->manager(), $b['public_key'])['planned_start_at']);
        $events = DB::table('tm_outbox')->count(); $this->planning()->executeSchedule($this->manager(), $preview, 'apply'); self::assertSame($events, DB::table('tm_outbox')->count());
    }
    public function test_changed_version_or_forged_proposal_is_rejected_without_partial_date_writes(): void
    {
        $a = $this->todo('a'); $preview = $this->planning()->previewSchedule($this->manager(), $a['public_key'], new \DateTimeImmutable('2026-10-20T08:00:00Z'), new \DateTimeImmutable('2026-10-20T09:00:00Z'));
        $forged = $preview; $forged['items'][0]['finish'] = '2026-10-21 09:00:00.000000';
        $this->error('preview_stale', fn () => $this->planning()->executeSchedule($this->manager(), $forged, 'forged'));
        $this->service()->mutate($this->manager(), $a['public_key'], 1, 'rename', 'update', ['title' => 'new']);
        $this->error('preview_stale', fn () => $this->planning()->executeSchedule($this->manager(), $preview, 'stale')); self::assertNull(DB::table('tm_tasks')->value('planned_start_at'));
    }
    public function test_successor_guard_does_not_treat_planned_predecessor_finish_as_actual_evidence(): void
    {
        $a = $this->todo('a'); $b = $this->todo('b'); $this->link($a, $b);
        $preview = $this->planning()->previewSchedule($this->manager(), $a['public_key'], new \DateTimeImmutable('2026-10-19T08:00:00Z'), new \DateTimeImmutable('2026-10-19T09:00:00Z')); $this->planning()->executeSchedule($this->manager(), $preview, 'plan');
        $b = $this->service()->get($this->manager(), $b['public_key']); $this->error('dependency_date_missing', fn () => $this->service()->mutate($this->manager(), $b['public_key'], $b['version'], 'complete', 'complete'));
    }
    public function test_critical_path_requires_evidence_then_calculates_real_elapsed_estimates_and_lag(): void
    {
        [$revision, $project, $a] = $this->projectTask(); $b = $this->inProject($project, 'b');
        self::assertSame('insufficient_data', $this->planning()->criticalPath($this->manager(), $project['project_key'])['state']);
        $this->planning()->estimate($this->manager(), $a['public_key'], $a['version'], 60, '3', 'a-estimate');
        $this->planning()->estimate($this->manager(), $b['public_key'], $b['version'], 30, '2', 'b-estimate'); $this->link($a, $b, 'FS', ['lag_minutes' => 10]);
        $result = $this->planning()->criticalPath($this->manager(), $project['project_key'], new \DateTimeImmutable('2026-10-20T08:00:00Z'));
        self::assertSame('calculated', $result['state']); self::assertEquals(100, $result['duration_minutes']); self::assertSame([$a['public_key'], $b['public_key']], $result['path']);
    }
    public function test_cross_project_dependency_requires_both_project_permissions(): void
    {
        [$revision, $project, $a] = $this->projectTask(); $other = $this->projects()->create($this->manager(), 'OTHER', 'Other', $revision['id'], 'other'); $b = $this->inProject($other, 'other-task');
        $this->projects()->change($this->manager(), $project['project_key'], 1, 'member-b', 'member', ['actor' => 'b', 'role' => 'member']);
        $this->error('not_found', fn () => $this->dependencies()->add($this->manager('b'), $a['public_key'], $a['version'], $b['public_key'], $b['version'], 'FS', [], 'limited'));
        $link = $this->link($a, $b); self::assertSame('FS', $link['type']); self::assertSame(1, DB::table('tm_task_dependencies')->count());
    }
}
