<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Karnoweb\TaskManager\Tests\WorkflowTestCase;

final class TM163Test extends WorkflowTestCase
{
    public function test_scenario(): void
    {
        $c = $this->manager();
        $r = $this->sales();
        $p = $this->pipeline($r['id']);
        $q = $this->pipeline($r['id'], name: 'Tickets');
        $this->host('lead', 'crm.lead', actors: ['a', 'b']);
        $this->host('ticket', 'support.ticket');
        $this->instances()->start($c, 'crm.lead', 'lead', $r['id'], 'lead', pipelineKey: $p['pipeline_key']);
        $this->instances()->start($c, 'support.ticket', 'ticket', $r['id'], 'ticket', pipelineKey: $q['pipeline_key']);
        $all = $this->instances()->query($c);
        self::assertCount(2, $all);
        self::assertCount(2, array_unique(array_column($all, 'pipeline_id')));
        $b = $this->instances()->query($this->manager('b'));
        self::assertCount(1, $b);
        self::assertSame('crm.lead', $b[0]['subject_type']);
        self::assertSame($p['id'], $b[0]['pipeline_id']);
    }
}
