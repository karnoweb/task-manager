<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Tests\HandoffTestCase;
use Karnoweb\TaskManager\Tests\Support\ProcessBarrier;

final class TM184Test extends HandoffTestCase
{
    public function test_tm184(): void
    {
        [$h, $origin, $destination] = $this->delegateFixture(false);
        $stages = ['inbox' => ['state' => 'new'], 'review' => ['state' => 'review'], 'won' => ['state' => 'won']];
        $risk = $this->pipelines()->create($this->manager(), 'Risk', $origin['workflow_revision_id'], $stages, 'risk-pipeline');
        $second = $this->handoffs()->delegate($this->manager(), $destination['instance_key'], 1, $risk['pipeline_key'], $origin['workflow_revision_id'], 'risk', $this->returnPolicy(), 'risk-handoff');
        $riskKey = DB::table('tm_workflow_instances')->where('id', $second['destination_id'])->value('instance_key');
        $this->instances()->transition($this->manager(), $riskKey, 1, 'risk-accept', 'accept');
        self::assertSame('resolved', $this->handoffs()->settle($this->manager(), $second['handoff_key'], 1, 'risk-return')['status']);
        $this->instances()->transition($this->manager(), $destination['instance_key'], 3, 'contract-approve', 'approve', ['reason_code' => 'approved']);
        self::assertSame('resolved', $this->handoffs()->settle($this->manager(), $h['handoff_key'], 1, 'return')['status']);
        $caseKey = DB::table('tm_process_cases')->value('case_key');
        $timeline = $this->cases()->timeline($this->manager(), $caseKey);
        self::assertCount(3, array_unique(array_column($timeline, 'aggregate_key')));
        self::assertStringNotContainsString('PRIVATE-HOST-TEXT', json_encode($timeline));
        self::assertSame([], $this->cases()->timeline($this->manager('b'), $caseKey));
        self::assertSame(1, DB::table('tm_process_cases')->count()); self::assertSame(1, DB::table('tm_case_subjects')->count());
    }
}

