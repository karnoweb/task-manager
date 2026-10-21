<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\WorkflowTestCase;

final class TM155Test extends WorkflowTestCase
{
    public function test_scenario(): void
    {
        $c = $this->manager();
        $r = $this->sales();
        $this->error('not_found', fn () => $this->instances()->start($c, 'crm.lead', 'missing', $r['id'], 'missing'));
        $this->host();
        $this->error('not_found', fn () => $this->instances()->start($this->manager('guest'), 'crm.lead', 'lead-1', $r['id'], 'denied'));
        self::assertSame(0, DB::table('tm_workflow_instances')->count());
        self::assertSame(0, $this->instances()->count($this->manager('guest')));
        self::assertSame([], $this->instances()->query($this->manager('guest')));
    }
}
