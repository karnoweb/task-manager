<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Karnoweb\TaskManager\Tests\WorkflowTestCase;

final class TM026Test extends WorkflowTestCase
{
    public function test_scenario(): void
    {
        $c = $this->manager();
        [$r,$p,$t] = $this->projectTask();
        $this->projects()->change($c, $p['project_key'], 1, 'viewer', 'member', ['actor' => 'b', 'role' => 'viewer']);
        $b = $this->manager('b');
        self::assertSame($p['id'], $this->projects()->get($b, $p['project_key'])['id']);
        self::assertCount(1, $this->service()->query($b));
        $this->error('not_found', fn () => $this->service()->mutate($b, $t['public_key'], 2, 'edit', 'update', ['priority' => 'high']));
        $this->error('not_found', fn () => $this->projects()->change($b, $p['project_key'], 2, 'archive', 'archive', []));
    }
}
