<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Illuminate\Support\Facades\DB;
use Karnoweb\TaskManager\Tests\WorkflowTestCase;

final class TM010Test extends WorkflowTestCase
{
    public function test_scenario(): void
    {
        $c = $this->manager();
        [$r,$p,$t] = $this->projectTask();
        self::assertSame($p['id'], $t['project_id']);
        self::assertSame('new', $t['state']);
        self::assertSame('APP-1', $t['display_key']);
        self::assertSame(1, DB::table('tm_tasks')->count());
        self::assertSame($t['public_key'], $this->service()->resolveKey($c, 'TM-1')['public_key']);
    }
}
