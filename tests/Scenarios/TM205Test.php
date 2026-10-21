<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Karnoweb\TaskManager\Tests\WorkflowTestCase;

final class TM205Test extends WorkflowTestCase
{
    public function test_scenario(): void
    {
        $c = $this->manager();
        $r = $this->sales();
        $this->host();
        $this->instances()->start($c, 'crm.lead', 'lead-1', $r['id'], 'start');
        $b = $this->manager('b');
        self::assertSame(0, $this->instances()->count($b));
        self::assertSame([], $this->instances()->facets($b));
        self::assertSame([], $this->instances()->query($b));
        self::assertSame([['state' => 'new', 'total' => 1]], $this->instances()->facets($c));
    }
}
