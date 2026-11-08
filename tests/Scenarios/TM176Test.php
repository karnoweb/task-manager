<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Karnoweb\TaskManager\Tests\HandoffTestCase;

final class TM176Test extends HandoffTestCase
{
    public function test_tm176(): void
    {
        [$h, $origin, $destination, $pipeline] = $this->delegateFixture(false);
                $this->error('handoff_cycle_or_depth', fn () => $this->handoffs()->delegate($this->manager(), $destination['instance_key'], 1, $pipeline['pipeline_key'], $origin['workflow_revision_id'], 'cycle', $this->returnPolicy(), 'cycle'));
    }
}
