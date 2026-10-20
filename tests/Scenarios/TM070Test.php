<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Planning\TemplateService;
use Karnoweb\TaskManager\Planning\ReportingService;
use Karnoweb\TaskManager\Planning\TimeTrackingService;

use Karnoweb\TaskManager\Tests\AgileTestCase;

final class TM070Test extends AgileTestCase
{
    public function test_scenario(): void
    {
        [$revision, $project, $epicA] = $this->projectTask(); $epicB = $this->inProject($project, 'epic-b', 'epic'); $story = $this->inProject($project, 'story', 'story'); $other = $this->inProject($project, 'other');
        $this->attach($story, $epicA, 'story-a'); $this->attach($other, $epicA, 'other-a'); $this->done($story, 'story'); self::assertSame(50.0, $this->hierarchy()->progress($this->manager(), $epicA['public_key'])['percent']);
        $this->attach($story, $epicB, 'story-b'); self::assertSame(0.0, $this->hierarchy()->progress($this->manager(), $epicA['public_key'])['percent']); self::assertSame(100.0, $this->hierarchy()->progress($this->manager(), $epicB['public_key'])['percent']);
        self::assertSame($epicB['id'], $this->service()->get($this->manager(), $story['public_key'])['parent_task_id']); self::assertSame(1, DB::table('tm_task_closure')->where('descendant_task_id', $story['id'])->where('depth', 1)->count());
    }

}
