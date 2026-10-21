<?php

namespace Karnoweb\TaskManager\Tests;

use Karnoweb\TaskManager\Context\ExecutionContext;
use Karnoweb\TaskManager\Contracts\Clock;
use Karnoweb\TaskManager\SLA\CalendarRegistry;
use Karnoweb\TaskManager\SLA\SlaService;
use Karnoweb\TaskManager\SLA\StagePolicyService;
use Karnoweb\TaskManager\SLA\WeeklyWorkingCalendar;
use Karnoweb\TaskManager\Tests\Support\FrozenClock;

abstract class SlaTestCase extends WorkflowTestCase
{
    protected FrozenClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new FrozenClock;
        $this->app->instance(Clock::class, $this->clock);
        $shifts = [];
        foreach ([6, 7, 1, 2, 3, 4] as $day) {
            $shifts[$day] = [['09:00', '17:00']];
        }
        $this->app->instance(CalendarRegistry::class, new CalendarRegistry(['iran-v1' => new WeeklyWorkingCalendar('Asia/Tehran', $shifts)]));
    }

    protected function manager(string $actor = 'a', string $scope = 'one'): ExecutionContext
    {
        $base = parent::manager($actor, $scope);

        return new ExecutionContext($scope, $actor, 'sla-test', capabilities: [...$base->capabilities, 'sla.manage', 'sla.pause', 'sla.run']);
    }

    protected function sla(): SlaService
    {
        return $this->app->make(SlaService::class);
    }

    protected function policy(): StagePolicyService
    {
        return $this->app->make(StagePolicyService::class);
    }

    protected function instanceWithSla(array $policy): array
    {
        $r = $this->sales();
        $p = $this->pipeline($r['id']);
        $this->policy()->publishSla($this->manager(), $p['pipeline_key'], 'inbox', 1, 'policy', $policy);
        $this->host();
        $i = $this->instances()->start($this->manager(), 'crm.lead', 'lead-1', $r['id'], 'start', pipelineKey: $p['pipeline_key']);
        $v = $this->instances()->visits($this->manager(), $i['instance_key'])[0];

        return [$i, $v, $p];
    }
}
