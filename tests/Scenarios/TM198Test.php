<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Karnoweb\TaskManager\Tests\SlaTestCase;

final class TM198Test extends SlaTestCase
{
    public function test_scenario(): void
    {
        $c = $this->manager();
        [$a,$v,$p] = $this->instanceWithSla(['duration_seconds' => 3600, 'reentry' => 'reset']);
        $q = $this->pipeline($a['workflow_revision_id'], name: 'Cumulative');
        $this->policy()->publishSla($c, $q['pipeline_key'], 'inbox', 1, 'cumulative', ['duration_seconds' => 3600, 'reentry' => 'cumulative']);
        $this->host('lead-2');
        $b = $this->instances()->start($c, 'crm.lead', 'lead-2', $a['workflow_revision_id'], 'second', pipelineKey: $q['pipeline_key']);
        $this->clock->advance('+20 minutes');
        $this->instances()->transition($c, $a['instance_key'], 1, 'review-a', 'review');
        $this->instances()->transition($c, $b['instance_key'], 1, 'review-b', 'review');
        $this->clock->advance('+10 minutes');
        $this->instances()->transition($c, $a['instance_key'], 2, 'return-a', 'return');
        $this->instances()->transition($c, $b['instance_key'], 2, 'return-b', 'return');
        $av = $this->instances()->visits($c, $a['instance_key']);
        $bv = $this->instances()->visits($c, $b['instance_key']);
        self::assertCount(3, $av);
        self::assertCount(3, $bv);
        self::assertSame(3600, $av[2]['remaining_seconds']);
        self::assertSame(2400, $bv[2]['remaining_seconds']);
        self::assertSame(1200,$av[0]['elapsed_seconds']);
        self::assertNotNull($av[0]['exited_at']);
    }
}
