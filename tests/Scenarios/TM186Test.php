<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Exceptions\DomainError;
use Karnoweb\TaskManager\Tests\HandoffTestCase;

final class TM186Test extends HandoffTestCase
{
    public function test_tm186(): void
    {
        [$origin] = $this->handoffFixture(false);
        $other = $this->manager(scope: 'two');
        $r = $this->workflows()->publish($other, 'sales', 0, 'other-workflow', $this->definition());
        $p = $this->pipelines()->create($other, 'Other', $r['id'], ['inbox' => ['state' => 'new'], 'review' => ['state' => 'review'], 'won' => ['state' => 'won']], 'other-pipeline');
        try {
            $this->handoffs()->delegate($this->manager(), $origin['instance_key'], 1, $p['pipeline_key'], $r['id'], 'cross', $this->returnPolicy(), 'cross-scope');
            self::fail();
        } catch (DomainError $e) {
            self::assertSame('not_found', $e->errorCode);
        }
        self::assertSame(0, DB::table('tm_handoffs')->count());
        self::assertSame(0, DB::table('tm_process_cases')->count());
        self::assertSame(0, DB::table('tm_case_subjects')->count());
        self::assertSame('active', $this->instances()->get($this->manager(), $origin['instance_key'])['lifecycle']);
    }
}
