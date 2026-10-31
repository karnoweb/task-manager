<?php

namespace Karnoweb\TaskManager\Tests\Scenarios\Complete;

use Karnoweb\TaskManager\Tests\WorkflowTestCase;

final class Scenario07PipelineTest extends WorkflowTestCase
{
    public function test_complete_scenario_07(): void
    {

        $r = $this->sales();
        $this->host();
        $p = $this->pipeline($r['id']);
        $q = $this->pipeline($r['id'], name: 'Contracts');
        $i = $this->instances()->start($this->manager(), 'crm.lead', 'lead-1', $r['id'], 'start', pipelineKey: $p['pipeline_key']);
        $i = $this->instances()->transition($this->manager(), $i['instance_key'], 1, 'review', 'review');
        $i = $this->instances()->transition($this->manager(), $i['instance_key'], 2, 'return', 'return');
        $visits = $this->instances()->visits($this->manager(), $i['instance_key']);
        self::assertCount(3, $visits);
        self::assertNotNull($visits[0]['exited_at']);
        self::assertNull($visits[2]['exited_at']);
        $preview = $this->instances()->previewMove($this->manager(), $i['instance_key'], $q['pipeline_key'], ['new' => 'review']);
        $moved = $this->instances()->moveToPipeline($this->manager(), $preview, 'move');
        self::assertSame('lead-1', $moved['subject_id']);
        self::assertSame($i['instance_key'], $moved['instance_key']);
        self::assertSame($q['id'], $moved['pipeline_id']);
        self::assertCount(4, $this->instances()->visits($this->manager(), $i['instance_key']));

    }
}
