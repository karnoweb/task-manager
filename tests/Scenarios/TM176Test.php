<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Tests\HandoffTestCase;
use Karnoweb\TaskManager\Tests\Support\ProcessBarrier;

final class TM176Test extends HandoffTestCase
{
    public function test_tm176(): void
    {
        [$h, $origin, $destination, $pipeline] = $this->delegateFixture(false);
        $this->expectExceptionMessage('handoff_cycle_or_depth');
        $this->handoffs()->delegate($this->manager(), $destination['instance_key'], 1, $pipeline['pipeline_key'], $origin['workflow_revision_id'], 'cycle', $this->returnPolicy(), 'cycle');
    }
}

