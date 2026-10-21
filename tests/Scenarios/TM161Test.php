<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\WorkflowTestCase;

final class TM161Test extends WorkflowTestCase
{
    public function test_scenario(): void
    {
        $c = $this->manager();
        $r = $this->sales();
        $before = DB::table('tm_outbox')->count();
        $this->error('stage_mapping_invalid', fn () => $this->pipelines()->create($c, 'Bad', $r['id'], ['bad' => ['state' => 'missing']], 'bad'));
        self::assertSame(0, DB::table('tm_pipelines')->count());
        self::assertSame(0, DB::table('tm_pipeline_stages')->count());
        self::assertSame(0, DB::table('tm_workflow_instances')->count());
        self::assertSame($before, DB::table('tm_outbox')->count());
    }
}
