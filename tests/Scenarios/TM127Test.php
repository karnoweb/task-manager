<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Karnoweb\TaskManager\Tests\WorkflowTestCase;

final class TM127Test extends WorkflowTestCase
{
    public function test_scenario(): void
    {
        $c = $this->manager();
        [$r,$p,$t] = $this->projectTask();
        $this->projects()->change($c, $p['project_key'], 1, 'join', 'member', ['actor' => 'b', 'role' => 'member']);
        self::assertSame(1, $this->service()->count($this->manager('b')));
        $this->projects()->change($c, $p['project_key'], 2, 'viewer', 'member', ['actor' => 'b', 'role' => 'viewer']);
        $this->error('not_found', fn () => $this->service()->mutate($this->manager('b'), $t['public_key'], 2, 'edit', 'update', ['title' => 'no']));
        $this->projects()->change($c, $p['project_key'], 3, 'leave', 'member', ['actor' => 'b', 'role' => null]);
        self::assertSame([], $this->service()->query($this->manager('b')));
        self::assertSame(0, $this->service()->count($this->manager('b')));
    }
}
