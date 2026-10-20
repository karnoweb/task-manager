<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Planning\TemplateService;
use Karnoweb\TaskManager\Planning\ReportingService;
use Karnoweb\TaskManager\Planning\TimeTrackingService;

use Karnoweb\TaskManager\Tests\AgileTestCase;

final class TM102Test extends AgileTestCase
{
    public function test_scenario(): void
    {
        $a = $this->todo('a'); $b = $this->todo('b'); $this->attach($a, $b, 'first-client');
        $this->error('version_conflict', fn () => $this->hierarchy()->reparent($this->manager(), $b['public_key'], 1, $a['public_key'], 1, 'stale-client'));
        $this->error('task_hierarchy_cycle', fn () => $this->attach($b, $a, 'fresh-reverse'));
        self::assertSame(3, DB::table('tm_task_closure')->count()); self::assertSame($b['id'], $this->service()->get($this->manager(), $a['public_key'])['parent_task_id']); self::assertNull($this->service()->get($this->manager(), $b['public_key'])['parent_task_id']);
    }

}
