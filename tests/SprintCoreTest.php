<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;

final class SprintCoreTest extends AgileTestCase
{
    public function test_start_commits_points_and_daily_snapshot_does_not_rewrite_committed_estimates(): void
    {
        [$revision, $project, $a] = $this->projectTask(); $a = $this->points($a, '5'); $sprint = $this->sprints()->create($this->manager(), $project['project_key'], 'One', null, null, 'sprint');
        $sprint = $this->enrollTasks($sprint, [$a], 'enroll'); $sprint = $this->sprints()->start($this->manager(), $sprint['sprint_key'], $sprint['version'], 'start');
        self::assertSame(500, $this->sprints()->report($this->manager(), $sprint['sprint_key'])['committed']['points_minor']);
        $this->points($a, '13'); $this->clock->advance('+1 day'); $this->sprints()->snapshot($this->manager(), $sprint['sprint_key'], $sprint['version'], 'daily');
        $report = $this->sprints()->report($this->manager(), $sprint['sprint_key']); self::assertSame(500, $report['current']['points_minor']); self::assertSame('0.00', $report['scope_change_points']);
    }
    public function test_active_scope_additions_and_removals_are_separate_from_original_commitment(): void
    {
        [$revision, $project, $a] = $this->projectTask(); $a = $this->points($a, '3'); $b = $this->points($this->inProject($project, 'b'), '2');
        $sprint = $this->sprints()->create($this->manager(), $project['project_key'], 'One', null, null, 'sprint'); $sprint = $this->enrollTasks($sprint, [$a], 'enroll'); $sprint = $this->sprints()->start($this->manager(), $sprint['sprint_key'], $sprint['version'], 'start');
        $sprint = $this->enrollTasks($sprint, [$b], 'add-active'); self::assertSame('2.00', $this->sprints()->report($this->manager(), $sprint['sprint_key'])['scope_change_points']); $this->clock->advance('+1 day'); $this->sprints()->snapshot($this->manager(), $sprint['sprint_key'], $sprint['version'], 'daily');
        $report = $this->sprints()->report($this->manager(), $sprint['sprint_key']); self::assertSame(300, $report['committed']['points_minor']); self::assertSame('2.00', $report['scope_change_points']);
        $b = $this->service()->get($this->manager(), $b['public_key']); $this->sprints()->remove($this->manager(), $sprint['sprint_key'], $sprint['version'], $b['public_key'], $b['version'], 'remove');
        self::assertSame(500, $this->sprints()->report($this->manager(), $sprint['sprint_key'])['current']['points_minor']);
    }
    public function test_completion_carries_only_unfinished_tasks_and_keeps_the_original_membership_history(): void
    {
        [$revision, $project, $a] = $this->projectTask(); $a = $this->points($a, '3'); $b = $this->points($this->inProject($project, 'b'), '2');
        $one = $this->sprints()->create($this->manager(), $project['project_key'], 'One', null, null, 'one'); $two = $this->sprints()->create($this->manager(), $project['project_key'], 'Two', null, null, 'two');
        $one = $this->enrollTasks($one, [$a, $b], 'enroll'); $one = $this->sprints()->start($this->manager(), $one['sprint_key'], $one['version'], 'start'); $this->clock->advance('+1 hour'); $this->done($a, 'a');
        $closed = $this->sprints()->close($this->manager(), $one['sprint_key'], $one['version'], 'carry', $two['sprint_key'], 1, 'close');
        self::assertSame('completed', $closed['state']); self::assertSame('3.00', $this->sprints()->report($this->manager(), $one['sprint_key'])['velocity']);
        self::assertSame([$b['id']], DB::table('tm_sprint_memberships')->where('sprint_id', $two['id'])->pluck('task_id')->all());
        self::assertSame(2, DB::table('tm_sprint_tasks')->where('sprint_id', $one['id'])->whereNotNull('left_at')->count()); self::assertSame(0, DB::table('tm_active_sprint_tasks')->count());
    }
    public function test_velocity_counts_only_real_done_leaves_and_never_epic_plus_children(): void
    {
        [$revision, $project, $epic] = $this->projectTask(); $epic = $this->points($epic, '100'); $a = $this->points($this->inProject($project, 'a'), '3'); $b = $this->points($this->inProject($project, 'b'), '5'); $this->attach($a, $epic, 'a-epic'); $this->attach($b, $epic, 'b-epic');
        $sprint = $this->sprints()->create($this->manager(), $project['project_key'], 'Sprint', null, null, 'sprint'); $sprint = $this->enrollTasks($sprint, [$epic, $a, $b], 'enroll'); $sprint = $this->sprints()->start($this->manager(), $sprint['sprint_key'], $sprint['version'], 'start');
        self::assertSame(800, $this->sprints()->report($this->manager(), $sprint['sprint_key'])['committed']['points_minor']); $this->clock->advance('+1 hour'); $this->done($a, 'a'); $this->done($epic, 'epic');
        $this->sprints()->close($this->manager(), $sprint['sprint_key'], $sprint['version'], 'backlog', null, null, 'close'); self::assertSame('3.00', $this->sprints()->report($this->manager(), $sprint['sprint_key'])['velocity']);
    }
    public function test_historical_burndown_and_same_day_snapshot_deduplication_survive_later_task_changes(): void
    {
        [$revision, $project, $a] = $this->projectTask(); $a = $this->points($a, '5'); $sprint = $this->sprints()->create($this->manager(), $project['project_key'], 'Sprint', null, null, 'sprint'); $sprint = $this->enrollTasks($sprint, [$a], 'enroll'); $sprint = $this->sprints()->start($this->manager(), $sprint['sprint_key'], $sprint['version'], 'start');
        $this->clock->advance('+1 day'); $daily = $this->sprints()->snapshot($this->manager(), $sprint['sprint_key'], $sprint['version'], 'daily'); $again = $this->sprints()->snapshot($this->manager(), $sprint['sprint_key'], $sprint['version'], 'daily-again'); self::assertSame($daily['snapshot_key'], $again['snapshot_key']);
        $this->done($a, 'a'); self::assertSame(500, $this->sprints()->report($this->manager(), $sprint['sprint_key'])['current']['remaining_points_minor']);
        $this->clock->advance('+1 day'); $this->sprints()->snapshot($this->manager(), $sprint['sprint_key'], $sprint['version'], 'next-day'); $report = $this->sprints()->report($this->manager(), $sprint['sprint_key']); self::assertSame(0, $report['current']['remaining_points_minor']); self::assertSame(500, $report['history'][1]['remaining_points_minor']); self::assertSame(3, DB::table('tm_sprint_daily_snapshots')->count());
    }
    public function test_unknown_points_are_reported_as_missing_instead_of_zero_velocity(): void
    {
        [$revision, $project, $task] = $this->projectTask(); $sprint = $this->sprints()->create($this->manager(), $project['project_key'], 'Unknown', null, null, 'unknown');
        $sprint = $this->enrollTasks($sprint, [$task], 'enroll'); $sprint = $this->sprints()->start($this->manager(), $sprint['sprint_key'], $sprint['version'], 'start'); $this->done($task, 'task');
        $this->sprints()->close($this->manager(), $sprint['sprint_key'], $sprint['version'], 'backlog', null, null, 'close'); $report = $this->sprints()->report($this->manager(), $sprint['sprint_key']);
        self::assertNull($report['velocity']); self::assertSame(1, $report['current']['unknown_points']); self::assertSame(1, $report['current']['done_tasks']);
    }
    public function test_a_task_cannot_reserve_two_active_sprints_and_current_project_permissions_apply(): void
    {
        [$revision, $project, $task] = $this->projectTask(); $one = $this->sprints()->create($this->manager(), $project['project_key'], 'One', null, null, 'one'); $two = $this->sprints()->create($this->manager(), $project['project_key'], 'Two', null, null, 'two');
        $one = $this->enrollTasks($one, [$task], 'enroll-one'); $two = $this->enrollTasks($two, [$task], 'enroll-two'); $this->sprints()->start($this->manager(), $one['sprint_key'], $one['version'], 'start-one');
        $this->error('task_in_active_sprint', fn () => $this->sprints()->start($this->manager(), $two['sprint_key'], $two['version'], 'start-two'));
        self::assertSame('planned', $this->sprints()->get($this->manager(), $two['sprint_key'])['state']); self::assertSame(1, DB::table('tm_active_sprint_tasks')->count());
        $this->error('not_found', fn () => $this->sprints()->report($this->manager('b'), $one['sprint_key']));
    }
}
