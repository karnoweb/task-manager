<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Planning\PositionService;
use Karnoweb\TaskManager\Tests\AgileTestCase;

final class TM104Test extends AgileTestCase
{
    public function test_scenario(): void
    {
        [$revision, $project, $a] = $this->projectTask();
        $b = $this->inProject($project, 'b');
        $c = $this->inProject($project, 'c');
        $positions = $this->app->make(PositionService::class);
        $context = $positions->create($this->manager(), 'backlog', $project['project_key'], 'context');
        foreach ([$a, $b] as $index => $task) {
            $context = $positions->get($this->manager(), $context['context_key']);
            $task = $this->service()->get($this->manager(), $task['public_key']);
            $positions->place($this->manager(), $context['context_key'], $context['version'], $task['public_key'], $task['version'], null, null, 'place-'.$index);
        }
        $this->error('version_conflict', fn () => $positions->place($this->manager(), $context['context_key'], 1, $c['public_key'], $c['version'], null, null, 'stale'));
        DB::table('tm_task_positions')->where('task_id', $a['id'])->update(['rank_units' => 100]);
        DB::table('tm_task_positions')->where('task_id', $b['id'])->update(['rank_units' => 101]);
        $context = $positions->get($this->manager(), $context['context_key']);
        $c = $this->service()->get($this->manager(), $c['public_key']);
        $positions->place($this->manager(), $context['context_key'], $context['version'], $c['public_key'], $c['version'], $a['public_key'], $b['public_key'], 'middle');
        self::assertSame([$a['public_key'], $c['public_key'], $b['public_key']], array_column($positions->listing($this->manager(), $context['context_key'])['items'], 'task_key'));
        self::assertSame(3, DB::table('tm_task_positions')->distinct()->count('rank_units'));
        $this->error('not_found', fn () => $positions->listing($this->manager('b'), $context['context_key']));
    }
}
