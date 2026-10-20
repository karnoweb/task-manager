<?php

namespace Karnoweb\TaskManager\Tests;

use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Enterprise\ReportService;

final class EnterpriseSlaReportTest extends SlaTestCase
{
    protected function manager(string $actor='a',string $scope='one'):ExecutionContext{$base=parent::manager($actor,$scope);return new ExecutionContext($scope,$actor,'report-sla',capabilities:[...$base->capabilities,'report.view']);}
    public function test_stage_elapsed_pause_and_breach_metrics_are_as_of_and_permission_filtered():void
    {
        [$instance,$visit]=$this->instanceWithSla(['duration_seconds'=>60,'pause_reasons'=>['wait'],'max_pause_seconds'=>300]);$this->clock->advance('+20 seconds');$this->sla()->pause($this->manager(),$visit['id'],1,'pause','wait');$this->clock->advance('+30 seconds');$this->sla()->resume($this->manager(),$visit['id'],2,'resume');$this->clock->advance('+60 seconds');$this->sla()->run($this->manager());$reports=$this->app->make(ReportService::class);
        $now=$reports->read($this->manager())['metrics'];self::assertSame(110,$now['elapsed_dwell_seconds']);self::assertSame(30,$now['paused_seconds']);self::assertSame(80,$now['active_elapsed_dwell_seconds']);self::assertSame(1,$now['breached_visits']);
        $past=$reports->read($this->manager(),['as_of'=>'2026-10-20T00:00:10Z'])['metrics'];self::assertSame(10,$past['elapsed_dwell_seconds']);self::assertSame(0,$past['paused_seconds']);self::assertSame(0,$past['breached_visits']);self::assertSame(0,$reports->read($this->manager('b'))['metrics']['visit_count']);
    }
}
