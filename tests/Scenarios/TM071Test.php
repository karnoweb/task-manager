<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Planning\PositionService;
use Karnoweb\TaskManager\Planning\MarkerService;
use Karnoweb\TaskManager\Planning\ReportingService;
use Karnoweb\TaskManager\Planning\TimeTrackingService;

use Karnoweb\TaskManager\Tests\AgileTestCase;

final class TM071Test extends AgileTestCase
{
    public function test_scenario(): void
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

}
