<?php

namespace Karnoweb\TaskManager\Tests;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Planning\TemplateService;
use Karnoweb\TaskManager\Planning\ReportingService;
use Karnoweb\TaskManager\Planning\TimeTrackingService;

final class PhaseFourAcceptanceTest extends AgileTestCase
{
    public function test_conflicting_dependency_commands_recheck_versions_and_cannot_create_reverse_cycle(): void
    {
        $a = $this->todo('a'); $b = $this->todo('b'); $this->link($a, $b, operation: 'first-client');
        $this->error('version_conflict', fn () => $this->dependencies()->add($this->manager(), $b['public_key'], 1, $a['public_key'], 1, 'FS', [], 'stale-client'));
        $this->error('dependency_cycle', fn () => $this->link($b, $a, operation: 'fresh-reverse'));
        self::assertSame(1, DB::table('tm_task_dependencies')->whereNull('deleted_at')->count()); self::assertSame(1, DB::table('tm_active_dependencies')->count());
    }
    public function test_conflicting_reparent_commands_reject_stale_versions_and_reverse_cycle(): void
    {
        $a = $this->todo('a'); $b = $this->todo('b'); $this->attach($a, $b, 'first-client');
        $this->error('version_conflict', fn () => $this->hierarchy()->reparent($this->manager(), $b['public_key'], 1, $a['public_key'], 1, 'stale-client'));
        $this->error('task_hierarchy_cycle', fn () => $this->attach($b, $a, 'fresh-reverse'));
        self::assertSame(3, DB::table('tm_task_closure')->count()); self::assertSame($b['id'], $this->service()->get($this->manager(), $a['public_key'])['parent_task_id']); self::assertNull($this->service()->get($this->manager(), $b['public_key'])['parent_task_id']);
    }
    public function test_reparenting_story_between_epics_updates_both_rollups_and_keeps_one_parent(): void
    {
        [$revision, $project, $epicA] = $this->projectTask(); $epicB = $this->inProject($project, 'epic-b', 'epic'); $story = $this->inProject($project, 'story', 'story'); $other = $this->inProject($project, 'other');
        $this->attach($story, $epicA, 'story-a'); $this->attach($other, $epicA, 'other-a'); $this->done($story, 'story'); self::assertSame(50.0, $this->hierarchy()->progress($this->manager(), $epicA['public_key'])['percent']);
        $this->attach($story, $epicB, 'story-b'); self::assertSame(0.0, $this->hierarchy()->progress($this->manager(), $epicA['public_key'])['percent']); self::assertSame(100.0, $this->hierarchy()->progress($this->manager(), $epicB['public_key'])['percent']);
        self::assertSame($epicB['id'], $this->service()->get($this->manager(), $story['public_key'])['parent_task_id']); self::assertSame(1, DB::table('tm_task_closure')->where('descendant_task_id', $story['id'])->where('depth', 1)->count());
    }
    public function test_hundred_task_project_depth_eight_bug_workflow_dependencies_sprint_and_truth_rollups(): void
    {
        $revision = $this->sales(); $bug = $this->workflows()->publish($this->manager(), 'bug', 0, 'bug', $this->definition()); $items = [];
        for ($i = 0; $i < 100; ++$i) { $items[] = ['alias' => 't'.$i, 'title' => 'Task '.$i, 'type' => $i === 0 ? 'epic' : ($i % 2 === 0 ? 'story' : 'bug'), 'parent' => $i === 0 ? null : ($i <= 8 ? 't'.($i - 1) : 't0'), 'story_points' => $i === 0 ? '100' : '1']; }
        $templates = $this->app->make(TemplateService::class); $template = $templates->publish($this->manager(), null, 0, 'Hundred', ['workflow_revision_id' => $revision['id'], 'type_workflows' => ['bug' => $bug['id']], 'tasks' => $items, 'max_depth' => 8], 'template');
        $created = $templates->instantiate($this->manager(), $template['template_key'], 1, 'BIG', 'Hundred', 'instantiate'); $project = $created['project']; $tasks = [];
        foreach ($created['tasks'] as $alias => $key) { $tasks[$alias] = $this->service()->get($this->manager(), $key); }
        self::assertSame(100, DB::table('tm_tasks')->where('project_id', $project['id'])->count()); self::assertSame(8, (int) DB::table('tm_task_closure')->max('depth'));
        self::assertSame($bug['id'], DB::table('tm_workflow_instances')->where('task_id', $tasks['t9']['id'])->value('workflow_revision_id'));
        $this->link($tasks['t8'], $tasks['t9']); $this->error('dependency_date_missing', fn () => $this->step($tasks['t9'], 'review', 'blocked'));
        $sprint = $this->sprints()->create($this->manager(), $project['project_key'], 'Sprint', null, null, 'sprint'); $sprint = $this->enrollTasks($sprint, array_values($tasks), 'enroll'); $sprint = $this->sprints()->start($this->manager(), $sprint['sprint_key'], $sprint['version'], 'start');
        self::assertSame(9200, $this->sprints()->report($this->manager(), $sprint['sprint_key'])['committed']['points_minor']);
        $this->done($tasks['t8'], 'eight'); $this->done($tasks['t9'], 'nine'); $this->done($tasks['t0'], 'epic');
        $this->app->make(TimeTrackingService::class)->log($this->manager(), $tasks['t8']['public_key'], new \DateTimeImmutable('2026-10-19T22:00:00Z'), new \DateTimeImmutable('2026-10-19T22:10:00Z'), null, 'time');
        $reports = $this->app->make(ReportingService::class); $totals = $reports->totals($this->manager(), $project['project_key']); self::assertSame(92, $totals['leaf_tasks']); self::assertSame('92.00', $totals['points']); self::assertSame('2.00', $totals['done_points']); self::assertSame(600, $reports->subtreeTime($this->manager(), $tasks['t0']['public_key'])['spent_seconds']);
        $this->sprints()->close($this->manager(), $sprint['sprint_key'], $sprint['version'], 'backlog', null, null, 'close'); self::assertSame('2.00', $this->sprints()->report($this->manager(), $sprint['sprint_key'])['velocity']);
        self::assertSame(100, $this->hierarchy()->tree($this->manager(), $tasks['t0']['public_key'], 250)['total']);
    }
}
