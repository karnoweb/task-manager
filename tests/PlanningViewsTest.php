<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Planning\PositionService;
use Karnoweb\TaskManager\Planning\MarkerService;
use Karnoweb\TaskManager\Planning\ReportingService;
use Karnoweb\TaskManager\Planning\TimeTrackingService;

final class PlanningViewsTest extends AgileTestCase
{
    public function test_position_versions_reject_stale_clients_and_dense_ranks_rebalance_exactly(): void
    {
        [$revision, $project, $a] = $this->projectTask(); $b = $this->inProject($project, 'b'); $c = $this->inProject($project, 'c');
        $positions = $this->app->make(PositionService::class); $context = $positions->create($this->manager(), 'backlog', $project['project_key'], 'context');
        foreach ([$a, $b] as $index => $task) {
            $context = $positions->get($this->manager(), $context['context_key']); $task = $this->service()->get($this->manager(), $task['public_key']);
            $positions->place($this->manager(), $context['context_key'], $context['version'], $task['public_key'], $task['version'], null, null, 'place-'.$index);
        }
        $this->error('version_conflict', fn () => $positions->place($this->manager(), $context['context_key'], 1, $c['public_key'], $c['version'], null, null, 'stale'));
        DB::table('tm_task_positions')->where('task_id', $a['id'])->update(['rank_units' => 100]);
        DB::table('tm_task_positions')->where('task_id', $b['id'])->update(['rank_units' => 101]);
        $context = $positions->get($this->manager(), $context['context_key']); $c = $this->service()->get($this->manager(), $c['public_key']);
        $positions->place($this->manager(), $context['context_key'], $context['version'], $c['public_key'], $c['version'], $a['public_key'], $b['public_key'], 'middle');
        self::assertSame([$a['public_key'], $c['public_key'], $b['public_key']], array_column($positions->listing($this->manager(), $context['context_key'])['items'], 'task_key'));
        self::assertSame(3, DB::table('tm_task_positions')->distinct()->count('rank_units'));
        $this->error('not_found', fn () => $positions->listing($this->manager('b'), $context['context_key']));
    }
    public function test_departed_backlog_positions_are_pruned_without_removing_current_cards(): void
    {
        [$revision, $project, $a] = $this->projectTask(); $b = $this->inProject($project, 'b'); $positions = $this->app->make(PositionService::class);
        $context = $positions->create($this->manager(), 'backlog', $project['project_key'], 'positions'); $a = $this->service()->get($this->manager(), $a['public_key']);
        $placed = $positions->place($this->manager(), $context['context_key'], 1, $a['public_key'], $a['version'], null, null, 'place-a');
        $sprint = $this->sprints()->create($this->manager(), $project['project_key'], 'Sprint', null, null, 'sprint'); $sprint = $this->enrollTasks($sprint, [$a], 'enroll'); $this->sprints()->start($this->manager(), $sprint['sprint_key'], $sprint['version'], 'start');
        self::assertSame(0, $positions->listing($this->manager(), $context['context_key'])['total']);
        $b = $this->service()->get($this->manager(), $b['public_key']); $positions->place($this->manager(), $context['context_key'], $placed['version'], $b['public_key'], $b['version'], null, null, 'place-b');
        self::assertSame([$b['id']], DB::table('tm_task_positions')->where('context_id', $context['id'])->pluck('task_id')->all());
        self::assertSame([$b['public_key']], array_column($positions->listing($this->manager(), $context['context_key'])['items'], 'task_key'));
    }
    public function test_milestone_and_release_progress_is_independent_of_sprint_membership(): void
    {
        [$revision, $project, $task] = $this->projectTask(); $markers = $this->app->make(MarkerService::class);
        foreach (['milestone', 'release'] as $kind) {
            $marker = $markers->create($this->manager(), $project['project_key'], $kind, $kind, null, 'create-'.$kind);
            $task = $this->service()->get($this->manager(), $task['public_key']); $markers->link($this->manager(), $marker['marker_key'], 1, $task['public_key'], $task['version'], true, 'link-'.$kind);
            self::assertSame(0, $markers->progress($this->manager(), $marker['marker_key'])['done']); $keys[] = $marker['marker_key'];
        }
        $this->done($task, 'done'); foreach ($keys as $key) { self::assertSame(100.0, $markers->progress($this->manager(), $key)['percent']); }
        self::assertSame(0, DB::table('tm_sprint_tasks')->count());
    }
    public function test_reporting_counts_raw_time_once_and_reparenting_updates_leaf_totals(): void
    {
        [$revision, $project, $parent] = $this->projectTask(); $a = $this->points($this->inProject($project, 'a'), '3'); $b = $this->points($this->inProject($project, 'b'), '5');
        $this->attach($a, $parent, 'a-parent'); $this->attach($b, $parent, 'b-parent'); $reports = $this->app->make(ReportingService::class);
        $this->app->make(TimeTrackingService::class)->log($this->manager(), $a['public_key'], new \DateTimeImmutable('2026-10-19T22:00:00Z'), new \DateTimeImmutable('2026-10-19T22:10:00Z'), null, 'time');
        self::assertSame(600, $reports->subtreeTime($this->manager(), $parent['public_key'])['spent_seconds']);
        $totals = $reports->totals($this->manager(), $project['project_key']); self::assertSame(2, $totals['leaf_tasks']); self::assertSame('8.00', $totals['points']); self::assertSame(600, $totals['spent_seconds']);
        self::assertSame(0, $reports->totals($this->manager('b'))['total_tasks']);
        $this->clock->advance('+1 hour'); $this->step($a, 'review', 'review'); $this->clock->advance('+10 minutes'); $this->step($a, 'approve', 'approve');
        $flow = $reports->flow($this->manager(), $a['public_key']); self::assertSame(600, $flow['cycle_seconds']); self::assertSame(4200, $flow['lead_seconds']);
    }
}
