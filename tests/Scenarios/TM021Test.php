<?php

namespace Karnoweb\TaskManager\Tests\Scenarios;

use Karnoweb\TaskManager\Tests\WorkflowTestCase;

final class TM021Test extends WorkflowTestCase
{
    public function test_scenario(): void
    {
        $c = $this->manager();
        [$r,$p,$t] = $this->projectTask();
        $this->projects()->change($c, $p['project_key'], 1, 'archive', 'archive', []);
        $todo = $this->service()->create($c, 'Later', 'later');
        $this->error('project_archived', fn () => $this->projects()->previewTaskMove($c, $todo['public_key'], $p['project_key'], 'task', ['pending' => 'new']));
        self::assertNull($this->service()->get($c, $todo['public_key'])['project_id']);
        self::assertSame('archived', $this->projects()->get($c, $p['project_key'])['status']);
    }
}
